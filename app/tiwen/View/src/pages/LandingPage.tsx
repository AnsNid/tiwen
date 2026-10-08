import React, { useEffect, useState } from 'react';
import { useNavigate, Link } from 'react-router-dom';
import { useSession } from '../context/SessionContext';
import { useAuth } from '../context/AuthContext';
import { useLanguage } from '../context/LanguageContext';
import { RecentSessions } from '../components/common/RecentSessions';
import { fetchAppConfig } from '../api/models';
import {
  Sparkles,
  ArrowRight,
  Zap,
  Layers,
  FileText,
  Cpu,
  Quote,
  ShieldCheck,
  Globe
} from 'lucide-react';

export const LandingPage: React.FC = () => {
  const navigate = useNavigate();
  const {
    createSession,
    selectedModelIds,
    setSelectedModelIds,
    models,
    smartSearch,
    setSmartSearch
  } = useSession();
  const { user, isAuthenticated, openAuthModal } = useAuth();
  const { t, locale } = useLanguage();
  const [question, setQuestion] = useState('');
  const [freeDailyAsks, setFreeDailyAsks] = useState<number | null>(null);

  // 免费版每日限次读共享缓存配置(自动去重并发请求)
  useEffect(() => {
    let cancelled = false;
    fetchAppConfig().then(cfg => {
      if (cancelled || !cfg?.plans) return;
      setFreeDailyAsks(Number(cfg.plans.free?.daily_asks ?? 20));
    });
    return () => { cancelled = true; };
  }, []);

  // 首页快捷模型开关(联动工作台真实并发模型)
  const gpt56Id = models.find(m => m.id.startsWith('gpt-5.6'))?.id || 'gpt-5.6-sol';
  const heroModelOptions = [
    { id: gpt56Id, label: 'GPT-5.6' },
    { id: 'claude-opus-5', label: 'Claude' },
    { id: 'gemini-3.1-pro-preview', label: 'Gemini' },
    { id: 'deepseek-v4-pro', label: 'DeepSeek' },
    { id: 'glm-5.1', label: 'GLM' },
    { id: 'kimi-k2.6', label: 'Kimi' },
  ];

  // 首页默认选中前三个模型
  useEffect(() => {
    const defaultTop3Ids = [gpt56Id, 'claude-opus-5', 'gemini-3.1-pro-preview'];
    const userCustomized = sessionStorage.getItem('tiwen_landing_models_toggled') === '1';
    if (!userCustomized) {
      const saved = localStorage.getItem('tiwen_selected_models');
      let shouldResetToTop3 = !saved;
      if (saved) {
        try {
          const parsed = JSON.parse(saved);
          const oldDefaults = ['gpt-5.6-sol', 'deepseek-v4-pro', 'claude-opus-5', 'gemini-3.1-pro-preview'];
          if (
            !Array.isArray(parsed) ||
            parsed.length === 0 ||
            (parsed.length === 4 && oldDefaults.every(id => parsed.includes(id))) ||
            !defaultTop3Ids.every(id => parsed.includes(id))
          ) {
            shouldResetToTop3 = true;
          }
        } catch {
          shouldResetToTop3 = true;
        }
      }
      if (shouldResetToTop3) {
        setSelectedModelIds(defaultTop3Ids);
      }
    }
  }, [models, gpt56Id, setSelectedModelIds]);

  const toggleModel = (id: string) => {
    sessionStorage.setItem('tiwen_landing_models_toggled', '1');
    setSelectedModelIds(
      selectedModelIds.includes(id)
        ? (selectedModelIds.length > 1 ? selectedModelIds.filter(m => m !== id) : selectedModelIds)
        : [...selectedModelIds, id]
    );
  };

  const handleAsk = async (queryText?: string) => {
    if (!isAuthenticated) {
      openAuthModal(
        locale === 'zh'
          ? '注册即可免费体验多模型并发对比,每日 4 次提问额度'
          : locale === 'zh-TW'
          ? '註冊即可免費體驗多模型並發對比，每日 4 次提問額度'
          : 'Sign up for free multi-model comparison, 4 questions daily'
      );
      return;
    }
    const q =
      (queryText || question).trim() ||
      (locale === 'zh'
        ? '为我的产品写一份上线运营与商业化方案'
        : locale === 'zh-TW'
        ? '為我的產品寫一份上線營運與商業化方案'
        : 'Write a product launch & GTM roadmap');
    const newId = await createSession(q);
    if (newId) {
      navigate(`/chat/${newId}`);
    }
  };

  const trendingList = [
    {
      id: 1,
      num: '1',
      title: t.landing.q1,
      tag: t.landing.q1Tag,
      heat: '23k',
      top: true
    },
    {
      id: 2,
      num: '2',
      title: t.landing.q2,
      tag: t.landing.q2Tag,
      heat: '18k',
      top: true
    },
    {
      id: 3,
      num: '3',
      title: t.landing.q3,
      tag: t.landing.q3Tag,
      heat: '15k',
      top: true
    },
    {
      id: 4,
      num: '4',
      title: t.landing.q4,
      tag: t.landing.q4Tag,
      heat: '12k',
      top: false
    },
    {
      id: 5,
      num: '5',
      title: t.landing.q5,
      tag: t.landing.q5Tag,
      heat: '9.8k',
      top: false
    }
  ];



  const modelMatrix = [
    { name: 'GPT-5.6 Luna', badge: 'Pro', color: '#0f766e', desc: '深度逻辑与工程方案主力', speed: '120 tok/s', ctx: '128k' },
    { name: 'Claude Opus 5', badge: 'Pro', color: '#b45309', desc: '长文研报与严谨推演', speed: '150 tok/s', ctx: '200k' },
    { name: 'Gemini 3.1 Pro', badge: '', color: '#0369a1', desc: '多模态快查与全球信息整合', speed: '180 tok/s', ctx: '1M' },
    { name: 'DeepSeek V4 Pro', badge: '', color: '#1d4ed8', desc: '数学与代码强力推理', speed: '140 tok/s', ctx: '64k' },
    { name: 'GLM 5.1', badge: '', color: '#047857', desc: '中文通用问答与常识', speed: '130 tok/s', ctx: '128k' },
    { name: 'Kimi K2.6', badge: '', color: '#be123c', desc: '超长文档研读', speed: '110 tok/s', ctx: '200k' }
  ];

  return (
    <div className="w-full max-w-[1400px] mx-auto px-3.5 sm:px-6 py-4 sm:py-6 space-y-10 sm:space-y-16 overflow-x-hidden">
      {/* =========================================================
          1. TOP HERO BANNER (SOLID BLUE CARD AS IN REFERENCE)
         ========================================================= */}
      <section className="bg-gradient-to-r from-[#1d63ff] via-[#1a5ceb] to-[#154fd8] text-white rounded-2xl sm:rounded-3xl p-5 sm:p-10 shadow-lg shadow-blue-500/10 space-y-5 sm:space-y-6 overflow-hidden">
        <div className="space-y-1.5 sm:space-y-2">
          <h1 className="text-xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight leading-tight">
            {t.landing.heroTitle}
          </h1>
          <p className="text-xs sm:text-sm text-white/90 font-normal leading-relaxed">
            {t.landing.heroSub}
          </p>
        </div>

        {/* White Input Container (Mobile: clean stacked / Desktop: rounded pill) */}
        <form
          onSubmit={e => {
            e.preventDefault();
            handleAsk();
          }}
          className="bg-white rounded-2xl sm:rounded-full p-1.5 pl-3.5 sm:pl-5 pr-1.5 sm:pr-2 shadow-md flex flex-col sm:flex-row items-stretch sm:items-center gap-2 sm:gap-3 transition-all focus-within:ring-4 focus-within:ring-white/30"
        >
          <input
            type="text"
            value={question}
            onChange={e => setQuestion(e.target.value)}
            placeholder={t.landing.heroPlaceholder}
            className="w-full min-w-0 flex-1 py-1.5 sm:py-2 text-xs sm:text-sm text-[#1b2230] placeholder:text-[#94a3b8] focus:outline-none bg-transparent font-medium"
          />

          {/* Action Row: Web search toggle + dynamic model labels + Ask button */}
          <div className="flex items-center justify-between sm:justify-end gap-2 shrink-0">
            {/* 联网模式开关 (在输入框内) */}
            <button
              type="button"
              onClick={() => setSmartSearch(!smartSearch)}
              aria-pressed={smartSearch}
              title={t.landing.webSearchHint || t.workbench.smartSearchHint}
              className={`inline-flex items-center gap-1.5 px-3 py-1.5 sm:py-2 rounded-full text-xs font-semibold whitespace-nowrap transition-all select-none cursor-pointer ${
                smartSearch
                  ? 'bg-blue-50 text-brand border border-blue-200/90 shadow-2xs'
                  : 'bg-slate-50 hover:bg-slate-100 text-[#64748b] hover:text-[#1b2230] border border-slate-200/80'
              }`}
            >
              <Globe className={`w-3.5 h-3.5 transition-colors ${smartSearch ? 'text-brand' : 'text-[#94a3b8]'}`} />
              <span>{t.landing.webSearch || '联网模式'}</span>
              {smartSearch && (
                <span className="w-1.5 h-1.5 rounded-full bg-brand" />
              )}
            </button>

            <button
              type="submit"
              className="w-auto px-5 py-2 sm:py-2.5 rounded-xl sm:rounded-full bg-brand hover:bg-brand-hover text-white font-bold text-xs sm:text-sm transition-colors flex items-center justify-center gap-1.5 shadow-xs shrink-0"
            >
              <span>{t.landing.askBtn}</span>
            </button>
          </div>
        </form>

        {/* Model Selector Pills Below Input(联动真实并发模型) */}
        <div className="flex flex-wrap items-center gap-1.5 sm:gap-2 pt-1 max-w-full">
          {heroModelOptions.map(opt => {
            const active = selectedModelIds.includes(opt.id);
            return (
              <button
                key={opt.id}
                type="button"
                onClick={() => toggleModel(opt.id)}
                className={`px-3 py-1 sm:px-3.5 sm:py-1.5 rounded-full text-xs font-bold transition-all ${
                  active
                    ? 'bg-white text-brand shadow-sm'
                    : 'bg-white/20 text-white/80 hover:bg-white/30'
                }`}
              >
                <span>{opt.label}</span>
              </button>
            );
          })}
        </div>
      </section>

      {/* =========================================================
          2. FREE TRIAL NOTICE BANNER (AMBER SOFT BAR)
          免费版展示(Pro/Team 已付费不再出现);次数与模型数为后台实时配置
         ========================================================= */}
      {(!user || user.plan === 'free') && (
        <section className="bg-[#fef6e7] border border-[#fde68a] rounded-2xl p-3 sm:p-3.5 px-4 sm:px-5 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2.5 sm:gap-3 text-xs shadow-2xs">
          <div className="flex items-center gap-2 text-[#7a5b10] font-medium text-left">
            <span>
              {t.landing.noticeText
                .replace('{asks}', freeDailyAsks === null ? '20' : freeDailyAsks > 0 ? `${freeDailyAsks} ${locale === 'en' ? '' : '次'}`.trim() : t.landing.noticeUnlimited)
                .replace('{models}', String(models.length || 18))}
            </span>
          </div>

          <Link
            to="/pricing"
            className="py-1.5 px-4 rounded-full bg-brand hover:bg-brand-hover text-white font-bold text-xs transition-colors text-center whitespace-nowrap shadow-2xs self-end sm:self-auto"
          >
            {t.landing.upgradeProBtn}
          </Link>
        </section>
      )}

      {/* =========================================================
          2.5 RECENT CHATS (登录态且有历史会话时显示,最多 5 条按时间倒序)
         ========================================================= */}
      {isAuthenticated && <RecentSessions limit={5} />}

      {/* =========================================================
          3. TODAY'S TRENDING QUESTIONS
         ========================================================= */}
      <section className="space-y-2.5 sm:space-y-3 min-w-0">
        <div className="flex items-center justify-between px-1">
          <h2 className="text-sm sm:text-base font-extrabold text-[#1b2230]">
            {t.landing.trendingTitle}
          </h2>
          <button
            onClick={() => handleAsk('更多热门行业分析课题')}
            className="text-xs text-[#65718a] hover:text-brand font-medium flex items-center gap-0.5 transition-colors"
          >
            <span>{t.landing.viewAll}</span>
          </button>
        </div>

        <div className="bg-white rounded-2xl border border-[#e7ebf3] shadow-subtle divide-y divide-[#f1f5f9] overflow-hidden">
          {trendingList.map(item => (
            <div
              key={item.id}
              onClick={() => handleAsk(item.title)}
              className="p-3.5 sm:p-4 px-3.5 sm:px-5 hover:bg-[#f8faff] transition-colors cursor-pointer flex items-center justify-between gap-2.5 sm:gap-3 group min-w-0"
            >
              <div className="flex items-center gap-2.5 sm:gap-3.5 min-w-0 flex-1">
                {/* Number Badge */}
                <span
                  className={`w-5 h-5 sm:w-6 sm:h-6 rounded-lg text-xs font-extrabold flex items-center justify-center shrink-0 ${
                    item.top
                      ? 'bg-brand text-white shadow-2xs'
                      : 'bg-[#f1f5f9] text-[#65718a]'
                  }`}
                >
                  {item.num}
                </span>

                {/* Question Title */}
                <span className="text-xs sm:text-sm font-semibold text-[#1b2230] group-hover:text-brand transition-colors truncate min-w-0 flex-1">
                  {item.title}
                </span>

                {/* Category Tag */}
                <span className="px-1.5 sm:px-2 py-0.5 rounded-full text-[10px] font-medium bg-blue-50 text-blue-600 shrink-0">
                  {item.tag}
                </span>
              </div>

              {/* Heat / View Count */}
              <span className="text-[11px] sm:text-xs font-mono text-[#8c97af] shrink-0">
                {item.heat}
              </span>
            </div>
          ))}
        </div>
      </section>

      {/* =========================================================
          4. FOUR CORE CAPABILITIES (BENTO SECTION)
         ========================================================= */}
      <section className="space-y-4 sm:space-y-6 pt-2 sm:pt-4">
        <div className="text-center max-w-2xl mx-auto space-y-1.5 sm:space-y-2">
          <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-subtle text-brand text-xs font-bold">
            <Sparkles className="w-3.5 h-3.5" />
            <span>核心推演能力</span>
          </div>
          <h2 className="text-xl sm:text-3xl font-extrabold text-[#0f172a] tracking-tight">
            {t.landing.whyTitle}
          </h2>
          <p className="text-xs sm:text-sm text-[#65718a]">
            {t.landing.whySub}
          </p>
        </div>

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5 sm:gap-5">
          {/* Card 1 */}
          <div className="bg-white rounded-2xl p-5 sm:p-6 border border-[#e7ebf3] shadow-subtle hover:border-brand/50 hover:shadow-md transition-all space-y-2.5">
            <div className="w-9 h-9 rounded-xl bg-blue-50 text-brand flex items-center justify-center font-bold">
              <Zap className="w-4 h-4 sm:w-5 sm:h-5" />
            </div>
            <h3 className="font-bold text-xs sm:text-sm text-[#0f172a]">{t.landing.cap1Title}</h3>
            <p className="text-xs text-[#65718a] leading-relaxed">
              {t.landing.cap1Desc}
            </p>
          </div>

          {/* Card 2 */}
          <div className="bg-white rounded-2xl p-5 sm:p-6 border border-[#e7ebf3] shadow-subtle hover:border-brand/50 hover:shadow-md transition-all space-y-2.5">
            <div className="w-9 h-9 rounded-xl bg-purple-50 text-purple-600 flex items-center justify-center font-bold">
              <Layers className="w-4 h-4 sm:w-5 sm:h-5" />
            </div>
            <h3 className="font-bold text-xs sm:text-sm text-[#0f172a]">{t.landing.cap2Title}</h3>
            <p className="text-xs text-[#65718a] leading-relaxed">
              {t.landing.cap2Desc}
            </p>
          </div>

          {/* Card 3 */}
          <div className="bg-white rounded-2xl p-5 sm:p-6 border border-[#e7ebf3] shadow-subtle hover:border-brand/50 hover:shadow-md transition-all space-y-2.5">
            <div className="w-9 h-9 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center font-bold">
              <Sparkles className="w-4 h-4 sm:w-5 sm:h-5" />
            </div>
            <h3 className="font-bold text-xs sm:text-sm text-[#0f172a]">{t.landing.cap3Title}</h3>
            <p className="text-xs text-[#65718a] leading-relaxed">
              {t.landing.cap3Desc}
            </p>
          </div>

          {/* Card 4 */}
          <div className="bg-white rounded-2xl p-5 sm:p-6 border border-[#e7ebf3] shadow-subtle hover:border-brand/50 hover:shadow-md transition-all space-y-2.5">
            <div className="w-9 h-9 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center font-bold">
              <FileText className="w-4 h-4 sm:w-5 sm:h-5" />
            </div>
            <h3 className="font-bold text-xs sm:text-sm text-[#0f172a]">{t.landing.cap4Title}</h3>
            <p className="text-xs text-[#65718a] leading-relaxed">
              {t.landing.cap4Desc}
            </p>
          </div>
        </div>
      </section>

      {/* =========================================================
          5. SUPPORTED MODEL MATRIX (ECOSYSTEM TILES)
         ========================================================= */}
      <section className="bg-white rounded-2xl sm:rounded-3xl p-5 sm:p-9 border border-[#e7ebf3] shadow-subtle space-y-4 sm:space-y-6">
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-[#f1f5f9] pb-3 sm:pb-4">
          <div>
            <h2 className="text-base sm:text-lg font-bold text-[#0f172a] flex items-center gap-2">
              <Cpu className="w-4 h-4 sm:w-5 sm:h-5 text-brand" />
              <span>{t.landing.modelsTitle}</span>
            </h2>
            <p className="text-xs text-[#65718a] mt-0.5">{t.landing.modelsSub}</p>
          </div>
          <span className="text-xs font-semibold text-brand flex items-center gap-1 self-start sm:self-auto">
            <ShieldCheck className="w-4 h-4 text-emerald-500" />
            <span>原生 API 零中转</span>
          </span>
        </div>

        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5 sm:gap-3.5">
          {modelMatrix.map(m => (
            <div
              key={m.name}
              className="p-3 rounded-xl border border-[#e7ebf3] hover:border-brand bg-[#fafcff] space-y-1.5 transition-all group min-w-0"
            >
              <div className="flex items-center justify-between">
                <span className="w-2.5 h-2.5 rounded-full" style={{ backgroundColor: m.color }}></span>
                {m.badge && (
                  <span className="text-[9px] sm:text-[10px] font-extrabold bg-blue-100 text-blue-800 px-1.5 py-0.2 rounded">
                    {m.badge}
                  </span>
                )}
              </div>
              <div className="font-bold text-xs text-[#1b2230] group-hover:text-brand transition-colors truncate">
                {m.name}
              </div>
              <p className="text-[10px] sm:text-[11px] text-[#65718a] line-clamp-2 leading-tight">
                {m.desc}
              </p>
              <div className="pt-1.5 border-t border-[#f1f5f9] flex justify-between text-[10px] text-[#8c97af] font-mono">
                <span>{m.speed}</span>
                <span>{m.ctx}</span>
              </div>
            </div>
          ))}
        </div>
      </section>

      {/* =========================================================
          6. REAL WORKFLOW TESTIMONIALS
         ========================================================= */}
      <section className="space-y-4 sm:space-y-6">
        <div className="text-center max-w-xl mx-auto space-y-1.5 sm:space-y-2">
          <h2 className="text-lg sm:text-2xl font-extrabold text-[#0f172a]">
            {t.landing.useCasesTitle}
          </h2>
          <p className="text-xs sm:text-sm text-[#65718a]">
            {t.landing.useCasesSub}
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-3.5 sm:gap-5">
          <div className="bg-white rounded-2xl p-4 sm:p-5 border border-[#e7ebf3] shadow-subtle flex flex-col justify-between space-y-2.5">
            <Quote className="w-5 h-5 text-blue-200" />
            <p className="text-xs text-[#334155] leading-relaxed">
              "{t.landing.uc1Quote}"
            </p>
            <div className="pt-2.5 border-t border-[#f1f5f9] flex items-center gap-2">
              <div className="w-6 h-6 rounded-full bg-blue-100 text-blue-700 font-bold text-xs flex items-center justify-center">
                A
              </div>
              <span className="text-xs font-bold text-[#1b2230]">{t.landing.uc1Role}</span>
            </div>
          </div>

          <div className="bg-white rounded-2xl p-4 sm:p-5 border border-[#e7ebf3] shadow-subtle flex flex-col justify-between space-y-2.5">
            <Quote className="w-5 h-5 text-purple-200" />
            <p className="text-xs text-[#334155] leading-relaxed">
              "{t.landing.uc2Quote}"
            </p>
            <div className="pt-2.5 border-t border-[#f1f5f9] flex items-center gap-2">
              <div className="w-6 h-6 rounded-full bg-purple-100 text-purple-700 font-bold text-xs flex items-center justify-center">
                E
              </div>
              <span className="text-xs font-bold text-[#1b2230]">{t.landing.uc2Role}</span>
            </div>
          </div>

          <div className="bg-white rounded-2xl p-4 sm:p-5 border border-[#e7ebf3] shadow-subtle flex flex-col justify-between space-y-2.5">
            <Quote className="w-5 h-5 text-amber-200" />
            <p className="text-xs text-[#334155] leading-relaxed">
              "{t.landing.uc3Quote}"
            </p>
            <div className="pt-2.5 border-t border-[#f1f5f9] flex items-center gap-2">
              <div className="w-6 h-6 rounded-full bg-amber-100 text-amber-700 font-bold text-xs flex items-center justify-center">
                C
              </div>
              <span className="text-xs font-bold text-[#1b2230]">{t.landing.uc3Role}</span>
            </div>
          </div>
        </div>
      </section>

      {/* =========================================================
          7. BOTTOM HIGH-CONVERSION CTA BANNER
         ========================================================= */}
      <section className="bg-gradient-to-r from-brand via-indigo-600 to-blue-700 text-white rounded-2xl sm:rounded-3xl p-6 sm:p-12 text-center space-y-5 sm:space-y-6 shadow-xl relative overflow-hidden">
        <div className="max-w-xl mx-auto space-y-2">
          <h2 className="text-xl sm:text-3xl lg:text-4xl font-extrabold tracking-tight">
            {t.landing.bottomCtaTitle}
          </h2>
          <p className="text-xs sm:text-sm text-white/85">
            {t.landing.bottomCtaSub}
          </p>
        </div>

        <div className="flex flex-col sm:flex-row items-center justify-center gap-3 pt-1">
          <button
            onClick={() => handleAsk('为我的团队生成一份 2026 年生成式 AI 落地规划')}
            className="w-full sm:w-auto px-7 py-3 rounded-xl bg-white text-brand font-bold text-xs sm:text-sm hover:bg-slate-50 transition-colors shadow-md flex items-center justify-center gap-2"
          >
            <span>{t.landing.bottomCtaBtn}</span>
            <ArrowRight className="w-4 h-4" />
          </button>

          <Link
            to="/pricing"
            className="w-full sm:w-auto px-6 py-3 rounded-xl bg-white/10 hover:bg-white/20 border border-white/30 text-white font-semibold text-xs sm:text-sm transition-colors text-center"
          >
            {t.landing.bottomPricingBtn}
          </Link>
        </div>
      </section>
    </div>
  );
};
