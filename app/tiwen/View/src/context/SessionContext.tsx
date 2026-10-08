import React, { createContext, useContext, useState, useEffect, useRef, useCallback } from 'react';
import { SessionData, ConversationRound, ModelCardResponse, ModelMeta } from '../types';
import { api, streamSSE } from '../api/client';
import { fetchModelSetup, fetchAppConfig, localMetaOf } from '../api/models';
import { useAuth } from './AuthContext';
import { AttachmentData } from '../components/workbench/AttachmentField';

/** 提问附件(已由服务端解析为纯文本) */
export interface AskAttachment {
  name: string;
  content: string;
}

/** 订阅弹窗携带的真实额度(roundCreate 402 响应 data) */
export interface SubscribeQuota {
  plan?: string;
  used?: number;
  limit?: number;
  remaining?: number;
  tokensUsed?: number;
  tokensQuota?: number;
}

interface SessionContextType {
  sessions: SessionData[];
  currentSessionId: string;
  currentSession: SessionData | null;
  activeRoundIndex: number;
  isThinking: boolean;
  totalCheckedCount: number;
  toastMessage: string | null;
  showToast: (msg: string, duration?: number) => void;
  loadSession: (id: string) => void;
  createSession: (firstQuestion?: string, attachment?: AskAttachment | null) => Promise<string>;
  renameSession: (id: string, newTitle: string) => void;
  deleteSession: (id: string) => void;
  setActiveRoundIndex: (idx: number) => void;
  toggleCardCheck: (roundIndex: number, cardId: string) => void;
  toggleSelectAll: (roundIndex?: number) => void;
  isAllCardsChecked: (roundIndex?: number) => boolean;
  checkedInRoundCount: (roundIndex?: number) => number;
  selectableCardsCount: (roundIndex?: number) => number;
  deleteCard: (roundIndex: number, cardId: string) => void;
  retryCard: (roundIndex: number, cardId: string) => Promise<void>;
  adoptMergedContent: () => void;
  isSynthesizing: boolean;
  synthesizeContent: () => Promise<void>;
  updateEditorDraft: (content: string) => void;
  sendFollowUp: (question: string, attachment?: AskAttachment | null) => Promise<void>;
  webSearchEnabled: boolean;
  smartSearch: boolean;
  setSmartSearch: (v: boolean) => void;
  models: ModelMeta[];
  getModelMeta: (id: string, name?: string) => ModelMeta;
  selectedModelIds: string[];
  setSelectedModelIds: (ids: string[]) => void;
  loading: boolean;
  isSubscribeModalOpen: boolean;
  subscribeModalReason: string;
  subscribeModalQuota: SubscribeQuota | null;
  openSubscribeModal: (reason?: string, quota?: SubscribeQuota | null) => void;
  closeSubscribeModal: () => void;
}

const SELECTED_KEY = 'tiwen_selected_models';
const SMART_SEARCH_KEY = 'tiwen_smart_search';

const SessionContext = createContext<SessionContextType | undefined>(undefined);

export const SessionProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, consumeTokens, refreshUser, openAuthModal } = useAuth();

  const [sessions, setSessions] = useState<SessionData[]>([]);
  const [currentSessionId, setCurrentSessionId] = useState<string>('');
  const [isThinking, setIsThinking] = useState(false);
  const [toastMessage, setToastMessage] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [isSynthesizing, setIsSynthesizing] = useState(false);

  const [models, setModels] = useState<ModelMeta[]>([]);
  const [selectedModelIds, setSelectedModelIdsState] = useState<string[]>([]);
  const [webSearchEnabled, setWebSearchEnabled] = useState(false);
  const [smartSearch, setSmartSearchState] = useState<boolean>(
    () => localStorage.getItem(SMART_SEARCH_KEY) === '1'
  );
  const [isSubscribeModalOpen, setIsSubscribeModalOpen] = useState(false);
  const [subscribeModalReason, setSubscribeModalReason] = useState('');
  const [subscribeModalQuota, setSubscribeModalQuota] = useState<SubscribeQuota | null>(null);

  const openSubscribeModal = useCallback((reason = '', quota: SubscribeQuota | null = null) => {
    setSubscribeModalReason(reason);
    setSubscribeModalQuota(quota);
    setIsSubscribeModalOpen(true);
  }, []);

  const closeSubscribeModal = useCallback(() => {
    setIsSubscribeModalOpen(false);
    setSubscribeModalReason('');
    setSubscribeModalQuota(null);
  }, []);

  // 流式缓冲:cardKey → 累积正文,定时批量刷入 state
  const streamBuffers = useRef<Record<string, string>>({});
  const flushTimer = useRef<number | null>(null);
  const draftSaveTimer = useRef<number | null>(null);
  const toastTimer = useRef<number | null>(null);

  const showToast = useCallback((msg: string, duration = 2600) => {
    setToastMessage(msg);
    // 自动消隐:新提示重置计时,duration 为 0 时常驻不自动清除
    if (toastTimer.current !== null) window.clearTimeout(toastTimer.current);
    if (duration > 0) {
      toastTimer.current = window.setTimeout(() => setToastMessage(null), duration);
    }
  }, []);

  // ---------- 模型清单 ----------
  useEffect(() => {
    let cancelled = false;
    (async () => {
      const { models, defaultModels } = await fetchModelSetup();
      if (cancelled) return;
      setModels(models);
      const saved = JSON.parse(localStorage.getItem(SELECTED_KEY) || '[]');
      const valid = Array.isArray(saved) ? saved.filter((id: string) => models.some(m => m.id === id)) : [];
      setSelectedModelIdsState(valid.length ? valid : defaultModels);
    })();
    return () => { cancelled = true; };
  }, []);

  const setSelectedModelIds = useCallback((ids: string[]) => {
    setSelectedModelIdsState(ids);
    localStorage.setItem(SELECTED_KEY, JSON.stringify(ids));
  }, []);

  // 联网搜索勾选全站共享并落地本地,新提问页勾上后进入会话页/追问仍保持
  const setSmartSearch = useCallback((v: boolean) => {
    setSmartSearchState(v);
    localStorage.setItem(SMART_SEARCH_KEY, v ? '1' : '0');
  }, []);

  useEffect(() => {
    let cancelled = false;
    fetchAppConfig().then(cfg => {
      if (cancelled) return;
      const enabled = !!cfg?.web_search_enabled;
      setWebSearchEnabled(enabled);
      // 后台未开启(或密钥未配置)时强制回落,避免提交一个服务端会忽略的勾选
      if (!enabled) setSmartSearchState(false);
    });
    return () => { cancelled = true; };
  }, []);

  const getModelMeta = useCallback((id: string, name?: string): ModelMeta => {
    return models.find(m => m.id === id) || localMetaOf(id, name);
  }, [models]);

  // ---------- 会话列表加载 ----------
  useEffect(() => {
    if (!user) {
      setSessions([]);
      setCurrentSessionId('');
      return;
    }
    let cancelled = false;
    (async () => {
      setLoading(true);
      const res = await api<SessionData[]>('/tiwen/sessions');
      if (cancelled) return;
      setLoading(false);
      if (res.ok && Array.isArray(res.data)) {
        setSessions(res.data);
        setCurrentSessionId(prev => (res.data!.some(s => s.id === prev) ? prev : (res.data![0]?.id || '')));
      }
    })();
    return () => { cancelled = true; };
  }, [user?.id]);

  const currentSession = sessions.find(s => s.id === currentSessionId) || null;
  const activeRoundIndex = currentSession?.activeRoundIndex ?? 0;

  const totalCheckedCount = currentSession
    ? currentSession.rounds.reduce(
        (total, r) => total + r.models.filter(m => m.checked).length,
        0
      )
    : 0;

  const loadSession = (id: string) => {
    if (sessions.some(s => s.id === id)) {
      setCurrentSessionId(id);
    }
  };

  // ---------- 流式缓冲批量刷新 ----------
  const flushStreams = useCallback(() => {
    flushTimer.current = null;
    const buffers = streamBuffers.current;
    if (Object.keys(buffers).length === 0) return;
    setSessions(prev => prev.map(s => ({
      ...s,
      rounds: s.rounds.map(r => ({
        ...r,
        models: r.models.map(c => (buffers[c.id] !== undefined ? { ...c, body: buffers[c.id] } : c)),
      })),
    })));
  }, []);

  const queueStreamFlush = useCallback(() => {
    if (flushTimer.current !== null) return;
    flushTimer.current = window.setTimeout(flushStreams, 90);
  }, [flushStreams]);

  const updateCard = useCallback((sessionId: number, roundKey: string, modelId: string, patch: Partial<ModelCardResponse>, appendBody?: string) => {
    setSessions(prev => prev.map(s => {
      if (s.sessionId !== sessionId) return s;
      return {
        ...s,
        rounds: s.rounds.map(r => {
          if (r.id !== roundKey) return r;
          return {
            ...r,
            models: r.models.map(c => {
              if (c.modelKey !== modelId) return c;
              if (appendBody !== undefined) {
                streamBuffers.current[c.id] = (streamBuffers.current[c.id] || '') + appendBody;
                queueStreamFlush();
                return c;
              }
              return { ...c, ...patch };
            }),
          };
        }),
      };
    }));
  }, [queueStreamFlush]);

  // ---------- 提问主流程 ----------
  const ask = useCallback(async (question: string, sessionId: number, attachment?: AskAttachment | null) => {
    const modelIds = selectedModelIds.length ? selectedModelIds : models.slice(0, 3).map(m => m.id);
    if (modelIds.length === 0) {
      showToast('模型清单加载中,请稍后重试');
      return;
    }

    // 创建轮次(服务端校验配额:免费版按并发模型数计,4 模型一次 = 4 次);附件文本随轮次暂存服务端
    const roundRes = await api<{ roundId: number; roundIndex: number }>('/tiwen/roundCreate', {
      method: 'POST',
      json: {
        sessionId,
        question,
        models: modelIds,
        attachmentName: attachment?.name ?? '',
        attachmentContent: attachment?.content ?? '',
        webSearch: smartSearch,
      },
    });
    if (!roundRes.ok || !roundRes.data) {
      if (roundRes.code === 402) {
        // 配额用尽: 弹出订阅升级弹窗,并把服务端返回的真实额度一起带入
        openSubscribeModal(
          roundRes.message || '今日免费提问额度已用尽，可减少并发模型或升级 Pro 套餐解锁',
          (roundRes.data as SubscribeQuota) || null
        );
        return;
      }
      showToast(roundRes.message || '提问失败');
      return;
    }
    const { roundId, roundIndex } = roundRes.data;
    const roundKey = `round_${roundId}`;
    const startedAt = Date.now();

    const initialRound: ConversationRound = {
      id: roundKey,
      roundId,
      roundIndex,
      question,
      tag: roundIndex === 0 ? '主问题' : '追问',
      pending: false,
      createdAt: Date.now(),
      models: modelIds.map(mid => {
        const meta = getModelMeta(mid);
        return {
          id: `stream-${roundId}-${mid}`,
          modelKey: mid,
          modelName: meta.name,
          meta: '实时推演中... · 0 tok',
          tokenCount: 0,
          durationSec: 0,
          checked: false,
          isStreaming: true,
          body: '',
        };
      }),
    };

    setSessions(prev => prev.map(s => {
      if (s.sessionId !== sessionId) return s;
      return {
        ...s,
        activeRoundIndex: roundIndex,
        totalTokens: s.totalTokens,
        updatedAt: Date.now(),
        rounds: [...s.rounds, initialRound],
      };
    }));

    setIsThinking(true);
    let totalTokens = 0;
    let failure = 0;

    await Promise.all(modelIds.map(mid =>
      streamSSE('/tiwen/chat', { sessionId, roundId, model: mid }, {
        onEvent: (event, data) => {
          if (event === 'delta' && data.content) {
            updateCard(sessionId, roundKey, mid, {}, String(data.content));
          } else if (event === 'usage') {
            const tokens = Number(data.tokens) || 0;
            const dur = Number(data.duration) || ((Date.now() - startedAt) / 1000);
            totalTokens += tokens;
            updateCard(sessionId, roundKey, mid, {
              tokenCount: tokens,
              durationSec: dur,
              meta: `${tokens.toLocaleString()} tok · ${dur.toFixed(1)}s`,
            });
          } else if (event === 'fail') {
            failure++;
            const rawMsg = data.message ? String(data.message) : '';
            const displayMsg = rawMsg
              ? (rawMsg.startsWith('输出异常') ? rawMsg : `输出异常（${rawMsg}），请稍后重试`)
              : '输出异常，请稍后重试';
            updateCard(sessionId, roundKey, mid, {
              isStreaming: false,
              failed: true,
              failMessage: displayMsg,
              meta: '输出异常',
            });
          } else if (event === 'done') {
            updateCard(sessionId, roundKey, mid, { isStreaming: false });
          }
        },
      })
    ));

    // 清空缓冲并收尾
    if (flushTimer.current !== null) {
      window.clearTimeout(flushTimer.current);
      flushTimer.current = null;
    }
    flushStreams();
    streamBuffers.current = {};
    setIsThinking(false);
    consumeTokens(totalTokens);
    refreshUser();
    showToast(failure === modelIds.length ? '⚠️ 本轮全部模型生成失败,请稍后重试' : failure > 0 ? `本轮完成,${failure} 个模型失败` : '✨ 实时并发推演完成');

    // 拉取服务端权威状态(卡片 id、token 统计)
    const detail = await api<SessionData>('/tiwen/sessionDetail', { method: 'POST', json: { sessionId } });
    if (detail.ok && detail.data) {
      setSessions(prev => prev.map(s => (s.sessionId === sessionId ? { ...detail.data!, activeRoundIndex: roundIndex } : s)));
    }
  }, [selectedModelIds, models, smartSearch, getModelMeta, showToast, openSubscribeModal, updateCard, flushStreams, consumeTokens, refreshUser]);

  const createSession = useCallback(async (firstQuestion = '', attachment?: AskAttachment | null): Promise<string> => {
    if (!user) {
      openAuthModal('登录后即可开启多模型研报工作台');
      return '';
    }
    const res = await api<{ id: number; routeId: string; title: string }>('/tiwen/sessionCreate', {
      method: 'POST',
      json: firstQuestion ? { title: firstQuestion.slice(0, 60) } : {},
    });
    if (!res.ok || !res.data) {
      showToast(res.message || '创建会话失败');
      return '';
    }
    const sid = res.data.id;
    // 路由 id 用服务端混淆后的非连续短 id,接口调用一律走 sessionId
    const newSession: SessionData = {
      id: res.data.routeId || `session_${sid}`,
      sessionId: sid,
      title: res.data.title,
      summary: res.data.title,
      totalTokens: 0,
      activeRoundIndex: 0,
      editorDraft: '',
      updatedAt: Date.now(),
      rounds: [],
    };
    setSessions(prev => [newSession, ...prev]);
    setCurrentSessionId(newSession.id);
    if (firstQuestion.trim()) {
      ask(firstQuestion.trim(), sid, attachment);
    }
    return newSession.id;
  }, [user, openAuthModal, showToast, ask]);

  const sendFollowUp = useCallback(async (question: string, attachment?: AskAttachment | null) => {
    if (!currentSession || !currentSession.sessionId || !question.trim()) return;
    if (!user) {
      openAuthModal('登录后即可继续多模型深度追问');
      return;
    }
    await ask(question.trim(), currentSession.sessionId, attachment);
  }, [currentSession, user, openAuthModal, ask]);

  // ---------- 会话管理 ----------
  const renameSession = (id: string, newTitle: string) => {
    const target = sessions.find(s => s.id === id);
    if (!target || !newTitle.trim()) return;
    setSessions(prev => prev.map(s => (s.id === id ? { ...s, title: newTitle, updatedAt: Date.now() } : s)));
    if (target.sessionId) {
      api('/tiwen/sessionRename', { method: 'POST', json: { sessionId: target.sessionId, title: newTitle } });
    }
  };

  const deleteSession = (id: string) => {
    const target = sessions.find(s => s.id === id);
    setSessions(prev => {
      const remaining = prev.filter(s => s.id !== id);
      if (currentSessionId === id) {
        setCurrentSessionId(remaining[0]?.id || '');
      }
      return remaining;
    });
    if (target?.sessionId) {
      api('/tiwen/sessionDelete', { method: 'POST', json: { sessionId: target.sessionId } });
    }
  };

  const setActiveRoundIndex = (idx: number) => {
    if (!currentSession) return;
    setSessions(prev =>
      prev.map(s => (s.id === currentSession.id ? { ...s, activeRoundIndex: idx } : s))
    );
    if (currentSession.sessionId) {
      api('/tiwen/sessionRound', { method: 'POST', json: { sessionId: currentSession.sessionId, roundIndex: idx } });
    }
  };

  const toggleCardCheck = (roundIndex: number, cardId: string) => {
    if (!currentSession) return;
    const card = currentSession.rounds[roundIndex]?.models.find(m => m.id === cardId);
    if (!card) return;
    const nextChecked = !card.checked;

    setSessions(prev =>
      prev.map(s => {
        if (s.id !== currentSession.id) return s;
        const updatedRounds = s.rounds.map((r, rIdx) => {
          if (rIdx !== roundIndex) return r;
          const updatedModels = r.models.map(m => (m.id === cardId ? { ...m, checked: nextChecked } : m));
          return { ...r, models: updatedModels };
        });
        return { ...s, rounds: updatedRounds };
      })
    );
    if (card.cardId) {
      api('/tiwen/cardCheck', { method: 'POST', json: { cardId: card.cardId, checked: nextChecked } });
    }
  };

  const isAllCardsChecked = useCallback((roundIndex?: number) => {
    if (!currentSession) return false;
    const rIdx = roundIndex ?? activeRoundIndex;
    const round = currentSession.rounds[rIdx];
    if (!round || !round.models.length) return false;
    const validCards = round.models.filter(m => !m.failed && (m.body.trim().length > 0 || m.tokenCount > 0));
    return validCards.length > 0 && validCards.every(m => m.checked);
  }, [currentSession, activeRoundIndex]);

  const checkedInRoundCount = useCallback((roundIndex?: number) => {
    if (!currentSession) return 0;
    const rIdx = roundIndex ?? activeRoundIndex;
    const round = currentSession.rounds[rIdx];
    if (!round) return 0;
    return round.models.filter(m => m.checked).length;
  }, [currentSession, activeRoundIndex]);

  const selectableCardsCount = useCallback((roundIndex?: number) => {
    if (!currentSession) return 0;
    const rIdx = roundIndex ?? activeRoundIndex;
    const round = currentSession.rounds[rIdx];
    if (!round) return 0;
    return round.models.filter(m => !m.failed && (m.body.trim().length > 0 || m.tokenCount > 0)).length;
  }, [currentSession, activeRoundIndex]);

  const toggleSelectAll = useCallback((roundIndex?: number) => {
    if (!currentSession) return;
    const targetRoundIdx = roundIndex ?? activeRoundIndex;
    const round = currentSession.rounds[targetRoundIdx];
    if (!round || !round.models.length) return;

    // 可勾选有效卡片: 未失败且有内容
    const validCards = round.models.filter(m => !m.failed && (m.body.trim().length > 0 || m.tokenCount > 0));
    if (validCards.length === 0) {
      showToast('当前轮次暂无可采纳的模型观点');
      return;
    }

    const allChecked = validCards.every(m => m.checked);
    const nextChecked = !allChecked;

    setSessions(prev =>
      prev.map(s => {
        if (s.id !== currentSession.id) return s;
        const updatedRounds = s.rounds.map((r, rIdx) => {
          if (rIdx !== targetRoundIdx) return r;
          const validIds = new Set(validCards.map(c => c.id));
          const updatedModels = r.models.map(m =>
            validIds.has(m.id) ? { ...m, checked: nextChecked } : m
          );
          return { ...r, models: updatedModels };
        });
        return { ...s, rounds: updatedRounds };
      })
    );

    // 服务端异步同步
    validCards.forEach(card => {
      if (card.cardId && card.checked !== nextChecked) {
        api('/tiwen/cardCheck', { method: 'POST', json: { cardId: card.cardId, checked: nextChecked } });
      }
    });

    showToast(
      nextChecked
        ? `已全选当前轮次 ${validCards.length} 个模型观点`
        : '已取消全选当前轮次模型观点'
    );
  }, [currentSession, activeRoundIndex, showToast]);

  const deleteCard = (roundIndex: number, cardId: string) => {
    if (!currentSession) return;
    const card = currentSession.rounds[roundIndex]?.models.find(m => m.id === cardId);
    setSessions(prev =>
      prev.map(s => {
        if (s.id !== currentSession.id) return s;
        const updatedRounds = s.rounds.map((r, rIdx) => {
          if (rIdx !== roundIndex) return r;
          return { ...r, models: r.models.filter(m => m.id !== cardId) };
        });
        return { ...s, rounds: updatedRounds };
      })
    );
    if (card?.cardId) {
      api('/tiwen/cardDelete', { method: 'POST', json: { cardId: card.cardId } });
    }
  };

  const retryCard = useCallback(async (roundIndex: number, cardId: string) => {
    if (!currentSession || !currentSession.sessionId) return;
    const round = currentSession.rounds[roundIndex];
    if (!round) return;
    const card = round.models.find(m => m.id === cardId);
    if (!card || card.isStreaming) return;

    const sessionId = currentSession.sessionId;
    const roundId = round.roundId || parseInt(round.id.replace('round_', ''), 10);
    const mid = card.modelKey;
    const roundKey = round.id;
    const startedAt = Date.now();

    updateCard(sessionId, roundKey, mid, {
      body: '',
      isStreaming: true,
      failed: false,
      failMessage: '',
      meta: '重新推演中... · 0 tok',
      tokenCount: 0,
      durationSec: 0,
    });
    streamBuffers.current[card.id] = '';

    showToast(`🔄 正在重新调用 ${card.modelName || mid} 生成回答...`);

    let totalTokens = 0;
    let failed = false;

    await streamSSE('/tiwen/chat', { sessionId, roundId, model: mid }, {
      onEvent: (event, data) => {
        if (event === 'delta' && data.content) {
          updateCard(sessionId, roundKey, mid, {}, String(data.content));
        } else if (event === 'usage') {
          const tokens = Number(data.tokens) || 0;
          const dur = Number(data.duration) || ((Date.now() - startedAt) / 1000);
          totalTokens += tokens;
          updateCard(sessionId, roundKey, mid, {
            tokenCount: tokens,
            durationSec: dur,
            meta: `${tokens.toLocaleString()} tok · ${dur.toFixed(1)}s`,
          });
        } else if (event === 'fail') {
          failed = true;
          const rawMsg = data.message ? String(data.message) : '';
          const displayMsg = rawMsg
            ? (rawMsg.startsWith('输出异常') ? rawMsg : `输出异常（${rawMsg}），请稍后重试`)
            : '输出异常，请稍后重试';
          updateCard(sessionId, roundKey, mid, {
            isStreaming: false,
            failed: true,
            failMessage: displayMsg,
            meta: '输出异常',
          });
        } else if (event === 'done') {
          updateCard(sessionId, roundKey, mid, { isStreaming: false, failed: false });
        }
      },
    });

    if (flushTimer.current !== null) {
      window.clearTimeout(flushTimer.current);
      flushTimer.current = null;
    }
    flushStreams();
    streamBuffers.current = {};

    if (failed) {
      showToast('⚠️ 模型重试未成功，可稍后再试');
    } else {
      consumeTokens(totalTokens);
      refreshUser();
      showToast('✨ 模型回答已重新生成');
    }

    const detail = await api<SessionData>('/tiwen/sessionDetail', { method: 'POST', json: { sessionId } });
    if (detail.ok && detail.data) {
      setSessions(prev => prev.map(s => (s.sessionId === sessionId ? { ...detail.data!, activeRoundIndex: roundIndex } : s)));
    }
  }, [currentSession, updateCard, flushStreams, showToast, consumeTokens, refreshUser]);

  // ---------- 编辑区草稿(本地即时 + 防抖落库) ----------
  const persistDraft = useCallback((sessionId: number, draft: string) => {
    if (draftSaveTimer.current !== null) {
      window.clearTimeout(draftSaveTimer.current);
    }
    draftSaveTimer.current = window.setTimeout(() => {
      api('/tiwen/sessionDraft', { method: 'POST', json: { sessionId, draft } });
    }, 1200);
  }, []);

  const updateEditorDraft = useCallback((content: string) => {
    if (!currentSession) return;
    setSessions(prev =>
      prev.map(s => (s.id === currentSession.id ? { ...s, editorDraft: content } : s))
    );
    if (currentSession.sessionId) {
      persistDraft(currentSession.sessionId, content);
    }
  }, [currentSession, persistDraft]);

  const appendEditorDraft = useCallback((content: string) => {
    if (!currentSession) return;
    const newDraft = currentSession.editorDraft
      ? `${currentSession.editorDraft}\n\n---\n\n${content}`
      : content;
    updateEditorDraft(newDraft);
  }, [currentSession, updateEditorDraft]);

  // ---------- 采纳合并 ----------
  const adoptMergedContent = () => {
    if (!currentSession) return;
    const checkedList: string[] = [];

    currentSession.rounds.forEach((round, rIdx) => {
      round.models.forEach(model => {
        if (model.checked) {
          const modelName = model.modelName || getModelMeta(model.modelKey).name;
          checkedList.push(
            `### 来源于 Round ${rIdx + 1} (${round.tag}) · ${modelName} (${model.meta})\n**针对问题**：${round.question}\n\n${model.body}`
          );
        }
      });
    });

    if (checkedList.length === 0) {
      showToast('请先勾选需要采纳的模型结果卡片');
      return;
    }

    appendEditorDraft(checkedList.join('\n\n---\n\n'));
    showToast(`已采纳 ${checkedList.length} 项答案并导入研报编辑区`);
  };

  // ---------- 分析综合(真实流式) ----------
  const synthesizeContent = async () => {
    if (!currentSession?.sessionId) return;
    if (totalCheckedCount === 0) {
      showToast('请先在卡片上勾选至少 1 项高价值观点');
      return;
    }

    setIsSynthesizing(true);
    // 运行中常驻提示(duration = 0 不自动消隐,直到输出完成或异常)
    showToast('⚡ 正在进行多模型观点交叉比对与分析综合...', 0);
    let result = '';
    let tokens = 0;
    let failed = false;
    let failMsg = '';

    try {
      await streamSSE('/tiwen/synthesize', { sessionId: currentSession.sessionId }, {
        onEvent: (event, data) => {
          if (event === 'delta' && data.content) {
            result += String(data.content);
          } else if (event === 'usage') {
            tokens = Number(data.tokens) || 0;
          } else if (event === 'fail') {
            failed = true;
            failMsg = String(data.message || '请稍后重试');
          }
        },
      });

      if (failed) {
        showToast(`综合分析失败: ${failMsg || '请稍后重试'}`);
        return;
      }
      if (!result) {
        showToast('综合分析未返回有效内容，请稍后重试');
        return;
      }

      const dateStr = new Date().toLocaleDateString();
      const header = `# ⚡【AI 综合研报】${currentSession.title}\n*生成时间: ${dateStr} · 基于勾选的多模型观点交叉比对*\n\n`;
      appendEditorDraft(header + result);
      consumeTokens(tokens);
      refreshUser();
      showToast('⚡ 分析综合完成，已生成结构化研报并同步至编辑区');
    } catch (e: any) {
      showToast(`综合分析异常: ${e?.message || '网络连接中断'}`);
    } finally {
      setIsSynthesizing(false);
    }
  };

  return (
    <SessionContext.Provider
      value={{
        sessions,
        currentSessionId,
        currentSession,
        activeRoundIndex,
        isThinking,
        totalCheckedCount,
        toastMessage,
        showToast,
        loadSession,
        createSession,
        renameSession,
        deleteSession,
        setActiveRoundIndex,
        toggleCardCheck,
        toggleSelectAll,
        isAllCardsChecked,
        checkedInRoundCount,
        selectableCardsCount,
        deleteCard,
        retryCard,
        adoptMergedContent,
        isSynthesizing,
        synthesizeContent,
        updateEditorDraft,
        sendFollowUp,
        webSearchEnabled,
        smartSearch,
        setSmartSearch,
        models,
        getModelMeta,
        selectedModelIds,
        setSelectedModelIds,
        loading,
        isSubscribeModalOpen,
        subscribeModalReason,
        subscribeModalQuota,
        openSubscribeModal,
        closeSubscribeModal,
      }}
    >
      {children}
    </SessionContext.Provider>
  );
};

export const useSession = () => {
  const context = useContext(SessionContext);
  if (!context) throw new Error('useSession must be used within a SessionProvider');
  return context;
};
