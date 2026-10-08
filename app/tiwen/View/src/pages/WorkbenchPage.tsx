import React, { useEffect, useRef, useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
import { useSession } from '../context/SessionContext';
import { useAuth } from '../context/AuthContext';
import { useLanguage } from '../context/LanguageContext';
import { RoundTree } from '../components/workbench/RoundTree';
import { FocusArea } from '../components/workbench/FocusArea';
import { ModelSelector } from '../components/workbench/ModelSelector';
import { AttachmentButton, AttachmentPreview, AttachmentData } from '../components/workbench/AttachmentField';
import { WebSearchToggle } from '../components/workbench/WebSearchToggle';
import { RecentSessions } from '../components/common/RecentSessions';
import { fetchAppConfig } from '../api/models';
import { Loader2, Send, Sparkles } from 'lucide-react';

export const WorkbenchPage: React.FC = () => {
  const { sessionId } = useParams<{ sessionId?: string }>();
  const navigate = useNavigate();
  const {
    loadSession,
    currentSession,
    loading,
    createSession,
    webSearchEnabled,
    smartSearch,
    setSmartSearch,
  } = useSession();
  const { user, isAuthenticated, openAuthModal } = useAuth();
  const { t, locale } = useLanguage();
  const followUpInputRef = useRef<HTMLInputElement>(null);
  const [firstQuestion, setFirstQuestion] = useState('');
  const [firstAttachment, setFirstAttachment] = useState<AttachmentData | null>(null);
  const [freeDailyAsks, setFreeDailyAsks] = useState<number | null>(null);

  // /chat/:sessionId 定位对应会话;/chat 根路径恒为「新提问」页,不再自动跳入旧会话
  useEffect(() => {
    if (sessionId) {
      loadSession(sessionId);
    }
  }, [sessionId]);

  // 获取免登录/当前用户的免费提问额度配置（共享缓存）
  useEffect(() => {
    let unmounted = false;
    fetchAppConfig().then(cfg => {
      if (unmounted || !cfg) return;
      if (cfg.plans) {
        setFreeDailyAsks(Number(cfg.plans.free?.daily_asks ?? 20));
      }
    });
    return () => {
      unmounted = true;
    };
  }, []);

  const handleFocusFollowUp = () => {
    followUpInputRef.current?.focus();
  };

  const handleNewSessionAsk = async () => {
    if (!isAuthenticated) {
      openAuthModal('登录后即可开启多模型研报工作台');
      return;
    }
    if (!firstQuestion.trim()) return;
    const q = firstQuestion.trim();
    const att = firstAttachment ? { name: firstAttachment.name, content: firstAttachment.content } : null;
    setFirstQuestion('');
    setFirstAttachment(null);
    const newId = await createSession(q, att);
    if (newId) {
      navigate(`/chat/${newId}`);
    }
  };

  // 未登录空态:引导登录/注册
  if (!isAuthenticated || !user) {
    const loginGateSub = t.workbench.loginGateSub.replace(
      '{asks}',
      freeDailyAsks === null ? '20' : freeDailyAsks > 0 ? `${freeDailyAsks} ${locale === 'en' ? '' : '次'}`.trim() : t.landing.noticeUnlimited,
    );
    return (
      <div className="max-w-xl mx-auto px-4 py-16">
        <div className="bg-white rounded-3xl p-8 sm:p-10 border border-[#e2e8f0] shadow-card text-center space-y-5">
          <div className="w-14 h-14 rounded-2xl bg-brand-subtle text-brand flex items-center justify-center mx-auto">
            <Sparkles className="w-7 h-7" />
          </div>
          <h2 className="text-xl font-extrabold text-[#0f172a]">{t.workbench.loginGateTitle}</h2>
          <p className="text-xs sm:text-sm text-[#64748b] leading-relaxed">{loginGateSub}</p>
          <div className="flex flex-col sm:flex-row gap-3 justify-center pt-1">
            <button
              onClick={() => openAuthModal(loginGateSub, 'register')}
              className="px-5 py-2.5 rounded-xl bg-brand text-white font-bold text-xs hover:bg-brand-hover shadow-xs transition-colors"
            >
              {t.nav.register}
            </button>
            <button
              onClick={() => openAuthModal(loginGateSub, 'login')}
              className="px-5 py-2.5 rounded-xl border border-[#cbd5e1] text-[#334155] font-semibold text-xs hover:bg-[#f8fafc] transition-colors"
            >
              {t.nav.login}
            </button>
          </div>
        </div>
      </div>
    );
  }

  // 加载中
  if (loading) {
    return (
      <div className="max-w-[1400px] mx-auto px-4 sm:px-6 py-16 flex items-center justify-center gap-2 text-sm font-semibold text-[#65718a]">
        <Loader2 className="w-4 h-4 animate-spin text-brand" />
        <span>{t.workbench.loadingSessions}</span>
      </div>
    );
  }

  // 无会话路由参数(或会话不存在):展示新提问页 + 近期对话列表
  if (!sessionId || !currentSession) {
    return (
      <div className="max-w-2xl mx-auto px-4 py-12 space-y-5">
        <div className="bg-white rounded-3xl p-6 sm:p-8 border border-[#e2e8f0] shadow-card space-y-5">
          <div className="flex items-center gap-2.5">
            <span className="w-2.5 h-2.5 rounded-full bg-brand"></span>
            <h2 className="font-extrabold text-base text-[#1b2230]">{t.workbench.newSessionTitle}</h2>
          </div>
          <p className="text-xs text-[#8c97af] leading-relaxed">{t.workbench.newSessionSub}</p>

          <ModelSelector />

          <div className="rounded-2xl border border-[#cbd5e1] focus-within:border-brand focus-within:ring-4 focus-within:ring-brand-subtle transition-all bg-[#fafcff] p-2.5 sm:p-3 space-y-2">
            {/* 独立附件预览卡片（单独成行，不占用输入框宽度） */}
            {firstAttachment && (
              <div className="flex items-center pt-0.5">
                <AttachmentPreview
                  value={firstAttachment}
                  onRemove={() => setFirstAttachment(null)}
                />
              </div>
            )}

            <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
              <input
                type="text"
                value={firstQuestion}
                onChange={e => setFirstQuestion(e.target.value)}
                onKeyDown={e => { if (e.key === 'Enter') handleNewSessionAsk(); }}
                placeholder={t.workbench.newSessionPlaceholder}
                className="flex-1 px-2.5 py-1.5 text-xs sm:text-sm text-[#1b2230] placeholder:text-[#94a3b8] focus:outline-none bg-transparent"
              />
              <div className="flex items-center gap-2 self-end sm:self-auto shrink-0">
                {webSearchEnabled && (
                  <WebSearchToggle active={smartSearch} onToggle={() => setSmartSearch(!smartSearch)} />
                )}
                <AttachmentButton
                  hasAttachment={!!firstAttachment}
                  onUploaded={setFirstAttachment}
                />
                <button
                  onClick={handleNewSessionAsk}
                  disabled={!firstQuestion.trim()}
                  className="px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white font-bold text-xs disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5 shadow-xs transition-all"
                >
                  <Send className="w-3.5 h-3.5" />
                  <span>{t.workbench.send}</span>
                </button>
              </div>
            </div>
          </div>

          {sessionId && (
            <p className="text-[11px] text-amber-600">{t.workbench.sessionNotFound}</p>
          )}
        </div>

        {/* 近期对话(按时间倒序,最多 5 条) */}
        <RecentSessions limit={5} />
      </div>
    );
  }

  return (
    <div className="max-w-[1400px] mx-auto px-4 sm:px-6 py-5">
      {/* Main Dual-Column Duo Workbench */}
      <div className="flex flex-col lg:flex-row gap-5 items-start">
        {/* Left: Sticky Round Tree Sidebar + Recent Sessions */}
        <div className="w-full lg:w-72 shrink-0 space-y-3 lg:sticky lg:top-4">
          <RoundTree onFocusFollowUp={handleFocusFollowUp} />
          <RecentSessions limit={5} excludeId={currentSession.id} dense />
        </div>

        {/* Right: Focused Active Round View */}
        <FocusArea followUpInputRef={followUpInputRef} />
      </div>
    </div>
  );
};
