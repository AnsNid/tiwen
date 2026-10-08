import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, ApiResult } from '../api/client';
import { currencySymbolOf } from '../api/models';
import { MarkdownRenderer } from '../components/common/MarkdownRenderer';
import logoImg from '../../static/logo.png';
import {
  LayoutDashboard,
  CreditCard,
  Users,
  MessageSquareText,
  FileText,
  LogOut,
  RefreshCw,
  Loader2,
  MessagesSquare,
  ArrowLeft,
  ChevronLeft,
  ChevronRight,
  RotateCcw,
  Save,
  Search,
  Globe,
  ShieldCheck,
  AlertCircle,
  Eye,
  EyeOff,
  Lock,
  Mail,
  Sparkles,
  Activity,
  TrendingUp,
  Menu,
  X,
  Shield,
  Receipt,
  History,
} from 'lucide-react';

/* ============ 类型定义 ============ */

interface AdminStats {
  kpi: {
    memberTotal: number;
    paidMembers: number;
    activeSubscriptions: number;
    sessionTotal: number;
    todayCards: number;
    todayTokens: number;
    monthTokens: number;
    totalTokens: number;
  };
  daily: { dates: string[]; tokens: number[]; cards: number[] };
  models: { modelId: string; modelName: string; tokens: number; asks: number; avgDuration: number; percent: number }[];
  plans: { plan: string; name: string; total: number; percent: number }[];
  topMembers: { uid: number; name: string; email: string; plan: string; tokensUsed: number; tokensQuota: number }[];
}

interface AdminMember {
  uid: number;
  name: string;
  email: string;
  avatar: string;
  plan: string;
  planInterval: string;
  tokensQuota: number;
  tokensUsed: number;
  dailyAsks: number;
  subscriptionStatus: string;
  currentPeriodEnd: number;
  createdAt: string;
  userCreatedAt: string;
}

interface PromptsData {
  chat: { value: string; default: string; customized: boolean };
  synthesis: { value: string; default: string; customized: boolean };
}

interface WebSearchData {
  enabled: boolean;
  api_key_set: boolean;
  api_key_masked: string;
  max_results: number;
  search_depth: 'basic' | 'advanced';
  include_answer: boolean;
  timeout: number;
  max_content_chars: number;
}

interface LegalDocEntry {
  zh: string;
  'zh-TW': string;
  en: string;
  customized: boolean;
}

interface LegalData {
  register: LegalDocEntry;
  privacy: LegalDocEntry;
  about: LegalDocEntry;
  payment: LegalDocEntry;
}

interface AdminUser {
  uid: number;
  name: string;
  email: string;
  avatar: string;
  nickname?: string;
  username?: string;
}

/** 后端 login/info 返回的用户形状归一化为 AdminUser */
function normalizeUser(raw: AdminUser): AdminUser {
  return { ...raw, name: raw.name || raw.nickname || raw.username || raw.email || `UID ${raw.uid}` };
}

/* ============ 工具与常量 ============ */

const ADMIN_TOKEN_KEY = 'tiwen_admin_token';
const ADMIN_USER_KEY = 'tiwen_admin_user';

type AdminRequest = <T = any>(path: string, options?: RequestInit & { json?: any; token?: string }) => Promise<ApiResult<T>>;

/* ============ 分区数据缓存 ============
 * 模块级缓存:切换/返回分区时命中即用不重复请求;保存/调整成功后按前缀失效;
 * 退出登录全清。页面整刷后缓存自然重建(每分区仅首次访问各请求一次)。
 */
const adminDataCache: Record<string, { at: number; data: unknown }> = {};
const CACHE_TTL = 5 * 60 * 1000;

function cacheGet<T>(key: string, maxAge: number = CACHE_TTL): T | null {
  const hit = adminDataCache[key];
  return hit && Date.now() - hit.at <= maxAge ? (hit.data as T) : null;
}

function cacheSet(key: string, data: unknown): void {
  adminDataCache[key] = { at: Date.now(), data };
}

/** 前缀失效:传 'members' 失效 members:* ;空串全清 */
function cacheDrop(prefix = ''): void {
  for (const k of Object.keys(adminDataCache)) {
    if (prefix === '' || k === prefix || k.startsWith(prefix + ':')) {
      delete adminDataCache[k];
    }
  }
}

const BREAKDOWN_COLORS = ['#2f6bff', '#7b52d7', '#0d9488', '#b45309', '#be123c', '#4f46e5'];

function fmtNum(n: number): string {
  if (n >= 100000000) return (n / 100000000).toFixed(1) + '亿';
  if (n >= 10000) return (n / 10000).toFixed(1) + '万';
  return n.toLocaleString();
}

function fmtDate(raw: string | number): string {
  if (!raw) return '-';
  const n = typeof raw === 'string' ? parseInt(raw, 10) : raw;
  const d = typeof raw === 'number' || (typeof raw === 'string' && !isNaN(n) && raw.length >= 9 && /^\d+$/.test(raw))
    ? new Date((typeof raw === 'number' ? raw : n) * 1000)
    : new Date(raw);
  return isNaN(d.getTime()) ? String(raw) : d.toLocaleDateString();
}

const PLAN_BADGE: Record<string, string> = {
  free: 'bg-slate-100 text-slate-600 border-slate-200',
  pro: 'bg-blue-50 text-blue-700 border-blue-200',
  team: 'bg-violet-50 text-violet-700 border-violet-200',
  flagship: 'bg-amber-50 text-amber-700 border-amber-200',
};

function planBadge(plan: string): string {
  return PLAN_BADGE[plan] || 'bg-slate-100 text-slate-600 border-slate-200';
}

const SUB_STATUS_LABEL: Record<string, { label: string; cls: string }> = {
  active: { label: '生效中', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
  past_due: { label: '逾期', cls: 'bg-amber-50 text-amber-700 border-amber-200' },
  canceled: { label: '已取消', cls: 'bg-slate-100 text-slate-500 border-slate-200' },
  unpaid: { label: '未支付', cls: 'bg-amber-50 text-amber-700 border-amber-200' },
};

/* ============ 管理台状态管理 Hook ============ */

function useAdminAuth() {
  const [token, setToken] = useState<string>(() => localStorage.getItem(ADMIN_TOKEN_KEY) || '');
  const [admin, setAdmin] = useState<AdminUser | null>(() => {
    try {
      const raw = localStorage.getItem(ADMIN_USER_KEY);
      return raw ? (JSON.parse(raw) as AdminUser) : null;
    } catch {
      return null;
    }
  });

  const signIn = useCallback((t: string, user: AdminUser) => {
    localStorage.setItem(ADMIN_TOKEN_KEY, t);
    localStorage.setItem(ADMIN_USER_KEY, JSON.stringify(user));
    setToken(t);
    setAdmin(user);
  }, []);

  const signOut = useCallback(() => {
    localStorage.removeItem(ADMIN_TOKEN_KEY);
    localStorage.removeItem(ADMIN_USER_KEY);
    cacheDrop();
    setToken('');
    setAdmin(null);
  }, []);

  const request = useCallback(
    async <T,>(path: string, options: RequestInit & { json?: any; token?: string } = {}): Promise<ApiResult<T>> => {
      const res = await api<T>(path, { ...options, token });
      if (res.code === 401 || res.code === 403) {
        signOut();
      }
      return res;
    },
    [token, signOut]
  );

  return { token, admin, signIn, signOut, request };
}

/* ============ 后台登录页面结构 ============ */

const LoginGate: React.FC<{ onSignIn: (token: string, user: AdminUser) => void }> = ({ onSignIn }) => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email.trim() || !password) {
      setError('请输入邮箱和密码');
      return;
    }
    setLoading(true);
    setError('');
    const res = await api<{ token: string; user: AdminUser }>('/tiwen/admin/login', {
      method: 'POST',
      json: { email: email.trim(), password },
    });
    setLoading(false);
    if (res.code === 403) {
      setError(res.message || '没有提问后台管理权限');
      return;
    }
    if (!res.ok || !res.data) {
      setError(res.message || '登录失败，请检查账号密码');
      return;
    }
    onSignIn(res.data.token, normalizeUser(res.data.user));
  };

  return (
    <div className="flex min-h-screen w-full flex-col lg:flex-row bg-[#f5f7fb]">
      {/* ── 桌面端品牌展示区 (左侧) ── */}
      <div className="relative hidden lg:flex lg:w-1/2 flex-col justify-between overflow-hidden bg-[#070d1e] p-8 xl:p-12 text-white min-h-screen select-none">
        {/* 环境光与网格背景 */}
        <div className="pointer-events-none absolute -left-20 -top-20 h-96 w-96 rounded-full bg-[#2f6bff] opacity-25 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-24 -right-20 h-96 w-96 rounded-full bg-[#1e4fd6] opacity-25 blur-3xl" />
        <div className="pointer-events-none absolute inset-0 bg-grid-pattern opacity-30" />

        {/* 顶部品牌标牌 */}
        <div className="relative z-10">
          <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 backdrop-blur-sm">
            <img src={logoImg} alt="tiwen logo" className="h-4 w-4 object-contain" />
            <span className="text-xs font-semibold tracking-wide text-blue-200">
              Tiwen AI Multi-Model Workbench
            </span>
          </div>

          <div className="mt-8 max-w-lg">
            <h1 className="text-3xl font-black tracking-tight text-white xl:text-4xl leading-tight">
              智能多模型提问与 <br />
              <span className="text-[#5b8eff]">研报生成</span> 管控中心
            </h1>
            <p className="mt-4 text-sm leading-relaxed text-blue-100/75">
              支持全域多模型并发调度、Token 消耗全景观测、会员订阅套餐策略与全局系统提示词编排。
            </p>
          </div>
        </div>

        {/* 中部：核心特性矩阵 */}
        <div className="relative z-10 my-6 grid grid-cols-2 gap-4">
          <div className="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-blue-500/20 text-blue-300">
                <TrendingUp className="h-4 w-4" />
              </div>
              <div>
                <div className="text-xs font-bold text-white">Token 消耗洞察</div>
                <div className="text-[11px] text-blue-200/60 mt-0.5">毫秒级统计模型与用量</div>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-indigo-500/20 text-indigo-300">
                <CreditCard className="h-4 w-4" />
              </div>
              <div>
                <div className="text-xs font-bold text-white">订阅价格与配额</div>
                <div className="text-[11px] text-blue-200/60 mt-0.5">动态调整即刻生效</div>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-emerald-500/20 text-emerald-300">
                <Users className="h-4 w-4" />
              </div>
              <div>
                <div className="text-xs font-bold text-white">会员权益管控</div>
                <div className="text-[11px] text-blue-200/60 mt-0.5">配额调整与用量重置</div>
              </div>
            </div>
          </div>

          <div className="rounded-2xl border border-white/10 bg-white/5 p-4 backdrop-blur-sm">
            <div className="flex items-center gap-3">
              <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-violet-500/20 text-violet-300">
                <MessageSquareText className="h-4 w-4" />
              </div>
              <div>
                <div className="text-xs font-bold text-white">提示词全局编排</div>
                <div className="text-[11px] text-blue-200/60 mt-0.5">对话 Prompt 配置</div>
              </div>
            </div>
          </div>
        </div>

        {/* 底部运行状态 */}
        <div className="relative z-10 flex items-center justify-between border-t border-white/10 pt-4 text-xs text-blue-200/60">
          <div className="flex items-center gap-2">
            <span className="relative flex h-2 w-2">
              <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400 opacity-75" />
              <span className="relative inline-flex h-2 w-2 rounded-full bg-emerald-500" />
            </span>
            <span>Swoole 协程服务引擎 · 稳定运行中</span>
          </div>
          <div className="font-mono text-[11px]">X-PHP Tiwen Module</div>
        </div>
      </div>

      {/* ── 登录表单区 (右侧) ── */}
      <div className="flex flex-1 flex-col items-center justify-center min-h-screen p-4 sm:p-6 lg:p-8">
        {/* 移动端顶部标题 */}
        <div className="mb-6 flex flex-col items-center text-center lg:hidden">
          <div className="flex h-12 w-12 items-center justify-center rounded-2xl bg-white p-2 border border-[#e2e8f0] shadow-sm">
            <img src={logoImg} alt="tiwen logo" className="h-full w-full object-contain" />
          </div>
          <h1 className="mt-3 text-xl font-bold text-[#0f172a]">
            提问 · 管理后台
          </h1>
          <p className="mt-1 text-xs text-[#64748b]">
            多模型调度、用量统计与订阅管理平台
          </p>
        </div>

        {/* 登录卡片 */}
        <div className="w-full max-w-md">
          <div className="rounded-2xl border border-[#e2e8f0] bg-white p-6 sm:p-8 shadow-card">
            {/* 桌面端卡片头部 */}
            <div className="mb-6 hidden lg:block">
              <div className="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-xl bg-white border border-[#e2e8f0] p-2 shadow-xs">
                <img src={logoImg} alt="tiwen logo" className="w-full h-full object-contain" />
              </div>
              <h2 className="text-2xl font-bold text-[#0f172a]">
                管理员登录
              </h2>
              <p className="mt-1 text-xs text-[#64748b]">
                请输入系统凭据，登录提问管控后台
              </p>
            </div>

            {/* 错误提示 */}
            {error && (
              <div className="mb-4 flex items-start gap-2 text-xs text-rose-600 bg-rose-50 border border-rose-100 rounded-xl p-3">
                <AlertCircle className="w-4 h-4 mt-0.5 shrink-0" />
                <span className="leading-relaxed">{error}</span>
              </div>
            )}

            {/* 表单 */}
            <form onSubmit={submit} className="space-y-4">
              <div>
                <label className="mb-1.5 block text-xs font-bold text-[#334155]" htmlFor="login-email">
                  管理员账号 / 邮箱
                </label>
                <div className="relative flex items-center">
                  <Mail className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[#94a3b8]" />
                  <input
                    id="login-email"
                    type="text"
                    name="username"
                    autoComplete="username"
                    placeholder="请输入管理员邮箱或账号"
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    style={{ paddingLeft: '40px', paddingRight: '14px' }}
                    className="h-10 w-full rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-sm text-[#0f172a] placeholder:text-[#94a3b8] transition-all focus:border-[#2f6bff] focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                    required
                  />
                </div>
              </div>

              <div>
                <label className="mb-1.5 block text-xs font-bold text-[#334155]" htmlFor="login-password">
                  登录密码
                </label>
                <div className="relative flex items-center">
                  <Lock className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-[#94a3b8]" />
                  <input
                    id="login-password"
                    type={showPassword ? 'text' : 'password'}
                    name="password"
                    autoComplete="current-password"
                    placeholder="请输入登录密码"
                    value={password}
                    onChange={(e) => setPassword(e.target.value)}
                    style={{ paddingLeft: '40px', paddingRight: '40px' }}
                    className="h-10 w-full rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-sm text-[#0f172a] placeholder:text-[#94a3b8] transition-all focus:border-[#2f6bff] focus:bg-white focus:outline-none focus:ring-2 focus:ring-blue-100"
                    required
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-2.5 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-[#94a3b8] transition-colors hover:text-[#334155]"
                    tabIndex={-1}
                    aria-label={showPassword ? '隐藏密码' : '显示密码'}
                  >
                    {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                  </button>
                </div>
              </div>

              <div className="pt-2">
                <button
                  type="submit"
                  disabled={loading}
                  className="flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-[#2f6bff] text-sm font-bold text-white shadow-sm transition-all hover:bg-[#1e4fd6] active:scale-[0.99] disabled:cursor-not-allowed disabled:opacity-60"
                >
                  {loading ? (
                    <>
                      <Loader2 className="h-4 w-4 animate-spin" />
                      <span>正在验证…</span>
                    </>
                  ) : (
                    <span>登录管理后台</span>
                  )}
                </button>
              </div>
            </form>

            <div className="mt-4 flex items-center justify-center gap-1.5 text-xs text-[#94a3b8]">
              <ShieldCheck className="h-3.5 w-3.5 text-[#2f6bff]" />
              <span>需要 tiwen:admin 权限的管理员账号</span>
            </div>
          </div>

          <div className="mt-4 text-center">
            <Link
              to="/"
              className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#64748b] hover:text-[#2f6bff] transition-colors"
            >
              <ArrowLeft className="h-3.5 w-3.5" />
              返回提问工作台首页
            </Link>
          </div>
        </div>
      </div>
    </div>
  );
};

/* ============ 通用卡片组件 ============ */

const KpiTile: React.FC<{
  label: string;
  value: string;
  sub?: string;
  icon: React.ReactNode;
  accent?: boolean;
}> = ({ label, value, sub, icon, accent }) => (
  <div className="group relative overflow-hidden rounded-2xl border border-[#e2e8f0] bg-white p-4 shadow-card transition-all">
    <div className="flex items-center justify-between">
      <span className="text-xs font-semibold text-[#64748b] truncate">{label}</span>
      <div className={`flex h-8 w-8 items-center justify-center rounded-xl transition-colors ${
        accent ? 'bg-blue-50 text-[#2f6bff]' : 'bg-[#f1f5f9] text-[#64748b]'
      }`}>
        {icon}
      </div>
    </div>
    <div className={`mt-2 font-mono text-2xl font-bold tabular-nums tracking-tight truncate ${accent ? 'text-[#2f6bff]' : 'text-[#0f172a]'}`}>
      {value}
    </div>
    {sub && <div className="mt-1 text-xs text-[#94a3b8] truncate">{sub}</div>}
  </div>
);

const SectionCard: React.FC<{ title: string; extra?: React.ReactNode; children: React.ReactNode }> = ({ title, extra, children }) => (
  <div className="rounded-2xl border border-[#e2e8f0] bg-white shadow-card overflow-hidden">
    <div className="flex items-center justify-between gap-3 px-4 py-3.5 border-b border-[#f1f5f9]">
      <h3 className="text-sm font-bold text-[#0f172a]">{title}</h3>
      {extra}
    </div>
    <div className="p-4">{children}</div>
  </div>
);

const EmptyHint: React.FC<{ text?: string }> = ({ text = '暂无数据' }) => (
  <div className="py-12 text-center text-xs text-[#94a3b8]">{text}</div>
);

/* ============ 概览: 消耗统计 ============ */

const DailyTrendChart: React.FC<{ daily: AdminStats['daily'] }> = ({ daily }) => {
  const { dates, tokens, cards } = daily;
  const max = useMemo(() => Math.max(1, ...tokens), [tokens]);

  if (!dates.length) return <EmptyHint />;
  return (
    <div>
      <div className="flex items-end gap-2 h-44 pt-4" role="img" aria-label="近14天每日 Token 消耗柱状图">
        {dates.map((d, i) => {
          const v = tokens[i] || 0;
          const h = v > 0 ? Math.max(6, Math.round((v / max) * 100)) : 3;
          return (
            <div key={d} className="flex-1 flex flex-col items-center justify-end h-full group relative" title={`${d} · ${v.toLocaleString()} tokens · ${cards[i] || 0} 次回答`}>
              <div className="pointer-events-none absolute -top-8 left-1/2 -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity bg-[#0f172a] text-white text-[10px] font-mono px-2 py-1 rounded-md shadow whitespace-nowrap z-20">
                {fmtNum(v)} tok
              </div>
              <div
                className={`w-full rounded-t-md transition-all ${
                  v > 0 ? 'bg-[#2f6bff]/85 group-hover:bg-[#2f6bff]' : 'bg-[#e2e8f0]'
                }`}
                style={{ height: `${h}%` }}
              />
            </div>
          );
        })}
      </div>
      <div className="flex gap-2 mt-2">
        {dates.map((d, i) => (
          <div key={d} className="flex-1 text-center text-[10px] font-mono text-[#94a3b8] truncate">
            {i % 2 === 0 ? d.slice(5) : ''}
          </div>
        ))}
      </div>
      <div className="flex items-center justify-between mt-3 pt-3 border-t border-[#f1f5f9] text-xs">
        <span className="text-[#64748b]">峰值 <span className="font-mono text-[#0f172a] font-bold">{fmtNum(max)}</span> tokens</span>
        <span className="text-[#94a3b8] font-mono">统计区间 {dates[0]} ~ {dates[dates.length - 1]}</span>
      </div>
    </div>
  );
};

const StatsSection: React.FC<{ request: AdminRequest }> = ({ request }) => {
  const [stats, setStats] = useState<AdminStats | null>(() => cacheGet<AdminStats>('stats'));
  const [loading, setLoading] = useState(() => !cacheGet<AdminStats>('stats'));
  const [error, setError] = useState('');

  const load = useCallback(async (force = false) => {
    // 命中缓存直接复用,切换分区不重复请求;手动刷新 force 跳过
    if (!force && cacheGet<AdminStats>('stats')) return;
    setLoading(true);
    const res = await request<AdminStats>('/tiwen/admin/stats');
    setLoading(false);
    if (res.ok && res.data) {
      setStats(res.data);
      setError('');
      cacheSet('stats', res.data);
    } else {
      setError(res.message || '统计加载失败');
    }
  }, [request]);

  useEffect(() => { load(); }, [load]);

  if (loading) {
    return (
      <div className="py-24 flex flex-col items-center justify-center text-[#94a3b8] gap-3">
        <Loader2 className="w-6 h-6 animate-spin text-[#2f6bff]" />
        <span className="text-sm">正在加载全域统计数据…</span>
      </div>
    );
  }
  if (error) return <EmptyHint text={error} />;
  if (!stats) return <EmptyHint />;

  const { kpi, daily, models, plans, topMembers } = stats;
  const maxModelTokens = Math.max(1, ...models.map((m) => m.tokens));

  return (
    <div className="space-y-4">
      {/* KPI 指标卡片 */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <KpiTile
          label="会员总数"
          value={fmtNum(kpi.memberTotal)}
          sub={`付费会员 ${kpi.paidMembers} 人`}
          icon={<Users className="w-4 h-4" />}
        />
        <KpiTile
          label="生效订阅"
          value={fmtNum(kpi.activeSubscriptions)}
          sub={`付费率 ${(kpi.memberTotal > 0 ? (kpi.paidMembers / kpi.memberTotal * 100).toFixed(1) : 0)}%`}
          icon={<CreditCard className="w-4 h-4" />}
          accent
        />
        <KpiTile
          label="今日回答次数"
          value={fmtNum(kpi.todayCards)}
          sub={`今日消耗 ${fmtNum(kpi.todayTokens)} tok`}
          icon={<Activity className="w-4 h-4" />}
        />
        <KpiTile
          label="本月 Token 消耗"
          value={fmtNum(kpi.monthTokens)}
          sub={`全域累计 ${fmtNum(kpi.totalTokens)}`}
          icon={<TrendingUp className="w-4 h-4" />}
        />
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
        {/* 每日趋势 */}
        <div className="xl:col-span-2">
          <SectionCard
            title="近 14 天每日 Token 消耗趋势"
            extra={
              <button
                onClick={() => load(true)}
                className="h-8 px-2.5 inline-flex items-center gap-1.5 rounded-lg border border-[#e2e8f0] text-xs font-semibold text-[#64748b] hover:text-[#0f172a] hover:border-[#cbd5e1] focus:outline-none transition-colors"
              >
                <RefreshCw className="w-3.5 h-3.5" />
                刷新
              </button>
            }
          >
            <DailyTrendChart daily={daily} />
          </SectionCard>
        </div>

        {/* 套餐分布 */}
        <SectionCard title="会员套餐分布">
          {plans.length === 0 ? <EmptyHint /> : (
            <div className="space-y-4">
              {plans.map((p, i) => (
                <div key={p.plan}>
                  <div className="flex items-center justify-between text-xs mb-1.5">
                    <span className="font-bold text-[#334155]">{p.name}</span>
                    <span className="font-mono text-[#64748b] tabular-nums">{p.total} 人 · {p.percent}%</span>
                  </div>
                  <div className="h-2 rounded-full bg-[#f1f5f9] overflow-hidden">
                    <div
                      className="h-full rounded-full transition-all"
                      style={{ width: `${Math.max(2, p.percent)}%`, backgroundColor: BREAKDOWN_COLORS[i % BREAKDOWN_COLORS.length] }}
                    />
                  </div>
                </div>
              ))}
              <div className="pt-3 border-t border-[#f1f5f9] flex items-center justify-between text-xs text-[#64748b]">
                <span>累计会话总数</span>
                <span className="font-mono text-[#0f172a] font-bold">{fmtNum(kpi.sessionTotal)}</span>
              </div>
            </div>
          )}
        </SectionCard>
      </div>

      <div className="grid grid-cols-1 xl:grid-cols-3 gap-4">
        {/* 模型消耗 */}
        <div className="xl:col-span-2">
          <SectionCard title="各 AI 模型消耗排行">
            {models.length === 0 ? <EmptyHint /> : (
              <div className="space-y-3.5">
                {models.map((m, i) => (
                  <div key={m.modelId} className="flex items-center gap-3">
                    <span
                      className="w-1.5 h-8 rounded-full shrink-0"
                      style={{ backgroundColor: BREAKDOWN_COLORS[i % BREAKDOWN_COLORS.length] }}
                    />
                    <div className="flex-1 min-w-0">
                      <div className="flex items-center justify-between gap-3 text-xs mb-1">
                        <span className="font-bold text-[#334155] truncate">{m.modelName}</span>
                        <span className="font-mono text-[#64748b] tabular-nums shrink-0">
                          {fmtNum(m.tokens)} tok · {m.asks} 次 · 均 {m.avgDuration}s
                        </span>
                      </div>
                      <div className="h-1.5 rounded-full bg-[#f1f5f9] overflow-hidden">
                        <div
                          className="h-full rounded-full"
                          style={{ width: `${Math.max(1.5, (m.tokens / maxModelTokens) * 100)}%`, backgroundColor: BREAKDOWN_COLORS[i % BREAKDOWN_COLORS.length] }}
                        />
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </SectionCard>
        </div>

        {/* Top 消耗用户 */}
        <SectionCard title="本月 Token 消耗 Top 会员">
          {topMembers.length === 0 ? <EmptyHint text="暂无消耗记录" /> : (
            <div className="divide-y divide-[#f1f5f9]">
              {topMembers.map((u, i) => (
                <div key={u.uid} className="flex items-center gap-3 py-2.5 first:pt-0 last:pb-0">
                  <span className={`flex h-5 w-5 items-center justify-center rounded-md font-mono text-[11px] font-bold shrink-0 ${
                    i === 0 ? 'bg-amber-100 text-amber-800' : i === 1 ? 'bg-slate-200 text-slate-700' : i === 2 ? 'bg-amber-50 text-amber-700' : 'text-[#94a3b8]'
                  }`}>
                    {i + 1}
                  </span>
                  <div className="flex-1 min-w-0">
                    <div className="text-xs font-bold text-[#0f172a] truncate">{u.name}</div>
                    <div className="text-[11px] text-[#94a3b8] truncate">{u.email || `UID ${u.uid}`}</div>
                  </div>
                  <div className="text-right shrink-0">
                    <div className="font-mono text-xs font-bold text-[#0f172a] tabular-nums">{fmtNum(u.tokensUsed)}</div>
                    <div className="text-[10px] text-[#94a3b8]">/ {u.tokensQuota > 0 ? fmtNum(u.tokensQuota) : '∞'}</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </SectionCard>
      </div>
    </div>
  );
};

/* ============ 订阅与价格管理 ============ */

/* ============ 账单明细 ============ */

interface BillingRow {
  id: string;
  type: string;
  typeLabel: string;
  status: string;
  failed: boolean;
  amount: number;
  currency: string;
  plan: string;
  uid: number;
  name: string;
  email: string;
  avatar: string;
  createdAt: string;
}

interface BillingData {
  stripeInstalled: boolean;
  currency?: string;
  currencySymbol?: string;
  kpi: { totalRevenue: number; monthRevenue: number; successCount: number; failedCount: number };
  count: number;
  page: number;
  limit: number;
  data: BillingRow[];
}

const BillingSection: React.FC<{
  request: AdminRequest;
  notify: (msg: string) => void;
}> = ({ request, notify }) => {
  const [data, setData] = useState<BillingData | null>(() => cacheGet<BillingData>('billing:1:'));
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(() => !cacheGet<BillingData>('billing:1:'));
  const limit = 15;

  const load = useCallback(
    async (p = page, force = false) => {
      const cacheKey = `billing:${p}:${search}`;
      // 命中缓存直接复用(同页同关键词);换页/换词/手动刷新才请求
      if (!force) {
        const hit = cacheGet<BillingData>(cacheKey);
        if (hit) {
          setData(hit);
          setLoading(false);
          return;
        }
      }
      setLoading(true);
      const res = await request<BillingData>('/tiwen/admin/billing?' + new URLSearchParams({
        page: String(p),
        limit: String(limit),
        keyword: search,
      }));
      setLoading(false);
      if (res.ok && res.data) {
        setData(res.data);
        cacheSet(cacheKey, res.data);
      } else {
        notify(res.message || '账单加载失败');
      }
    },
    [request, notify, page, search]
  );

  useEffect(() => { load(page); /* eslint-disable-next-line */ }, [page, search]);

  if (loading && !data) {
    return (
      <div className="py-24 flex flex-col items-center justify-center text-[#94a3b8] gap-3">
        <Loader2 className="w-6 h-6 animate-spin text-[#2f6bff]" />
        <span className="text-sm">正在加载账单流水…</span>
      </div>
    );
  }

  if (data && !data.stripeInstalled) {
    return (
      <div className="bg-white rounded-2xl border border-dashed border-[#cbd5e1] shadow-card p-10 text-center space-y-2">
        <CreditCard className="w-8 h-8 text-[#cbd5e1] mx-auto" />
        <p className="text-sm font-bold text-[#0f172a]">支付组件未安装</p>
        <p className="text-xs text-[#8c97af]">安装 stripe 应用并完成配置后,这里将展示订阅收款流水</p>
      </div>
    );
  }

  const kpi = data?.kpi || { totalRevenue: 0, monthRevenue: 0, successCount: 0, failedCount: 0 };
  const curSymbol = data?.currencySymbol || '$';
  const list = data?.data || [];
  const count = data?.count || 0;
  const totalPages = Math.max(1, Math.ceil(count / limit));

  return (
    <div className="space-y-4">
      {/* KPI */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
        <KpiTile label="累计收入" value={`${curSymbol}${kpi.totalRevenue.toLocaleString()}`} sub="Stripe 成功扣款合计" icon={<TrendingUp className="w-4 h-4" />} accent />
        <KpiTile label="本月收入" value={`${curSymbol}${kpi.monthRevenue.toLocaleString()}`} sub="按发票口径统计" icon={<Activity className="w-4 h-4" />} />
        <KpiTile label="成功笔数" value={String(kpi.successCount)} icon={<ShieldCheck className="w-4 h-4" />} />
        <KpiTile label="失败笔数" value={String(kpi.failedCount)} sub={kpi.failedCount > 0 ? '存在扣款失败,建议关注' : undefined} icon={<AlertCircle className="w-4 h-4" />} />
      </div>

      {/* 搜索条 */}
      <div className="flex flex-wrap items-center gap-3 bg-white rounded-2xl border border-[#e2e8f0] shadow-subtle px-4 py-3">
        <form
          className="flex-1 min-w-[220px] relative"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setSearch(keyword.trim()); }}
        >
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-[#94a3b8]" />
          <input
            value={keyword}
            onChange={(e) => setKeyword(e.target.value)}
            placeholder="搜索用户 / 邮箱 / UID / Stripe 单号"
            aria-label="搜索账单"
            className="w-full h-9 pl-9 pr-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none focus:border-[#2f6bff] transition"
          />
        </form>
        <span className="text-xs text-[#94a3b8] font-mono tabular-nums">{count} 笔流水</span>
      </div>

      {/* 流水表 */}
      <div className="bg-white rounded-2xl border border-[#e2e8f0] shadow-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-[#64748b] border-b border-[#f1f5f9] bg-[#f8fafc]">
                <th className="px-4 py-3 font-semibold">时间</th>
                <th className="px-4 py-3 font-semibold">用户</th>
                <th className="px-4 py-3 font-semibold">事项</th>
                <th className="px-4 py-3 font-semibold text-right">金额</th>
                <th className="px-4 py-3 font-semibold">状态</th>
                <th className="px-4 py-3 font-semibold">Stripe 单号</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#f1f5f9]">
              {loading ? (
                <tr>
                  <td colSpan={6} className="px-4 py-16 text-center text-[#94a3b8]">
                    <Loader2 className="w-4 h-4 animate-spin inline mr-2" />
                    加载中…
                  </td>
                </tr>
              ) : list.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-16 text-center text-[#94a3b8]">暂无支付记录</td>
                </tr>
              ) : list.map((row) => (
                <tr key={row.id + row.type} className="hover:bg-[#f8fafc]/70 transition-colors">
                  <td className="px-4 py-3 text-[#8c97af] whitespace-nowrap font-mono">{row.createdAt}</td>
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2.5">
                      <div className="w-7 h-7 rounded-full bg-[#2f6bff]/10 text-[#2f6bff] flex items-center justify-center font-bold text-xs shrink-0">
                        {(row.name || 'U').slice(0, 1).toUpperCase()}
                      </div>
                      <div className="min-w-0">
                        <div className="font-semibold text-[#0f172a] truncate max-w-[160px]">{row.name}</div>
                        <div className="text-[#94a3b8] truncate max-w-[160px]">{row.email || `UID ${row.uid}`}</div>
                      </div>
                    </div>
                  </td>
                  <td className="px-4 py-3">
                    <span className="font-semibold text-[#334155]">{row.typeLabel}</span>
                    {row.plan && (
                      <span className={`ml-1.5 px-1.5 py-px rounded border text-[10px] font-bold ${planBadge(row.plan)}`}>{row.plan.toUpperCase()}</span>
                    )}
                  </td>
                  <td className={`px-4 py-3 text-right font-mono tabular-nums font-bold whitespace-nowrap ${row.failed ? 'text-rose-500 line-through' : 'text-[#0f172a]'}`}>
                    {row.currency === 'USD' ? '$' : row.currency + ' '}{row.amount.toLocaleString()}
                  </td>
                  <td className="px-4 py-3">
                    {row.failed ? (
                      <span className="inline-block px-2 py-0.5 rounded-md border bg-rose-50 text-rose-600 border-rose-200 font-semibold">失败</span>
                    ) : ['complete', 'succeeded', 'paid', 'active'].includes(row.status) ? (
                      <span className="inline-block px-2 py-0.5 rounded-md border bg-emerald-50 text-emerald-700 border-emerald-200 font-semibold">成功</span>
                    ) : (
                      <span className="inline-block px-2 py-0.5 rounded-md border bg-slate-100 text-slate-500 border-slate-200 font-semibold">{row.status || '—'}</span>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <span className="font-mono text-[10px] text-[#94a3b8] truncate block max-w-[140px]" title={row.id}>{row.id}</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        {totalPages > 1 && (
          <div className="flex items-center justify-between px-4 py-3 border-t border-[#f1f5f9]">
            <span className="text-xs text-[#94a3b8] font-mono tabular-nums">第 {page} / {totalPages} 页</span>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="h-8 w-8 rounded-lg border border-[#e2e8f0] flex items-center justify-center text-[#64748b] hover:border-[#cbd5e1] disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none transition-colors"
                aria-label="上一页"
              >
                <ChevronLeft className="w-4 h-4" />
              </button>
              <button
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages}
                className="h-8 w-8 rounded-lg border border-[#e2e8f0] flex items-center justify-center text-[#64748b] hover:border-[#cbd5e1] disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none transition-colors"
                aria-label="下一页"
              >
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

const PLAN_ORDER = ['free', 'pro', 'team', 'flagship'];
const SUB_PLAN_IDS = ['free', 'pro', 'team'];
const ONE_TIME_PLAN_IDS = ['flagship'];

type PlanLang = 'zh' | 'zh-TW' | 'en';
const PLAN_LANG_LABELS: Record<PlanLang, string> = { zh: '简体', 'zh-TW': '繁體', en: 'English' };

interface PlanDef {
  type: 'subscription' | 'one_time';
  name: string;
  price_monthly: number | string;
  price_yearly: number | string;
  daily_asks: number | string;
  tokens_quota: number | string;
  enabled: boolean;
  /** 默认语言(zh)权益行文本,一行一条 */
  features: string;
  /** 一次性包:售价/到账积分/加赠比例/积分有效期(月)/赠送Pro(月)/会员每月加赠 */
  price: number | string;
  credits: number | string;
  bonus_percent: number | string;
  validity_months: number | string;
  gift_pro_months: number | string;
  gift_monthly_credits: number | string;
  /** 多语言名称:lang → 文本(zh 缺失时回落 name) */
  name_i18n: Record<PlanLang, string>;
  /** 多语言权益:lang → 多行文本(zh 缺失时回落 features) */
  features_i18n: Record<PlanLang, string>;
}

type PlansMap = Record<string, PlanDef>;

/** 后端套餐形状 → 编辑态:多语言缺失回落单语字段,保证三语编辑器始终有值 */
function normalizePlan(id: string, raw: Record<string, any>): PlanDef {
  const featuresOf = (lines: unknown): string =>
    Array.isArray(lines) ? lines.join('\n') : String(lines ?? '');
  const name = String(raw.name ?? '');
  const rawNameI18n = (raw.name_i18n && typeof raw.name_i18n === 'object') ? raw.name_i18n : {};
  const rawFeatI18n = (raw.features_i18n && typeof raw.features_i18n === 'object') ? raw.features_i18n : {};
  const rawFeatures = featuresOf(raw.features);
  return {
    type: raw.type === 'one_time' ? 'one_time' : 'subscription',
    name,
    price_monthly: raw.price_monthly ?? 0,
    price_yearly: raw.price_yearly ?? 0,
    daily_asks: raw.daily_asks ?? 0,
    tokens_quota: raw.tokens_quota ?? 0,
    enabled: id === 'free' ? true : raw.enabled !== false,
    features: rawFeatures,
    price: raw.price ?? 0,
    credits: raw.credits ?? 0,
    bonus_percent: raw.bonus_percent ?? 0,
    validity_months: raw.validity_months ?? 0,
    gift_pro_months: raw.gift_pro_months ?? 0,
    gift_monthly_credits: raw.gift_monthly_credits ?? 0,
    name_i18n: {
      zh: String(rawNameI18n.zh ?? '') || name,
      'zh-TW': String(rawNameI18n['zh-TW'] ?? ''),
      en: String(rawNameI18n.en ?? ''),
    },
    features_i18n: {
      zh: featuresOf(rawFeatI18n.zh) || rawFeatures,
      'zh-TW': featuresOf(rawFeatI18n['zh-TW']),
      en: featuresOf(rawFeatI18n.en),
    },
  };
}

const PlansSection: React.FC<{
  request: AdminRequest;
  notify: (msg: string) => void;
}> = ({ request, notify }) => {
  const [plans, setPlans] = useState<PlansMap>(() => cacheGet<PlansMap>('plans') ?? {});
  const [currency, setCurrency] = useState<string>(() => cacheGet<string>('currency') ?? 'USD');
  const [planLang, setPlanLang] = useState<PlanLang>('zh');
  const [loading, setLoading] = useState(() => !cacheGet<PlansMap>('plans'));
  const [saving, setSaving] = useState(false);

  const load = useCallback(async (force = false) => {
    // 命中缓存直接复用;保存成功或手动刷新 force 跳过
    if (!force) {
      const hit = cacheGet<PlansMap>('plans');
      if (hit) {
        setPlans(hit);
        setCurrency(cacheGet<string>('currency') ?? 'USD');
        setLoading(false);
        return;
      }
    }
    setLoading(true);
    const res = await request<{ plans: Record<string, any>; currency?: string }>('/tiwen/admin/plans');
    setLoading(false);
    if (res.ok && res.data) {
      const next: PlansMap = {};
      for (const [id, raw] of Object.entries(res.data.plans || {})) {
        next[id] = normalizePlan(id, raw);
      }
      setPlans(next);
      cacheSet('plans', next);
      const cur = (res.data.currency || 'USD').toUpperCase();
      setCurrency(cur);
      cacheSet('currency', cur);
    } else {
      notify(res.message || '套餐加载失败');
    }
  }, [request, notify]);

  useEffect(() => { load(); }, [load]);

  /** 数字输入规整:decimal 允许两位小数,int 仅正整数 */
  const sanitize = (field: string, value: string): string => {
    if (field === 'price' || field === 'price_monthly' || field === 'price_yearly' || field === 'credits') {
      let s = String(value).replace(/[^\d.]/g, '');
      const parts = s.split('.');
      if (parts.length > 2) s = parts[0] + '.' + parts.slice(1).join('');
      if (parts.length === 2 && parts[1].length > 2) s = parts[0] + '.' + parts[1].slice(0, 2);
      return s;
    }
    return String(value).replace(/\D/g, '');
  };

  const update = (id: string, field: keyof PlanDef, value: string | boolean) => {
    setPlans((prev) => {
      let finalVal: any = value;
      if (typeof value === 'string' && field !== 'name') {
        finalVal = sanitize(String(field), value);
      } else if (field === 'enabled') {
        finalVal = Boolean(value);
      }
      return { ...prev, [id]: { ...prev[id], [field]: finalVal } };
    });
  };

  const updateI18n = (id: string, kind: 'name_i18n' | 'features_i18n', lang: PlanLang, value: string) => {
    setPlans((prev) => ({
      ...prev,
      [id]: { ...prev[id], [kind]: { ...prev[id][kind], [lang]: value } },
    }));
  };

  const save = async () => {
    setSaving(true);
    const num = (v: number | string, integer = true): number => {
      const n = Number(v);
      if (!isFinite(n) || n < 0) return 0;
      return integer ? Math.round(n) : Math.round(n * 100) / 100;
    };
    const payload = Object.fromEntries(
      Object.entries(plans).map(([id, p]) => {
        // zh 视为默认语言:与 name/features 单语字段保持同步(旧前台/接口回落用)
        const row: Record<string, unknown> = {
          type: p.type,
          name: p.name_i18n.zh.trim(),
          tokens_quota: num(p.tokens_quota),
          enabled: p.enabled,
          features: p.features_i18n.zh.split('\n'),
          name_i18n: Object.fromEntries(
            Object.entries(p.name_i18n).map(([lang, text]) => [lang, text.trim()])
          ),
          features_i18n: Object.fromEntries(
            Object.entries(p.features_i18n).map(([lang, text]) => [lang, text.split('\n')])
          ),
        };
        if (p.type === 'one_time') {
          row.price = num(p.price, false);
          row.credits = num(p.credits);
          row.bonus_percent = num(p.bonus_percent);
          row.validity_months = num(p.validity_months);
          row.gift_pro_months = num(p.gift_pro_months);
          row.gift_monthly_credits = num(p.gift_monthly_credits);
        } else {
          row.price_monthly = num(p.price_monthly, false);
          row.price_yearly = num(p.price_yearly, false);
          row.daily_asks = num(p.daily_asks);
        }
        return [id, row];
      })
    );
    const res = await request('/tiwen/admin/plansSave', { method: 'POST', json: { plans: payload, currency } });
    setSaving(false);
    if (res.ok) {
      // 服务端为准:拉回最新定义并刷新缓存
      load(true);
    }
    notify(res.ok ? '套餐已保存，新订单即刻按新价格与币种计费' : (res.message || '保存失败'));
  };

  if (loading) {
    return (
      <div className="py-24 flex flex-col items-center justify-center text-[#94a3b8] gap-3">
        <Loader2 className="w-6 h-6 animate-spin text-[#2f6bff]" />
        <span className="text-sm">正在加载套餐定价策略…</span>
      </div>
    );
  }

  /** 名称/权益多语言编辑器(语言由顶部 planLang Tab 统一切换) */
  const i18nEditors = (id: string, p: PlanDef) => (
    <>
      <div className="space-y-1">
        <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-name-${id}-${planLang}`}>
          套餐显示名称（{PLAN_LANG_LABELS[planLang]}）
        </label>
        <input
          id={`plan-name-${id}-${planLang}`}
          value={p.name_i18n[planLang]}
          onChange={(e) => updateI18n(id, 'name_i18n', planLang, e.target.value)}
          placeholder={planLang === 'zh' ? '' : `${PLAN_LANG_LABELS[planLang]}名称，留空回落默认`}
          className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
        />
      </div>
      <div className="space-y-1">
        <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-features-${id}-${planLang}`}>
          售卖页权益（{PLAN_LANG_LABELS[planLang]}，一行一条）
        </label>
        <textarea
          id={`plan-features-${id}-${planLang}`}
          rows={6}
          value={p.features_i18n[planLang]}
          onChange={(e) => updateI18n(id, 'features_i18n', planLang, e.target.value)}
          placeholder={'例：18 个顶尖模型全解锁\n最多 6 模型同时并发对比\n留空则沿用默认文案'}
          className="w-full px-3 py-2.5 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-xs leading-relaxed text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition resize-y"
        />
        <p className="text-[10px] text-[#94a3b8]">
          {p.features_i18n[planLang] ? p.features_i18n[planLang].split('\n').filter((l) => l.trim() !== '').length + ' 条权益' : '未配置，前台使用默认文案'}
        </p>
      </div>
    </>
  );

  /** 顶部开关与徽标 */
  const cardHeader = (id: string, p: PlanDef, locked = false) => (
    <div className="flex items-center justify-between">
      <div className="flex items-center gap-2">
        <span className={`px-2.5 py-1 rounded-lg border text-xs font-bold ${planBadge(id)}`}>
          {id.toUpperCase()}
        </span>
        <span className="px-2 py-0.5 rounded-md bg-[#f1f5f9] text-[#64748b] border border-[#e2e8f0] text-[10px] font-bold">
          {p.type === 'one_time' ? '一次性包' : '订阅'}
        </span>
        {!p.enabled && (
          <span className="px-2 py-0.5 rounded-md bg-slate-100 text-slate-500 border border-slate-200 text-[10px] font-bold">
            已下架
          </span>
        )}
      </div>
      {locked ? (
        <span className="text-xs font-semibold text-[#94a3b8]">默认套餐 · 恒开启</span>
      ) : (
        <div className="flex items-center gap-2">
          <span className="text-xs font-semibold text-[#94a3b8]">{p.enabled ? '售卖中' : '已停售'}</span>
          <button
            type="button"
            role="switch"
            aria-checked={p.enabled}
            aria-label={`${id} 套餐开关`}
            onClick={() => update(id, 'enabled', !p.enabled)}
            className="relative w-10 h-[22px] rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-[#2f6bff]/40"
            style={{ backgroundColor: p.enabled ? '#2f6bff' : '#cbd5e1' }}
          >
            <span
              className="absolute top-[3px] left-[3px] w-4 h-4 bg-white rounded-full shadow transition-transform"
              style={{ transform: p.enabled ? 'translateX(18px)' : 'translateX(0)' }}
            />
          </button>
        </div>
      )}
    </div>
  );

  return (
    <div className="space-y-4">
      {/* 提示与保存栏 */}
      <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white rounded-2xl border border-[#e2e8f0] shadow-card p-4">
        <div className="min-w-0">
          <h4 className="text-xs font-bold text-[#0f172a]">即时生效定价策略</h4>
          <p className="text-xs text-[#64748b] mt-0.5">
            修改保存后即时生效：前端售卖页与 Stripe 新订单按新价格与币种计费，已生效订阅保持既有周期。名称与权益支持三语，前台按访客语言展示。
          </p>
        </div>
        <div className="flex flex-wrap items-center gap-3 shrink-0">
          <div className="flex items-center rounded-xl bg-[#f1f5f9] p-1" role="tablist" aria-label="套餐语言">
            {(Object.keys(PLAN_LANG_LABELS) as PlanLang[]).map((l) => (
              <button
                key={l}
                role="tab"
                aria-selected={l === planLang}
                onClick={() => setPlanLang(l)}
                className={`h-7 px-2.5 rounded-lg text-xs font-bold transition-all ${
                  l === planLang ? 'bg-white text-[#2f6bff] shadow-sm' : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
              >
                {PLAN_LANG_LABELS[l]}
              </button>
            ))}
          </div>
          <label className="flex items-center gap-2 text-xs font-bold text-[#334155]">
            结算货币
            <select
              value={currency}
              onChange={(e) => setCurrency(e.target.value)}
              aria-label="结算货币"
              className="h-9 px-2.5 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
            >
              {['USD', 'EUR', 'GBP', 'CNY', 'JPY', 'HKD', 'TWD', 'SGD', 'AUD', 'CAD', 'NZD', 'CHF', 'KRW', 'INR', 'THB'].map((c) => (
                <option key={c} value={c}>{c} ({currencySymbolOf(c).trim()})</option>
              ))}
            </select>
          </label>
          <button
            onClick={save}
            disabled={saving}
            className="h-9 px-4 inline-flex items-center justify-center gap-2 rounded-xl bg-[#2f6bff] text-white text-xs font-bold hover:bg-[#1e4fd6] shadow-sm disabled:opacity-60 transition-all"
          >
            {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
            {saving ? '保存中…' : '保存全部配置'}
          </button>
        </div>
      </div>

      {/* ── 订阅套餐 ── */}
      <div className="flex items-center gap-2 pt-1">
        <CreditCard className="w-4 h-4 text-[#2f6bff]" />
        <h3 className="text-sm font-bold text-[#0f172a]">订阅套餐</h3>
        <span className="text-xs text-[#94a3b8]">月付 / 年付周期订阅</span>
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        {SUB_PLAN_IDS.filter((id) => plans[id]).map((id) => {
          const p = plans[id];
          const isFree = id === 'free';
          return (
            <div
              key={id}
              className={`bg-white rounded-2xl border shadow-card p-4 space-y-4 transition-opacity ${
                p.enabled ? 'border-[#e2e8f0]' : 'border-dashed border-[#cbd5e1] opacity-70'
              }`}
            >
              {cardHeader(id, p, isFree)}

              <div className="space-y-3">
                {i18nEditors(id, p)}

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-month-${id}`}>
                      月付价格 ({currencySymbolOf(currency).trim()})
                    </label>
                    <input
                      id={`plan-month-${id}`}
                      inputMode="decimal"
                      disabled={isFree}
                      value={p.price_monthly}
                      onChange={(e) => update(id, 'price_monthly', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition disabled:text-[#94a3b8] disabled:bg-[#f1f5f9]"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-year-${id}`}>
                      年付价格 ({currencySymbolOf(currency).trim()})
                    </label>
                    <input
                      id={`plan-year-${id}`}
                      inputMode="decimal"
                      disabled={isFree}
                      value={p.price_yearly}
                      onChange={(e) => update(id, 'price_yearly', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition disabled:text-[#94a3b8] disabled:bg-[#f1f5f9]"
                    />
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-asks-${id}`}>
                      每日限次
                    </label>
                    <input
                      id={`plan-asks-${id}`}
                      inputMode="numeric"
                      value={p.daily_asks}
                      onChange={(e) => update(id, 'daily_asks', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                    <p className="text-[10px] text-[#94a3b8]">0 = 无限制</p>
                  </div>
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-quota-${id}`}>
                      月度 Token 配额
                    </label>
                    <input
                      id={`plan-quota-${id}`}
                      inputMode="numeric"
                      value={p.tokens_quota}
                      onChange={(e) => update(id, 'tokens_quota', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                    <p className="text-[10px] text-[#94a3b8]">
                      {isFree ? '免费版不按配额计' : `${fmtNum(Number(p.tokens_quota) || 0)} / 月`}
                    </p>
                  </div>
                </div>
              </div>
            </div>
          );
        })}
      </div>

      {/* ── 一次性包 ── */}
      <div className="flex items-center gap-2 pt-2">
        <Sparkles className="w-4 h-4 text-[#2f6bff]" />
        <h3 className="text-sm font-bold text-[#0f172a]">一次性包</h3>
        <span className="text-xs text-[#94a3b8]">单笔买断 · 积分到账 · 可与订阅并存</span>
      </div>
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {ONE_TIME_PLAN_IDS.filter((id) => plans[id]).map((id) => {
          const p = plans[id];
          return (
            <div
              key={id}
              className={`bg-white rounded-2xl border shadow-card p-4 space-y-4 transition-opacity ${
                p.enabled ? 'border-[#e2e8f0]' : 'border-dashed border-[#cbd5e1] opacity-70'
              }`}
            >
              {cardHeader(id, p)}

              <div className="space-y-3">
                {i18nEditors(id, p)}

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-price-${id}`}>
                      一次性售价 ({currencySymbolOf(currency).trim()})
                    </label>
                    <input
                      id={`plan-price-${id}`}
                      inputMode="decimal"
                      value={p.price}
                      onChange={(e) => update(id, 'price', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-credits-${id}`}>
                      到账积分
                    </label>
                    <input
                      id={`plan-credits-${id}`}
                      inputMode="numeric"
                      value={p.credits}
                      onChange={(e) => update(id, 'credits', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                    <p className="text-[10px] text-[#94a3b8]">按美元计的积分面值</p>
                  </div>
                </div>

                <div className="grid grid-cols-3 gap-3">
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-bonus-${id}`}>
                      加赠比例 (%)
                    </label>
                    <input
                      id={`plan-bonus-${id}`}
                      inputMode="numeric"
                      value={p.bonus_percent}
                      onChange={(e) => update(id, 'bonus_percent', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                    <p className="text-[10px] text-[#94a3b8]">
                      合计 {fmtNum(Math.round((Number(p.credits) || 0) * (1 + (Number(p.bonus_percent) || 0) / 100)))} 积分
                    </p>
                  </div>
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-validity-${id}`}>
                      积分有效期 (月)
                    </label>
                    <input
                      id={`plan-validity-${id}`}
                      inputMode="numeric"
                      value={p.validity_months}
                      onChange={(e) => update(id, 'validity_months', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-gift-${id}`}>
                      赠送 Pro (月)
                    </label>
                    <input
                      id={`plan-gift-${id}`}
                      inputMode="numeric"
                      value={p.gift_pro_months}
                      onChange={(e) => update(id, 'gift_pro_months', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                    <p className="text-[10px] text-[#94a3b8]">会员期内享套餐算力</p>
                  </div>
                </div>

                <div className="grid grid-cols-2 gap-3">
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-gift-monthly-${id}`}>
                      会员每月加赠积分
                    </label>
                    <input
                      id={`plan-gift-monthly-${id}`}
                      inputMode="numeric"
                      value={p.gift_monthly_credits}
                      onChange={(e) => update(id, 'gift_monthly_credits', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                  </div>
                  <div className="space-y-1">
                    <label className="block text-xs font-bold text-[#334155]" htmlFor={`plan-quota-${id}`}>
                      月度 Token 配额
                    </label>
                    <input
                      id={`plan-quota-${id}`}
                      inputMode="numeric"
                      value={p.tokens_quota}
                      onChange={(e) => update(id, 'tokens_quota', e.target.value)}
                      className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
                    />
                    <p className="text-[10px] text-[#94a3b8]">会员期内每月 {fmtNum(Number(p.tokens_quota) || 0)} tokens</p>
                  </div>
                </div>
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
};

/* ============ 会员管理 ============ */

const MemberEditModal: React.FC<{
  member: AdminMember;
  submitting: boolean;
  onCancel: () => void;
  onSubmit: (payload: { plan: string; tokens_quota: number | null; reset_usage: boolean }) => void;
}> = ({ member, submitting, onCancel, onSubmit }) => {
  const [plan, setPlan] = useState(member.plan);
  const [quota, setQuota] = useState<string>(String(member.tokensQuota));
  const [resetUsage, setResetUsage] = useState(false);
  const quotaPercent = member.tokensQuota > 0 ? Math.min(100, Math.round((member.tokensUsed / member.tokensQuota) * 100)) : 0;

  return (
    <div className="fixed inset-0 z-[200] flex items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="调整会员">
      <div className="absolute inset-0 bg-[#070d1e]/50 backdrop-blur-sm" onClick={submitting ? undefined : onCancel} />
      <div className="relative w-full max-w-md bg-white rounded-2xl border border-[#e2e8f0] shadow-lg p-5 sm:p-6 space-y-4">
        <div className="flex items-center gap-3">
          <div className="w-10 h-10 rounded-2xl bg-blue-50 text-[#2f6bff] flex items-center justify-center font-bold text-sm shrink-0">
            {(member.name || 'U').slice(0, 1).toUpperCase()}
          </div>
          <div className="min-w-0">
            <div className="text-sm font-bold text-[#0f172a] truncate">{member.name}</div>
            <div className="text-xs text-[#94a3b8] truncate">{member.email || `UID ${member.uid}`}</div>
          </div>
          <span className={`ml-auto px-2.5 py-1 rounded-lg border text-xs font-bold shrink-0 ${planBadge(member.plan)}`}>
            {member.plan.toUpperCase()}
          </span>
        </div>

        <div className="rounded-xl bg-[#f8fafc] border border-[#f1f5f9] p-3 flex items-center justify-between text-xs">
          <span className="text-[#64748b]">本月已消耗</span>
          <span className="font-mono text-[#0f172a] tabular-nums font-bold">
            {fmtNum(member.tokensUsed)} / {fmtNum(member.tokensQuota)} tokens ({quotaPercent}%)
          </span>
        </div>

        <div className="space-y-1.5">
          <label className="block text-xs font-bold text-[#334155]" htmlFor="member-plan">
            调整套餐级别
          </label>
          <div className="grid grid-cols-2 sm:grid-cols-4 gap-2">
            {PLAN_ORDER.map((p) => (
              <button
                key={p}
                type="button"
                onClick={() => setPlan(p)}
                className={`h-9 rounded-xl border text-xs font-bold transition-colors ${
                  plan === p
                    ? 'bg-blue-50 border-[#2f6bff] text-[#2f6bff]'
                    : 'bg-white border-[#e2e8f0] text-[#64748b] hover:border-[#cbd5e1]'
                }`}
              >
                {p.toUpperCase()}
              </button>
            ))}
          </div>
          <p className="text-[10px] text-[#94a3b8]">切换套餐将继承该套餐的默认配额，可单独在此自定义</p>
        </div>

        <div className="space-y-1.5">
          <label className="block text-xs font-bold text-[#334155]" htmlFor="member-quota">
            月度 Token 配额自定义
          </label>
          <input
            id="member-quota"
            inputMode="numeric"
            value={quota}
            onChange={(e) => setQuota(e.target.value.replace(/[^\d]/g, ''))}
            placeholder="留空按套餐默认"
            className="w-full h-9 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-sm text-[#0f172a] tabular-nums focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
          />
        </div>

        <label className="flex items-center gap-2.5 text-xs font-medium text-[#334155] cursor-pointer select-none">
          <input
            type="checkbox"
            checked={resetUsage}
            onChange={(e) => setResetUsage(e.target.checked)}
            className="w-4 h-4 rounded border-[#cbd5e1] text-[#2f6bff] accent-[#2f6bff]"
          />
          重置本月 Token 已用量与今日提问次数
        </label>

        <div className="flex gap-3 pt-2">
          <button
            type="button"
            onClick={onCancel}
            disabled={submitting}
            className="flex-1 h-9 rounded-xl border border-[#e2e8f0] text-xs font-bold text-[#64748b] hover:bg-[#f8fafc] transition-colors"
          >
            取消
          </button>
          <button
            type="button"
            onClick={() => onSubmit({ plan, tokens_quota: quota === '' ? null : Number(quota), reset_usage: resetUsage })}
            disabled={submitting}
            className="flex-1 h-9 rounded-xl bg-[#2f6bff] text-white text-xs font-bold hover:bg-[#1e4fd6] shadow-sm disabled:opacity-60 transition-all inline-flex items-center justify-center gap-2"
          >
            {submitting && <Loader2 className="w-3.5 h-3.5 animate-spin" />}
            确认并保存
          </button>
        </div>
      </div>
    </div>
  );
};

/* ============ 会员提问记录抽屉 ============ */

interface MemberRoundCard {
  modelId: string;
  modelName: string;
  tokens: number;
  duration: number;
  checked: boolean;
  failed: boolean;
}

interface MemberRound {
  id: number;
  sessionId: number;
  sessionTitle: string;
  roundIndex: number;
  question: string;
  tag: string;
  createdAt: string;
  tokens: number;
  cards: MemberRoundCard[];
}

interface MemberRecordsData {
  stats: { sessions: number; rounds: number; tokens: number };
  count: number;
  page: number;
  limit: number;
  data: MemberRound[];
}

const MemberRecordsModal: React.FC<{
  member: AdminMember;
  request: AdminRequest;
  onClose: () => void;
}> = ({ member, request, onClose }) => {
  const [data, setData] = useState<MemberRecordsData | null>(null);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const limit = 10;

  const load = useCallback(async () => {
    setLoading(true);
    const res = await request<MemberRecordsData>('/tiwen/admin/memberRecords?' + new URLSearchParams({
      uid: String(member.uid),
      page: String(page),
      limit: String(limit),
      keyword: search,
    }));
    setLoading(false);
    if (res.ok && res.data) {
      setData(res.data);
    }
  }, [request, member.uid, page, search]);

  useEffect(() => { load(); }, [load]);

  // Esc 关闭抽屉
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose(); };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [onClose]);

  const totalPages = Math.max(1, Math.ceil((data?.count || 0) / limit));

  return (
    <div className="fixed inset-0 z-[200] flex justify-end" role="dialog" aria-modal="true" aria-label="会员提问记录">
      <div className="absolute inset-0 bg-[#070d1e]/50 backdrop-blur-sm" onClick={onClose} />
      <div className="relative w-full max-w-xl bg-[#f5f7fb] h-full flex flex-col shadow-2xl border-l border-[#e2e8f0]">
        {/* 头部:会员信息 + 用量概览 */}
        <div className="bg-white border-b border-[#e2e8f0] px-5 py-4 space-y-3 shrink-0">
          <div className="flex items-center gap-3">
            <div className="w-9 h-9 rounded-2xl bg-blue-50 text-[#2f6bff] flex items-center justify-center font-bold text-sm shrink-0">
              {(member.name || 'U').slice(0, 1).toUpperCase()}
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2">
                <span className="text-sm font-bold text-[#0f172a] truncate">{member.name}</span>
                <span className={`px-2 py-0.5 rounded-lg border text-[10px] font-bold shrink-0 ${planBadge(member.plan)}`}>
                  {member.plan.toUpperCase()}
                </span>
              </div>
              <div className="text-xs text-[#94a3b8] truncate">{member.email || `UID ${member.uid}`}</div>
            </div>
            <button
              onClick={onClose}
              className="h-8 w-8 rounded-lg text-[#64748b] hover:bg-[#f1f5f9] flex items-center justify-center transition-colors"
              aria-label="关闭"
            >
              <X className="w-4 h-4" />
            </button>
          </div>

          <div className="grid grid-cols-3 gap-2">
            <div className="rounded-xl bg-[#f8fafc] border border-[#f1f5f9] px-3 py-2">
              <div className="text-[10px] font-semibold text-[#94a3b8]">会话数</div>
              <div className="font-mono text-sm font-bold text-[#0f172a] tabular-nums">{fmtNum(data?.stats.sessions ?? 0)}</div>
            </div>
            <div className="rounded-xl bg-[#f8fafc] border border-[#f1f5f9] px-3 py-2">
              <div className="text-[10px] font-semibold text-[#94a3b8]">提问轮次</div>
              <div className="font-mono text-sm font-bold text-[#0f172a] tabular-nums">{fmtNum(data?.stats.rounds ?? 0)}</div>
            </div>
            <div className="rounded-xl bg-[#f8fafc] border border-[#f1f5f9] px-3 py-2">
              <div className="text-[10px] font-semibold text-[#94a3b8]">累计 Token</div>
              <div className="font-mono text-sm font-bold text-[#2f6bff] tabular-nums">{fmtNum(data?.stats.tokens ?? 0)}</div>
            </div>
          </div>

          {/* 问题关键词搜索 */}
          <form
            className="relative"
            onSubmit={(e) => { e.preventDefault(); setPage(1); setSearch(keyword.trim()); }}
          >
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-[#94a3b8]" />
            <input
              value={keyword}
              onChange={(e) => setKeyword(e.target.value)}
              placeholder="搜索问题关键词…"
              aria-label="搜索提问内容"
              className="w-full h-9 pl-9 pr-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-xs text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
            />
          </form>
        </div>

        {/* 轮次列表 */}
        <div className="flex-1 overflow-y-auto p-4 space-y-3">
          {loading ? (
            <div className="py-20 flex flex-col items-center justify-center text-[#94a3b8] gap-3">
              <Loader2 className="w-5 h-5 animate-spin text-[#2f6bff]" />
              <span className="text-xs">正在加载提问记录…</span>
            </div>
          ) : (data?.data.length ?? 0) === 0 ? (
            <div className="py-20 text-center text-xs text-[#94a3b8]">
              {search ? '没有匹配的提问记录' : '该会员还没有提问记录'}
            </div>
          ) : data!.data.map((r) => (
            <div key={r.id} className="bg-white rounded-2xl border border-[#e2e8f0] shadow-subtle p-3.5 space-y-2.5">
              <div className="flex items-center gap-2 text-[11px]">
                <span className={`px-1.5 py-0.5 rounded-md border text-[10px] font-bold shrink-0 ${
                  r.tag === '追问' ? 'bg-violet-50 text-violet-700 border-violet-200' : 'bg-blue-50 text-[#2f6bff] border-blue-200'
                }`}>
                  {r.tag}
                </span>
                <span className="text-[#94a3b8] truncate min-w-0" title={r.sessionTitle}>{r.sessionTitle}</span>
                <span className="ml-auto shrink-0 font-mono text-[#94a3b8]">{r.createdAt.slice(0, 16)}</span>
              </div>

              <p className="text-xs leading-relaxed text-[#0f172a] font-medium line-clamp-3" title={r.question}>
                {r.question || '(空问题)'}
              </p>

              {r.cards.length > 0 && (
                <div className="flex flex-wrap gap-1.5 pt-0.5">
                  {r.cards.map((c, i) => (
                    <span
                      key={i}
                      title={`${c.modelName} · ${c.tokens} tok · ${c.duration}s${c.failed ? ' · 失败' : ''}`}
                      className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-lg border text-[10px] font-semibold ${
                        c.failed
                          ? 'bg-rose-50 text-rose-600 border-rose-200'
                          : 'bg-[#f8fafc] text-[#475569] border-[#e2e8f0]'
                      }`}
                    >
                      {c.failed ? '✕' : '✓'}
                      <span className="max-w-[120px] truncate">{c.modelName}</span>
                      {!c.failed && <span className="font-mono text-[#94a3b8]">{fmtNum(c.tokens)} tok</span>}
                    </span>
                  ))}
                </div>
              )}

              <div className="flex items-center justify-between pt-0.5 border-t border-[#f1f5f9] text-[10px] text-[#94a3b8]">
                <span>第 {r.roundIndex + 1} 轮 · {r.cards.length} 个模型</span>
                <span className="font-mono tabular-nums">消耗 {fmtNum(r.tokens)} tok</span>
              </div>
            </div>
          ))}
        </div>

        {/* 分页 */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between px-4 py-3 bg-white border-t border-[#f1f5f9] shrink-0">
            <span className="text-xs text-[#94a3b8] font-mono tabular-nums">
              第 {page} / {totalPages} 页 · 共 {data?.count ?? 0} 条
            </span>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="h-8 w-8 rounded-lg border border-[#e2e8f0] flex items-center justify-center text-[#64748b] hover:border-[#cbd5e1] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                aria-label="上一页"
              >
                <ChevronLeft className="w-4 h-4" />
              </button>
              <button
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages}
                className="h-8 w-8 rounded-lg border border-[#e2e8f0] flex items-center justify-center text-[#64748b] hover:border-[#cbd5e1] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                aria-label="下一页"
              >
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

const MembersSection: React.FC<{
  request: AdminRequest;
  notify: (msg: string) => void;
}> = ({ request, notify }) => {  const initial = useRef(cacheGet<{ list: AdminMember[]; count: number }>('members:1::'));
  const [list, setList] = useState<AdminMember[]>(() => initial.current?.list ?? []);
  const [count, setCount] = useState(() => initial.current?.count ?? 0);
  const [page, setPage] = useState(1);
  const [keyword, setKeyword] = useState('');
  const [search, setSearch] = useState('');
  const [planFilter, setPlanFilter] = useState('');
  const [loading, setLoading] = useState(() => !initial.current);
  const [editing, setEditing] = useState<AdminMember | null>(null);
  const [viewing, setViewing] = useState<AdminMember | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const limit = 15;

  const load = useCallback(async (p = page, force = false) => {
    const cacheKey = `members:${p}:${search}:${planFilter}`;
    // 命中缓存直接复用(同页同词同筛选);调整会员后按前缀失效再强制拉取
    if (!force) {
      const hit = cacheGet<{ list: AdminMember[]; count: number }>(cacheKey);
      if (hit) {
        setList(hit.list);
        setCount(hit.count);
        setLoading(false);
        return;
      }
    }
    setLoading(true);
    const res = await request<{ count: number; data: AdminMember[] }>('/tiwen/admin/members?' + new URLSearchParams({
      page: String(p),
      limit: String(limit),
      keyword: search,
      plan: planFilter,
    }));
    setLoading(false);
    if (res.ok && res.data) {
      setList(res.data.data || []);
      setCount(res.data.count || 0);
      cacheSet(cacheKey, { list: res.data.data || [], count: res.data.count || 0 });
    } else {
      notify(res.message || '会员列表加载失败');
    }
  }, [request, notify, page, search, planFilter]);

  useEffect(() => { load(page); /* eslint-disable-next-line */ }, [page, search, planFilter]);

  const totalPages = Math.max(1, Math.ceil(count / limit));

  const submitEdit = async (payload: { plan: string; tokens_quota: number | null; reset_usage: boolean }) => {
    if (!editing) return;
    setSubmitting(true);
    const body: Record<string, unknown> = { uid: editing.uid, plan: payload.plan, reset_usage: payload.reset_usage };
    if (payload.tokens_quota !== null) body.tokens_quota = payload.tokens_quota;
    const res = await request('/tiwen/admin/memberSave', { method: 'POST', json: body });
    setSubmitting(false);
    if (res.ok) {
      notify('会员配置已更新');
      setEditing(null);
      cacheDrop('members');
      load(page, true);
    } else {
      notify(res.message || '更新失败');
    }
  };

  return (
    <div className="space-y-4">
      {/* 搜索与筛选工具栏 - 边距 p-4 规范化 */}
      <div className="bg-white rounded-2xl border border-[#e2e8f0] shadow-card p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <form
          className="flex-1 min-w-[200px] max-w-md relative"
          onSubmit={(e) => { e.preventDefault(); setPage(1); setSearch(keyword.trim()); }}
        >
          <Search className="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-[#94a3b8]" />
          <input
            value={keyword}
            onChange={(e) => setKeyword(e.target.value)}
            placeholder="搜索会员昵称 / 邮箱 / UID..."
            aria-label="搜索会员"
            className="w-full h-10 pl-10 pr-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
          />
        </form>

        <div className="flex flex-wrap items-center justify-between sm:justify-end gap-2.5">
          <div className="flex items-center gap-1.5" role="group" aria-label="套餐筛选">
            {[['', '全部'], ...PLAN_ORDER.map((p) => [p, p.toUpperCase()])].map(([value, label]) => (
              <button
                key={value}
                type="button"
                onClick={() => { setPlanFilter(value); setPage(1); }}
                className={`h-9 px-3.5 rounded-xl border text-xs font-bold transition-colors ${
                  planFilter === value
                    ? 'bg-blue-50 border-[#2f6bff] text-[#2f6bff]'
                    : 'bg-white border-[#e2e8f0] text-[#64748b] hover:border-[#cbd5e1]'
                }`}
              >
                {label}
              </button>
            ))}
          </div>
          <span className="text-xs text-[#94a3b8] font-mono tabular-nums">共 {count} 位会员</span>
        </div>
      </div>

      {/* 会员数据列表 */}
      <div className="bg-white rounded-2xl border border-[#e2e8f0] shadow-card overflow-hidden">
        <div className="overflow-x-auto">
          <table className="w-full text-xs">
            <thead>
              <tr className="text-left text-[#64748b] border-b border-[#f1f5f9] bg-[#f8fafc]">
                <th className="px-4 py-3.5 font-bold">会员信息</th>
                <th className="px-4 py-3.5 font-bold">当前套餐</th>
                <th className="px-4 py-3.5 font-bold">本月用量进度</th>
                <th className="px-4 py-3.5 font-bold">订阅状态</th>
                <th className="px-4 py-3.5 font-bold">注册时间</th>
                <th className="px-4 py-3.5 font-bold text-right">操作</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#f1f5f9]">
              {loading ? (
                <tr>
                  <td colSpan={6} className="px-4 py-16 text-center text-[#94a3b8]">
                    <Loader2 className="w-5 h-5 animate-spin inline mr-2 text-[#2f6bff]" />
                    加载会员列表中…
                  </td>
                </tr>
              ) : list.length === 0 ? (
                <tr>
                  <td colSpan={6} className="px-4 py-16 text-center text-[#94a3b8]">没有符合条件的会员记录</td>
                </tr>
              ) : list.map((m) => {
                const used = m.tokensQuota > 0 ? Math.min(100, Math.round((m.tokensUsed / m.tokensQuota) * 100)) : 0;
                const st = SUB_STATUS_LABEL[m.subscriptionStatus] || (m.subscriptionStatus ? { label: m.subscriptionStatus, cls: 'bg-slate-100 text-slate-500 border-slate-200' } : null);
                return (
                  <tr key={m.uid} className="hover:bg-[#f8fafc]/80 transition-colors">
                    <td className="px-4 py-3.5">
                      <div className="flex items-center gap-3">
                        <div className="w-8 h-8 rounded-full bg-blue-50 text-[#2f6bff] flex items-center justify-center font-bold text-xs shrink-0">
                          {(m.name || 'U').slice(0, 1).toUpperCase()}
                        </div>
                        <div className="min-w-0">
                          <div className="font-bold text-[#0f172a] truncate max-w-[200px]">{m.name}</div>
                          <div className="text-[11px] text-[#94a3b8] truncate max-w-[200px]">{m.email || `UID ${m.uid}`}</div>
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3.5">
                      <span className={`inline-block px-2.5 py-0.5 rounded-lg border font-bold ${planBadge(m.plan)}`}>
                        {m.plan.toUpperCase()}
                      </span>
                      {m.planInterval && (
                        <span className="ml-1.5 text-[#94a3b8] font-medium">
                          {m.planInterval === 'year' ? '年付' : m.planInterval === 'one_time' ? '一次性' : '月付'}
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3.5 w-[200px]">
                      <div className="font-mono tabular-nums text-[#334155] font-semibold">
                        {fmtNum(m.tokensUsed)}{m.tokensQuota > 0 ? ` / ${fmtNum(m.tokensQuota)}` : ''}
                      </div>
                      <div className="mt-1.5 h-1.5 rounded-full bg-[#f1f5f9] overflow-hidden">
                        <div
                          className={`h-full rounded-full transition-all ${
                            used >= 90 ? 'bg-rose-500' : used >= 70 ? 'bg-amber-500' : 'bg-[#2f6bff]'
                          }`}
                          style={{ width: m.tokensQuota > 0 ? `${Math.max(2, used)}%` : '0%' }}
                        />
                      </div>
                    </td>
                    <td className="px-4 py-3.5">
                      {st ? (
                        <span className={`inline-block px-2.5 py-0.5 rounded-lg border font-bold ${st.cls}`}>{st.label}</span>
                      ) : (
                        <span className="text-[#94a3b8]">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3.5 text-[#64748b] font-mono whitespace-nowrap">{fmtDate(m.userCreatedAt)}</td>
                    <td className="px-4 py-3.5 text-right">
                      <div className="inline-flex items-center gap-1.5">
                        <button
                          onClick={() => setViewing(m)}
                          className="h-8 px-2.5 inline-flex items-center gap-1.5 rounded-xl border border-[#e2e8f0] text-xs font-bold text-[#475569] hover:border-[#2f6bff] hover:text-[#2f6bff] hover:bg-blue-50/50 transition-colors"
                          title="查看该会员的提问记录"
                        >
                          <History className="w-3.5 h-3.5" />
                          提问记录
                        </button>
                        <button
                          onClick={() => setEditing(m)}
                          className="h-8 px-3 rounded-xl border border-[#e2e8f0] text-xs font-bold text-[#475569] hover:border-[#2f6bff] hover:text-[#2f6bff] hover:bg-blue-50/50 transition-colors"
                        >
                          调整配额
                        </button>
                      </div>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>

        {/* 分页栏 */}
        {totalPages > 1 && (
          <div className="flex items-center justify-between p-4 border-t border-[#f1f5f9]">
            <span className="text-xs text-[#94a3b8] font-mono tabular-nums">第 {page} / {totalPages} 页</span>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setPage((p) => Math.max(1, p - 1))}
                disabled={page <= 1}
                className="h-8 w-8 rounded-xl border border-[#e2e8f0] flex items-center justify-center text-[#64748b] hover:border-[#cbd5e1] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                aria-label="上一页"
              >
                <ChevronLeft className="w-4 h-4" />
              </button>
              <button
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
                disabled={page >= totalPages}
                className="h-8 w-8 rounded-xl border border-[#e2e8f0] flex items-center justify-center text-[#64748b] hover:border-[#cbd5e1] disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                aria-label="下一页"
              >
                <ChevronRight className="w-4 h-4" />
              </button>
            </div>
          </div>
        )}
      </div>

      {editing && (
        <MemberEditModal
          member={editing}
          submitting={submitting}
          onCancel={() => setEditing(null)}
          onSubmit={submitEdit}
        />
      )}

      {viewing && (
        <MemberRecordsModal
          member={viewing}
          request={request}
          onClose={() => setViewing(null)}
        />
      )}
    </div>
  );
};

/* ============ 提示词管理 ============ */

const CHAT_PROMPT_PLACEHOLDER = '留空 = 不注入系统提示词，保持各模型原生指令偏好';

const PromptsSection: React.FC<{
  request: AdminRequest;
  notify: (msg: string) => void;
}> = ({ request, notify }) => {
  const [data, setData] = useState<PromptsData | null>(() => cacheGet<PromptsData>('prompts'));
  const [chat, setChat] = useState(() => cacheGet<PromptsData>('prompts')?.chat.value ?? '');
  const [synthesis, setSynthesis] = useState(() => {
    const cached = cacheGet<PromptsData>('prompts');
    return cached ? (cached.synthesis.value || cached.synthesis.default) : '';
  });
  const [loading, setLoading] = useState(() => !cacheGet<PromptsData>('prompts'));
  const [savingKey, setSavingKey] = useState('');

  const load = useCallback(async (force = false) => {
    // 命中缓存直接复用;保存后 force 拉最新
    if (!force) {
      const hit = cacheGet<PromptsData>('prompts');
      if (hit) {
        setData(hit);
        setChat(hit.chat.value);
        setSynthesis(hit.synthesis.value || hit.synthesis.default);
        setLoading(false);
        return;
      }
    }
    setLoading(true);
    const res = await request<{ prompts: PromptsData }>('/tiwen/admin/prompts');
    setLoading(false);
    if (res.ok && res.data) {
      setData(res.data.prompts);
      setChat(res.data.prompts.chat.value);
      setSynthesis(res.data.prompts.synthesis.value || res.data.prompts.synthesis.default);
      cacheSet('prompts', res.data.prompts);
    } else {
      notify(res.message || '提示词加载失败');
    }
  }, [request, notify]);

  useEffect(() => { load(); }, [load]);

  const save = async (key: 'chat' | 'synthesis', value: string) => {
    setSavingKey(key);
    const res = await request('/tiwen/admin/promptsSave', { method: 'POST', json: { key, value } });
    setSavingKey('');
    notify(res.ok ? '提示词已保存，新发起的提问与分析即刻生效' : (res.message || '保存失败'));
    if (res.ok) load(true);
  };

  if (loading) {
    return (
      <div className="py-24 flex flex-col items-center justify-center text-[#94a3b8] gap-3">
        <Loader2 className="w-6 h-6 animate-spin text-[#2f6bff]" />
        <span className="text-sm">正在加载系统提示词配置…</span>
      </div>
    );
  }
  if (!data) return <EmptyHint text="提示词配置加载失败" />;

  return (
    <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
      {/* 对话提示词 */}
      <SectionCard
        title="多模型对话 System Prompt"
        extra={data.chat.customized ? <span className="px-2.5 py-0.5 rounded-lg bg-blue-50 text-[#2f6bff] text-xs font-bold border border-blue-200">已自定义</span> : undefined}
      >
        <div className="space-y-3">
          <p className="text-xs text-[#64748b] leading-relaxed">
            注入到每次多模型对话的 system 消息中，对全部并发模型统一生效。留空则不注入，保持各厂商模型原生行为。
          </p>
          <textarea
            value={chat}
            onChange={(e) => setChat(e.target.value)}
            placeholder={CHAT_PROMPT_PLACEHOLDER}
            rows={10}
            aria-label="对话系统提示词"
            className="w-full p-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-xs leading-relaxed text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition resize-y"
          />
          <div className="flex items-center justify-between">
            <span className="text-xs text-[#94a3b8] font-mono tabular-nums">{chat.length} / 8000 字</span>
            <div className="flex items-center gap-2">
              {data.chat.customized && (
                <button
                  onClick={() => setChat('')}
                  className="h-9 px-3 inline-flex items-center gap-1.5 rounded-xl border border-[#e2e8f0] text-xs font-bold text-[#64748b] hover:border-[#cbd5e1] transition-colors"
                >
                  <RotateCcw className="w-3.5 h-3.5" />
                  恢复默认
                </button>
              )}
              <button
                onClick={() => save('chat', chat)}
                disabled={savingKey === 'chat'}
                className="h-9 px-4 inline-flex items-center gap-2 rounded-xl bg-[#2f6bff] text-white text-xs font-bold hover:bg-[#1e4fd6] shadow-sm disabled:opacity-60 transition-all"
              >
                {savingKey === 'chat' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                保存 Prompt
              </button>
            </div>
          </div>
        </div>
      </SectionCard>

      {/* 综合分析提示词 */}
      <SectionCard
        title="交叉比对 System Prompt"
        extra={data.synthesis.customized ? <span className="px-2.5 py-0.5 rounded-lg bg-blue-50 text-[#2f6bff] text-xs font-bold border border-blue-200">已自定义</span> : <span className="px-2.5 py-0.5 rounded-lg bg-slate-100 text-slate-500 text-xs font-bold border border-slate-200">默认内置</span>}
      >
        <div className="space-y-3">
          <p className="text-xs text-[#64748b] leading-relaxed">
            多模型回答进行交叉深度比对、提炼共识与差异并生成结构化研报时使用的 system 提示词。
          </p>
          <textarea
            value={synthesis}
            onChange={(e) => setSynthesis(e.target.value)}
            rows={10}
            aria-label="综合分析系统提示词"
            className="w-full p-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-xs leading-relaxed text-[#0f172a] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition resize-y"
          />
          <div className="flex items-center justify-between">
            <span className="text-xs text-[#94a3b8] font-mono tabular-nums">{synthesis.length} / 8000 字</span>
            <div className="flex items-center gap-2">
              <button
                onClick={() => setSynthesis(data.synthesis.default)}
                className="h-9 px-3 inline-flex items-center gap-1.5 rounded-xl border border-[#e2e8f0] text-xs font-bold text-[#64748b] hover:border-[#cbd5e1] transition-colors"
              >
                <RotateCcw className="w-3.5 h-3.5" />
                填入默认
              </button>
              <button
                onClick={() => save('synthesis', synthesis)}
                disabled={savingKey === 'synthesis'}
                className="h-9 px-4 inline-flex items-center gap-2 rounded-xl bg-[#2f6bff] text-white text-xs font-bold hover:bg-[#1e4fd6] shadow-sm disabled:opacity-60 transition-all"
              >
                {savingKey === 'synthesis' ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
                保存 Prompt
              </button>
            </div>
          </div>
        </div>
      </SectionCard>
    </div>
  );
};

/* ============ 联网搜索(Tavily)配置 ============ */

const Toggle: React.FC<{ checked: boolean; onChange: (v: boolean) => void; label: string; hint?: string; disabled?: boolean }> = ({ checked, onChange, label, hint, disabled }) => (
  <button
    type="button"
    role="switch"
    aria-checked={checked}
    aria-label={label}
    disabled={disabled}
    onClick={() => onChange(!checked)}
    className="flex items-center justify-between w-full gap-3 text-left group disabled:opacity-50"
  >
    <span className="min-w-0">
      <span className="block text-xs font-bold text-[#0f172a]">{label}</span>
      {hint && <span className="block mt-0.5 text-[11px] text-[#94a3b8] leading-relaxed">{hint}</span>}
    </span>
    <span
      className={`relative shrink-0 inline-flex h-6 w-11 items-center rounded-full transition-colors ${
        checked ? 'bg-[#2f6bff]' : 'bg-[#cbd5e1]'
      }`}
    >
      <span
        className={`inline-block transform rounded-full bg-white shadow transition-transform ${
          checked ? 'translate-x-[22px]' : 'translate-x-[3px]'
        }`}
        style={{ height: 18, width: 18 }}
      />
    </span>
  </button>
);

const FieldNum: React.FC<{ label: string; value: number; min: number; max: number; step?: number; suffix?: string; onChange: (v: number) => void }> = ({ label, value, min, max, step = 1, suffix, onChange }) => (
  <label className="block">
    <span className="flex items-center justify-between text-[11px] font-bold text-[#475569] mb-1.5">
      {label}
      <span className="font-mono text-[#94a3b8]">{min}–{max}{suffix ? ` ${suffix}` : ''}</span>
    </span>
    <input
      type="number"
      value={value}
      min={min}
      max={max}
      step={step}
      onChange={(e) => onChange(Math.max(min, Math.min(max, Number(e.target.value) || min)))}
      className="w-full h-10 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-sm font-mono text-[#0f172a] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
    />
  </label>
);

const WebSearchSection: React.FC<{
  request: AdminRequest;
  notify: (msg: string) => void;
}> = ({ request, notify }) => {
  const [data, setData] = useState<WebSearchData | null>(() => cacheGet<WebSearchData>('websearch'));
  const [enabled, setEnabled] = useState(() => cacheGet<WebSearchData>('websearch')?.enabled ?? true);
  const [apiKey, setApiKey] = useState('');
  const [includeAnswer, setIncludeAnswer] = useState(() => cacheGet<WebSearchData>('websearch')?.include_answer ?? true);
  const [maxResults, setMaxResults] = useState(() => cacheGet<WebSearchData>('websearch')?.max_results ?? 5);
  const [searchDepth, setSearchDepth] = useState<'basic' | 'advanced'>(() => cacheGet<WebSearchData>('websearch')?.search_depth ?? 'basic');
  const [timeout, setTimeout] = useState(() => cacheGet<WebSearchData>('websearch')?.timeout ?? 8);
  const [maxChars, setMaxChars] = useState(() => cacheGet<WebSearchData>('websearch')?.max_content_chars ?? 1200);
  const [loading, setLoading] = useState(() => !cacheGet<WebSearchData>('websearch'));
  const [saving, setSaving] = useState(false);

  const applyData = useCallback((d: WebSearchData) => {
    setData(d);
    setEnabled(d.enabled);
    setIncludeAnswer(d.include_answer);
    setMaxResults(d.max_results);
    setSearchDepth(d.search_depth);
    setTimeout(d.timeout);
    setMaxChars(d.max_content_chars);
    setApiKey('');
  }, []);

  const load = useCallback(async (force = false) => {
    if (!force) {
      const hit = cacheGet<WebSearchData>('websearch');
      if (hit) {
        applyData(hit);
        setLoading(false);
        return;
      }
    }
    setLoading(true);
    const res = await request<{ webSearch: WebSearchData }>('/tiwen/admin/webSearch');
    setLoading(false);
    if (res.ok && res.data) {
      applyData(res.data.webSearch);
      cacheSet('websearch', res.data.webSearch);
    } else {
      notify(res.message || '联网搜索配置加载失败');
    }
  }, [request, notify, applyData]);

  useEffect(() => { load(); }, [load]);

  const save = async () => {
    setSaving(true);
    const payload: Record<string, unknown> = {
      enabled,
      include_answer: includeAnswer,
      max_results: maxResults,
      search_depth: searchDepth,
      timeout,
      max_content_chars: maxChars,
    };
    // api_key 留空 = 保留原值,不提交该字段
    if (apiKey.trim()) payload.api_key = apiKey.trim();
    const res = await request('/tiwen/admin/webSearchSave', { method: 'POST', json: { webSearch: payload } });
    setSaving(false);
    notify(res.ok ? '联网搜索配置已保存,前台勾选即刻生效' : (res.message || '保存失败'));
    if (res.ok) load(true);
  };

  if (loading) {
    return (
      <div className="py-24 flex flex-col items-center justify-center text-[#94a3b8] gap-3">
        <Loader2 className="w-6 h-6 animate-spin text-[#2f6bff]" />
        <span className="text-sm">正在加载联网搜索配置…</span>
      </div>
    );
  }
  if (!data) return <EmptyHint text="联网搜索配置加载失败" />;

  return (
    <div className="grid grid-cols-1 xl:grid-cols-2 gap-4">
      {/* 服务开关与密钥 */}
      <SectionCard
        title="联网搜索服务(Tavily)"
        extra={
          data.api_key_set
            ? <span className="px-2.5 py-0.5 rounded-lg bg-emerald-50 text-emerald-700 text-xs font-bold border border-emerald-200">Key 已配置</span>
            : <span className="px-2.5 py-0.5 rounded-lg bg-amber-50 text-amber-700 text-xs font-bold border border-amber-200">未配置 Key</span>
        }
      >
        <div className="space-y-4">
          <p className="text-xs text-[#64748b] leading-relaxed">
            开启后,前台提问区出现「智能搜索」勾选;用户勾选时,系统会先用 Tavily 检索实时网页,再把结果注入各并发模型的上下文。关闭则前台勾选不触发检索。
          </p>

          <Toggle
            checked={enabled}
            onChange={setEnabled}
            label="启用联网搜索"
            hint="总开关。关闭后无论前台是否勾选都不发起检索"
          />

          <div className="pt-1">
            <span className="flex items-center justify-between text-[11px] font-bold text-[#475569] mb-1.5">
              Tavily API Key
              {data.api_key_set && <span className="font-mono text-[#94a3b8]">{data.api_key_masked}</span>}
            </span>
            <input
              type="password"
              value={apiKey}
              autoComplete="new-password"
              onChange={(e) => setApiKey(e.target.value)}
              placeholder={data.api_key_set ? '留空 = 保留当前已保存的 Key' : 'tvly-xxxxxxxxxxxxxxxx'}
              aria-label="Tavily API Key"
              className="w-full h-10 px-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-xs text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition"
            />
            <span className="block mt-1.5 text-[11px] text-[#94a3b8] leading-relaxed">
              也可在服务器 .env 中配置 <code className="font-mono text-[#64748b]">TAVILY_API_KEY</code> 作为默认值;此处填写则优先生效。
            </span>
          </div>

          <div className="pt-1 border-t border-[#f1f5f9]">
            <Toggle
              checked={includeAnswer}
              onChange={setIncludeAnswer}
              label="附带检索摘要"
              hint="让 Tavily 额外返回一段对检索结果的综合答案,一并注入上下文"
            />
          </div>

          <div className="flex items-center justify-end pt-1">
            <button
              onClick={save}
              disabled={saving}
              className="h-9 px-4 inline-flex items-center gap-2 rounded-xl bg-[#2f6bff] text-white text-xs font-bold hover:bg-[#1e4fd6] shadow-sm disabled:opacity-60 transition-all"
            >
              {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
              保存配置
            </button>
          </div>
        </div>
      </SectionCard>

      {/* 检索参数 */}
      <SectionCard title="检索参数">
        <div className="space-y-4">
          <div>
            <span className="block text-[11px] font-bold text-[#475569] mb-1.5">检索深度</span>
            <div className="grid grid-cols-2 gap-2">
              {([['basic', '快速 Basic', '低延迟,适合日常提问'], ['advanced', '深度 Advanced', '覆盖更广,耗时与计费更高']] as const).map(([val, label, hint]) => (
                <button
                  key={val}
                  type="button"
                  onClick={() => setSearchDepth(val)}
                  className={`text-left p-3 rounded-xl border transition-all ${
                    searchDepth === val
                      ? 'border-[#2f6bff] bg-blue-50 ring-2 ring-blue-100'
                      : 'border-[#e2e8f0] bg-[#f8fafc] hover:border-[#cbd5e1]'
                  }`}
                >
                  <span className="block text-xs font-bold text-[#0f172a]">{label}</span>
                  <span className="block mt-0.5 text-[11px] text-[#94a3b8] leading-relaxed">{hint}</span>
                </button>
              ))}
            </div>
          </div>

          <FieldNum label="返回网页条数" value={maxResults} min={1} max={10} onChange={setMaxResults} suffix="条" />
          <FieldNum label="检索超时" value={timeout} min={3} max={30} onChange={setTimeout} suffix="秒" />
          <FieldNum label="单条正文截断" value={maxChars} min={200} max={4000} step={100} onChange={setMaxChars} suffix="字" />

          <p className="text-[11px] text-[#94a3b8] leading-relaxed pt-1 border-t border-[#f1f5f9]">
            参数保存后对新发起的提问即刻生效。条数与截断长度越大,注入上下文越长,Token 消耗与延迟相应增加。
          </p>
        </div>
      </SectionCard>
    </div>
  );
};

/* ============ 协议与条款管理 ============ */

const LEGAL_MAX_CHARS = 20000;

type LegalDocId = 'register' | 'privacy' | 'about' | 'payment';
type LegalLang = 'zh' | 'zh-TW' | 'en';

const LEGAL_DOCS: { id: LegalDocId; label: string; desc: string }[] = [
  { id: 'register', label: '注册协议', desc: '用户注册与使用本服务需同意的服务条款' },
  { id: 'privacy', label: '隐私条款', desc: '用户数据的收集、使用与保护方式' },
  { id: 'about', label: '关于我们', desc: '团队介绍、产品理念与联系方式' },
  { id: 'payment', label: '支付条款', desc: '订阅计费、退款与账单相关条款' },
];

const LEGAL_LANG_LABELS: Record<LegalLang, string> = { zh: '中文', 'zh-TW': '繁體', en: 'English' };

/** 各文档的结构化骨架占位(仅作 placeholder 提示,不预置虚构法务文本) */
const LEGAL_PLACEHOLDERS: Record<LegalDocId, Record<LegalLang, string>> = {
  register: {
    zh: '# 注册协议\n\n最后更新:YYYY-MM-DD\n\n## 一、协议的接受\n说明用户注册或使用本服务即视为同意本协议…\n\n## 二、账号与安全\n…\n\n## 三、用户行为规范\n…\n\n## 四、服务变更与终止\n…',
    'zh-TW': '# 註冊協議\n\n最後更新:YYYY-MM-DD\n\n## 一、協議的接受\n說明使用者註冊或使用本服務即視為同意本協議…\n\n## 二、帳號與安全\n…\n\n## 三、使用者行為規範\n…\n\n## 四、服務變更與終止\n…',
    en: '# Terms of Service\n\nLast updated: YYYY-MM-DD\n\n## 1. Acceptance of Terms\nWhat users agree to by signing up…\n\n## 2. Accounts & Security\n…\n\n## 3. Acceptable Use\n…\n\n## 4. Changes & Termination\n…',
  },
  privacy: {
    zh: '# 隐私条款\n\n最后更新:YYYY-MM-DD\n\n## 一、我们收集的信息\n…\n\n## 二、信息的使用方式\n…\n\n## 三、数据存储与保护\n…\n\n## 四、您的权利\n…',
    'zh-TW': '# 隱私條款\n\n最後更新:YYYY-MM-DD\n\n## 一、我們收集的資訊\n…\n\n## 二、資訊的使用方式\n…\n\n## 三、資料儲存與保護\n…\n\n## 四、您的權利\n…',
    en: '# Privacy Policy\n\nLast updated: YYYY-MM-DD\n\n## 1. Information We Collect\n…\n\n## 2. How We Use Information\n…\n\n## 3. Storage & Protection\n…\n\n## 4. Your Rights\n…',
  },
  about: {
    zh: '# 关于我们\n\n一句话介绍团队与产品使命…\n\n## 我们做什么\n…\n\n## 联系我们\n邮箱:support@example.com',
    'zh-TW': '# 關於我們\n\n一句話介紹團隊與產品使命…\n\n## 我們做什麼\n…\n\n## 聯絡我們\n電子郵件:support@example.com',
    en: '# About Us\n\nA one-line intro to the team and mission…\n\n## What We Do\n…\n\n## Contact Us\nEmail: support@example.com',
  },
  payment: {
    zh: '# 支付条款\n\n最后更新:YYYY-MM-DD\n\n## 一、计费与订阅\n…\n\n## 二、退款政策\n…\n\n## 三、账单与发票\n…',
    'zh-TW': '# 支付條款\n\n最後更新:YYYY-MM-DD\n\n## 一、計費與訂閱\n…\n\n## 二、退款政策\n…\n\n## 三、帳單與發票\n…',
    en: '# Payment Terms\n\nLast updated: YYYY-MM-DD\n\n## 1. Billing & Subscriptions\n…\n\n## 2. Refund Policy\n…\n\n## 3. Invoices & Receipts\n…',
  },
};

/** 后端可能是尚未重启的旧版(legal() 只返回 zh/en)或字段缺失,统一补齐三语言,避免编辑器读到 undefined */
const normalizeLegal = (next?: Partial<LegalData> | null) => {
  const values = {} as Record<LegalDocId, Record<LegalLang, string>>;
  const data = {} as Record<LegalDocId, LegalDocEntry>;
  LEGAL_DOCS.forEach(({ id }) => {
    const entry = next?.[id];
    const row = {
      zh: typeof entry?.zh === 'string' ? entry.zh : '',
      'zh-TW': typeof entry?.['zh-TW'] === 'string' ? entry['zh-TW'] : '',
      en: typeof entry?.en === 'string' ? entry.en : '',
    };
    values[id] = row;
    data[id] = { ...row, customized: !!entry?.customized };
  });
  return { values, data };
};

const LegalSection: React.FC<{
  request: AdminRequest;
  notify: (msg: string) => void;
}> = ({ request, notify }) => {
  const [data, setData] = useState<LegalData | null>(() => {
    const hit = cacheGet<LegalData>('legal');
    return hit ? normalizeLegal(hit).data : null;
  });
  const [values, setValues] = useState<Record<LegalDocId, Record<LegalLang, string>>>(() => normalizeLegal().values);
  const [doc, setDoc] = useState<LegalDocId>('register');
  const [lang, setLang] = useState<LegalLang>('zh');
  const [mobilePreview, setMobilePreview] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(() => !cacheGet<LegalData>('legal'));

  const applyData = useCallback((next: LegalData) => {
    const norm = normalizeLegal(next);
    setData(norm.data);
    setValues(norm.values);
  }, []);

  const load = useCallback(async (force = false) => {
    // 命中缓存直接复用;保存后 force 拉最新
    if (!force) {
      const hit = cacheGet<LegalData>('legal');
      if (hit) {
        applyData(hit);
        setLoading(false);
        return;
      }
    }
    setLoading(true);
    const res = await request<{ legal: LegalData }>('/tiwen/admin/legal');
    setLoading(false);
    if (res.ok && res.data) {
      applyData(res.data.legal);
      cacheSet('legal', res.data.legal);
    } else {
      notify(res.message || '协议内容加载失败');
    }
  }, [request, notify, applyData]);

  useEffect(() => { load(); }, [load]);

  const value = values[doc][lang];
  const savedValue = data ? data[doc][lang] : '';
  const dirty = value !== savedValue;
  const docLabel = LEGAL_DOCS.find((d) => d.id === doc)?.label ?? '';

  // 预览内容 150ms 防抖,与输入保持准实时
  const [previewText, setPreviewText] = useState('');
  useEffect(() => {
    const timer = window.setTimeout(() => setPreviewText(value), 150);
    return () => window.clearTimeout(timer);
  }, [value]);

  const save = async () => {
    setSaving(true);
    const res = await request('/tiwen/admin/legalSave', { method: 'POST', json: { doc, lang, value } });
    setSaving(false);
    notify(res.ok ? `已保存「${docLabel}」${LEGAL_LANG_LABELS[lang]}版本,前台即刻生效` : (res.message || '保存失败'));
    if (res.ok) load(true);
  };

  const revert = () => setValues((cur) => ({ ...cur, [doc]: { ...cur[doc], [lang]: savedValue } }));

  if (loading) {
    return (
      <div className="py-24 flex flex-col items-center justify-center text-[#94a3b8] gap-3">
        <Loader2 className="w-6 h-6 animate-spin text-[#2f6bff]" />
        <span className="text-sm">正在加载协议内容…</span>
      </div>
    );
  }
  if (!data) return <EmptyHint text="协议内容加载失败" />;

  const overLimit = value.length > LEGAL_MAX_CHARS;
  const counterCls = overLimit ? 'text-rose-500' : value.length > LEGAL_MAX_CHARS * 0.9 ? 'text-amber-500' : 'text-[#94a3b8]';

  return (
    <div className="space-y-4">
      {/* 文档选择 Tab 卡 */}
      <div className="rounded-2xl border border-[#e2e8f0] bg-white shadow-card overflow-hidden">
        <div className="flex overflow-x-auto px-2 pt-1" role="tablist" aria-label="协议文档">
          {LEGAL_DOCS.map((item) => {
            const active = item.id === doc;
            const published = !!(data[item.id].zh || data[item.id]['zh-TW'] || data[item.id].en);
            return (
              <button
                key={item.id}
                role="tab"
                aria-selected={active}
                onClick={() => setDoc(item.id)}
                className={`relative flex items-center gap-1.5 whitespace-nowrap px-4 py-3 text-xs font-bold transition-colors ${
                  active ? 'text-[#2f6bff]' : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
              >
                {published && (
                  <span
                    className={`w-1.5 h-1.5 rounded-full ${active ? 'bg-[#2f6bff]' : 'bg-emerald-500'}`}
                    title="已有已发布内容"
                  />
                )}
                {item.label}
                {active && <span className="absolute left-3 right-3 bottom-0 h-0.5 rounded-full bg-[#2f6bff]" />}
              </button>
            );
          })}
        </div>
        <div className="px-4 py-2.5 border-t border-[#f1f5f9] bg-[#f8fafc] text-xs text-[#64748b]">
          {LEGAL_DOCS.find((d) => d.id === doc)?.desc}
        </div>
      </div>

      {/* 编辑器卡 */}
      <div className="rounded-2xl border border-[#e2e8f0] bg-white shadow-card overflow-hidden">
        <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-[#f1f5f9]">
          {/* 语言 Tab */}
          <div className="flex items-center rounded-xl bg-[#f1f5f9] p-1" role="tablist" aria-label="文档语言">
            {(Object.keys(LEGAL_LANG_LABELS) as LegalLang[]).map((l) => (
              <button
                key={l}
                role="tab"
                aria-selected={l === lang}
                onClick={() => setLang(l)}
                className={`h-7 px-3 rounded-lg text-xs font-bold transition-all ${
                  l === lang ? 'bg-white text-[#2f6bff] shadow-sm' : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
              >
                {LEGAL_LANG_LABELS[l]}
              </button>
            ))}
          </div>
          <div className="flex items-center gap-2">
            {dirty ? (
              <span className="px-2.5 py-0.5 rounded-lg bg-amber-50 text-amber-600 text-xs font-bold border border-amber-200">未保存</span>
            ) : (
              <span className="px-2.5 py-0.5 rounded-lg bg-slate-100 text-slate-500 text-xs font-bold border border-slate-200">
                {savedValue ? '已发布' : '未发布'}
              </span>
            )}
            {/* 小屏编辑/预览切换(桌面双栏常驻) */}
            <div className="flex items-center rounded-xl bg-[#f1f5f9] p-1 xl:hidden" role="tablist" aria-label="编辑或预览">
              <button
                onClick={() => setMobilePreview(false)}
                aria-selected={!mobilePreview}
                className={`h-7 px-3 rounded-lg text-xs font-bold transition-all ${!mobilePreview ? 'bg-white text-[#2f6bff] shadow-sm' : 'text-[#64748b]'}`}
              >
                编辑
              </button>
              <button
                onClick={() => setMobilePreview(true)}
                aria-selected={mobilePreview}
                className={`h-7 px-3 rounded-lg text-xs font-bold transition-all ${mobilePreview ? 'bg-white text-[#2f6bff] shadow-sm' : 'text-[#64748b]'}`}
              >
                预览
              </button>
            </div>
          </div>
        </div>

        <div className="grid grid-cols-1 xl:grid-cols-2">
          {/* 编辑区 */}
          <div className={`p-4 xl:border-r border-[#f1f5f9] ${mobilePreview ? 'hidden xl:block' : ''}`}>
            <textarea
              value={value}
              onChange={(e) => setValues((cur) => ({ ...cur, [doc]: { ...cur[doc], [lang]: e.target.value } }))}
              placeholder={LEGAL_PLACEHOLDERS[doc][lang]}
              rows={16}
              aria-label={`编辑${docLabel}${LEGAL_LANG_LABELS[lang]}内容`}
              className="w-full p-3 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] font-mono text-xs leading-relaxed text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none focus:border-[#2f6bff] focus:bg-white transition resize-y"
            />
            <div className="flex flex-wrap items-center justify-between gap-2 mt-2 text-xs">
              <span className={`font-mono tabular-nums ${counterCls}`}>
                {value.length.toLocaleString()} / {LEGAL_MAX_CHARS.toLocaleString()} 字
                {overLimit ? ' · 超出部分保存时将被截断' : ''}
              </span>
              <span className="text-[#94a3b8]">支持 Markdown:标题、列表、引用、加粗</span>
            </div>
          </div>

          {/* 预览区:桌面常驻双栏,小屏切到「预览」时显示 */}
          <div className={`p-4 bg-[#fafbfe] ${mobilePreview ? 'block' : 'hidden xl:block'}`}>
            {previewText.trim() ? (
              <div className="max-h-[540px] min-h-[240px] overflow-y-auto rounded-xl border border-[#e2e8f0] bg-white p-4">
                <MarkdownRenderer content={previewText} />
              </div>
            ) : (
              <div className="min-h-[240px] h-full flex flex-col items-center justify-center gap-2 text-[#94a3b8]">
                <FileText className="w-8 h-8 text-[#cbd5e1]" />
                <span className="text-xs">开始输入后,此处实时预览前台发布效果</span>
              </div>
            )}
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-t border-[#f1f5f9] bg-[#f8fafc]">
          <p className="text-xs text-[#64748b]">保存后前台协议页即刻更新;清空内容并保存即下线该篇。</p>
          <div className="flex items-center gap-2">
            {dirty && (
              <button
                onClick={revert}
                className="h-9 px-3 inline-flex items-center gap-1.5 rounded-xl border border-[#e2e8f0] text-xs font-bold text-[#64748b] hover:border-[#cbd5e1] transition-colors"
              >
                <RotateCcw className="w-3.5 h-3.5" />
                放弃修改
              </button>
            )}
            <button
              onClick={save}
              disabled={saving}
              className="h-9 px-4 inline-flex items-center gap-2 rounded-xl bg-[#2f6bff] text-white text-xs font-bold hover:bg-[#1e4fd6] shadow-sm disabled:opacity-60 transition-all"
            >
              {saving ? <Loader2 className="w-3.5 h-3.5 animate-spin" /> : <Save className="w-3.5 h-3.5" />}
              保存{LEGAL_LANG_LABELS[lang]}版本
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

/* ============ 页面主体与侧边栏布局 ============ */

type SectionId = 'overview' | 'billing' | 'plans' | 'members' | 'prompts' | 'websearch' | 'legal';

const SECTIONS: { id: SectionId; label: string; desc: string; icon: typeof LayoutDashboard }[] = [
  { id: 'overview', label: '消耗统计', desc: '平台级用量、模型消耗与套餐分布', icon: LayoutDashboard },
  { id: 'billing', label: '账单明细', desc: 'Stripe 支付记录、收款统计与流水检索', icon: Receipt },
  { id: 'plans', label: '订阅与价格', desc: '订阅与一次性包定价、配额与多语言权益，保存后新订单即时生效', icon: CreditCard },
  { id: 'members', label: '会员管理', desc: '检索会员、提问记录查看、调整套餐与配额、重置用量', icon: Users },
  { id: 'prompts', label: '提示词管理', desc: '对话与综合分析的全局系统提示词', icon: MessageSquareText },
  { id: 'websearch', label: '联网搜索', desc: 'Tavily 智能搜索开关、密钥与检索参数', icon: Globe },
  { id: 'legal', label: '协议与条款', desc: '注册协议、隐私条款、关于我们、支付条款（简体／繁體／English）', icon: FileText },
];

export const SysAdminPage: React.FC = () => {
  const { token, admin, signIn, signOut, request } = useAdminAuth();
  // 分区由 URL 子路由驱动:/sysAdmin/billing 直达账单页,前进/后退/收藏均可用
  const { section: sectionParam } = useParams<{ section?: string }>();
  const section = (SECTIONS.find((s) => s.id === sectionParam)?.id ?? 'overview') as SectionId;
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const [toast, setToast] = useState('');

  // 退出登录:同时清空分区数据缓存,换账号不残留旧数据
  const handleSignOut = useCallback(() => {
    cacheDrop();
    signOut();
  }, [signOut]);

  const notify = useCallback((msg: string) => {
    setToast(msg);
    window.setTimeout(() => setToast((cur) => (cur === msg ? '' : cur)), 2800);
  }, []);

  // 登录态心跳校验
  useEffect(() => {
    if (!token) return;
    let cancelled = false;
    (async () => {
      const res = await request('/tiwen/admin/info');
      if (!cancelled && res.ok && res.data) {
        signIn(token, normalizeUser(res.data as AdminUser));
      }
    })();
    return () => { cancelled = true; };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  if (!token || !admin) {
    return <LoginGate onSignIn={signIn} />;
  }

  const active = SECTIONS.find((s) => s.id === section)!;

  const navContent = (
    <div className="flex h-full flex-col p-4">
      {/* 品牌头部 */}
      <div className="flex items-center justify-between pb-4 border-b border-[#f1f5f9]">
        <div className="flex items-center gap-3">
          <div className="flex h-9 w-9 items-center justify-center rounded-xl bg-white border border-[#e2e8f0] p-1.5 shadow-sm">
            <img src={logoImg} alt="tiwen logo" className="h-full w-full object-contain" />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-1.5">
              <span className="text-sm font-bold tracking-tight text-[#0f172a]">
                提问管控中心
              </span>
            </div>
            <p className="text-[11px] font-medium text-[#94a3b8] truncate">
              Tiwen Admin Console
            </p>
          </div>
        </div>
        <button
          onClick={() => setMobileMenuOpen(false)}
          className="lg:hidden rounded-lg p-1.5 text-[#64748b] hover:bg-[#f1f5f9]"
          aria-label="关闭菜单"
        >
          <X className="h-5 w-5" />
        </button>
      </div>

      {/* 模块分类标题 */}
      <div className="pt-4 pb-2 px-1 text-[11px] font-bold uppercase tracking-wider text-[#94a3b8]">
        核心管理模块
      </div>

      {/* 导航条目 */}
      <nav className="flex flex-1 flex-col gap-1" aria-label="后台导航">
        {SECTIONS.map((item) => {
          const isActive = section === item.id;
          const Icon = item.icon;
          return (
            <Link
              key={item.id}
              to={`/sysAdmin/${item.id}`}
              onClick={() => setMobileMenuOpen(false)}
              aria-current={isActive ? 'page' : undefined}
              className={`group relative flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-xs font-semibold text-left transition-all ${
                isActive
                  ? 'bg-blue-50 text-[#2f6bff]'
                  : 'text-[#475569] hover:bg-[#f8fafc] hover:text-[#0f172a]'
              }`}
            >
              {isActive && (
                <span className="absolute left-0 top-2 bottom-2 w-1 rounded-r-full bg-[#2f6bff]" />
              )}
              <div
                className={`flex h-7 w-7 items-center justify-center rounded-lg transition-colors ${
                  isActive
                    ? 'bg-[#2f6bff] text-white'
                    : 'bg-[#f1f5f9] text-[#64748b] group-hover:text-[#0f172a]'
                }`}
              >
                <Icon className="h-3.5 w-3.5" />
              </div>
              <div className="min-w-0 flex-1">
                <div className="font-bold text-xs truncate">{item.label}</div>
                <div className="text-[10px] font-normal text-[#94a3b8] truncate">
                  {item.desc}
                </div>
              </div>
            </Link>
          );
        })}
      </nav>

      {/* 侧栏底部 */}
      <div className="mt-auto space-y-2.5 border-t border-[#f1f5f9] pt-4">
        {/* 引擎状态 */}
        <div className="flex items-center justify-between rounded-xl bg-[#f8fafc] px-3 py-2 text-xs text-[#64748b]">
          <div className="flex items-center gap-1.5">
            <span className="h-2 w-2 rounded-full bg-emerald-500" />
            <span>Swoole 协程</span>
          </div>
          <span className="font-mono text-[10px] font-bold text-[#94a3b8]">v2.18</span>
        </div>

        {/* 用户信息卡片 */}
        <div className="flex items-center gap-2.5 rounded-2xl border border-[#e2e8f0] bg-white p-2.5 shadow-card">
          <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-xs font-bold text-[#2f6bff]">
            {(admin.name || 'A').slice(0, 1).toUpperCase()}
          </div>
          <div className="min-w-0 flex-1">
            <div className="truncate text-xs font-bold text-[#0f172a]">
              {admin.name}
            </div>
            <div className="flex items-center gap-1 text-[10px] text-[#94a3b8]">
              <Shield className="h-2.5 w-2.5 text-[#2f6bff]" />
              <span>系统管理员</span>
            </div>
          </div>
          <button
            onClick={handleSignOut}
            className="flex h-7 w-7 items-center justify-center rounded-lg text-[#94a3b8] transition-colors hover:bg-rose-50 hover:text-rose-600"
            title="退出登录"
          >
            <LogOut className="h-3.5 w-3.5" />
          </button>
        </div>

        {/* 返回前台链接 */}
        <Link
          to="/"
          className="flex h-9 w-full items-center justify-center gap-1.5 rounded-xl border border-[#e2e8f0] bg-white text-xs font-semibold text-[#64748b] hover:bg-[#f8fafc] hover:text-[#0f172a] transition-colors"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          返回前台工作台
        </Link>
      </div>
    </div>
  );

  return (
    <div className="flex min-h-screen flex-col lg:flex-row bg-[#f5f7fb]">
      {/* ── 移动端顶部标题栏 ── */}
      <header className="sticky top-0 flex h-14 items-center justify-between border-b border-[#e8edf5] bg-white px-4 lg:hidden shrink-0 z-30">
        <div className="flex items-center gap-2.5">
          <button
            onClick={() => setMobileMenuOpen(true)}
            className="rounded-xl border border-[#e2e8f0] bg-[#f8fafc] p-2 text-[#475569]"
            aria-label="打开侧边导航"
          >
            <Menu className="h-5 w-5" />
          </button>
          <div className="flex items-center gap-2">
            <div className="flex h-7 w-7 items-center justify-center rounded-lg bg-white border border-[#e2e8f0] p-1 shadow-xs">
              <img src={logoImg} alt="tiwen logo" className="h-full w-full object-contain" />
            </div>
            <span className="text-sm font-bold text-[#0f172a]">提问管控中心</span>
          </div>
        </div>
        <Link
          to="/"
          className="inline-flex items-center gap-1 text-xs font-semibold text-[#64748b] hover:text-[#2f6bff]"
        >
          <ArrowLeft className="h-3.5 w-3.5" />
          前台
        </Link>
      </header>

      {/* ── 移动端导航抽屉 (Drawer) ── */}
      {mobileMenuOpen && (
        <div className="fixed inset-0 z-50 lg:hidden" role="dialog" aria-modal="true">
          <div
            className="fixed inset-0 bg-[#070d1e]/40 backdrop-blur-sm transition-opacity"
            onClick={() => setMobileMenuOpen(false)}
          />
          <div className="fixed inset-y-0 left-0 w-72 max-w-[85vw] bg-white shadow-xl z-10 flex flex-col">
            {navContent}
          </div>
        </div>
      )}

      {/* ── 桌面端固定侧边栏 (固定视口，不随页面滚动) ── */}
      <aside className="hidden lg:flex w-64 shrink-0 flex-col border-r border-[#e8edf5] bg-white fixed inset-y-0 left-0 h-screen z-20 overflow-y-auto">
        {navContent}
      </aside>

      {/* ── 主内容区 (左侧避让固定侧栏 lg:pl-64) ── */}
      <main className="flex-1 min-w-0 flex flex-col lg:pl-64">
        {/* 顶部标题栏 (吸顶固定) */}
        <header className="sticky top-0 z-10 border-b border-[#e8edf5] bg-white/95 backdrop-blur-sm px-4 sm:px-6 py-4 flex items-center justify-between shrink-0">
          <div className="space-y-0.5">
            <div className="flex items-center gap-2">
              <h1 className="text-base sm:text-lg font-bold text-[#0f172a]">{active.label}</h1>
              <span className="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-bold text-[#2f6bff] border border-blue-100">
                Tiwen Console
              </span>
            </div>
            <p className="text-xs text-[#64748b]">{active.desc}</p>
          </div>

          <div className="flex items-center gap-3">
            <Link
              to="/"
              className="hidden sm:inline-flex items-center gap-1.5 rounded-xl border border-[#e2e8f0] px-3 py-1.5 text-xs font-semibold text-[#64748b] hover:bg-[#f8fafc] hover:text-[#0f172a] transition-colors"
            >
              <ArrowLeft className="h-3.5 w-3.5" />
              返回前台
            </Link>
          </div>
        </header>

        {/* 核心内容容器 - 标准边距 p-4 sm:p-6，去除 max-w 限宽铺满屏幕 */}
        <div className="flex-1 p-4 sm:p-6 w-full space-y-4">
          {section === 'overview' && <StatsSection request={request} />}
          {section === 'billing' && <BillingSection request={request} notify={notify} />}
          {section === 'plans' && <PlansSection request={request} notify={notify} />}
          {section === 'members' && <MembersSection request={request} notify={notify} />}
          {section === 'prompts' && <PromptsSection request={request} notify={notify} />}
          {section === 'websearch' && <WebSearchSection request={request} notify={notify} />}
          {section === 'legal' && <LegalSection request={request} notify={notify} />}
        </div>
      </main>

      {/* ── 全局轻提示 Toast ── */}
      {toast && (
        <div className="fixed left-1/2 bottom-8 -translate-x-1/2 z-[300] pointer-events-none">
          <div className="bg-[#070d1e] text-white text-xs font-semibold px-4.5 py-2.5 rounded-full shadow-lg border border-white/10 max-w-[85vw] text-center flex items-center gap-2">
            <span className="h-2 w-2 rounded-full bg-[#2f6bff]" />
            <span>{toast}</span>
          </div>
        </div>
      )}
    </div>
  );
};
