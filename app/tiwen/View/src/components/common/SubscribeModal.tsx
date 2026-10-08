import React, { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useSession } from '../../context/SessionContext';
import { useAuth } from '../../context/AuthContext';
import { useLanguage } from '../../context/LanguageContext';import { PRICING_PLANS } from '../../mock/initialData';
import { fetchAppConfig } from '../../api/models';
import { api } from '../../api/client';
import { PricingPlan } from '../../types';
import {
  X,
  Sparkles,
  Zap,
  Check,
  ShieldCheck,
  ArrowRight,
  Loader2,
  SlidersHorizontal,
  AlertTriangle
} from 'lucide-react';

export const SubscribeModal: React.FC = () => {
  const navigate = useNavigate();
  const {
    isSubscribeModalOpen,
    subscribeModalReason,
    subscribeModalQuota,
    closeSubscribeModal,
    selectedModelIds,
    setSelectedModelIds,
    showToast
  } = useSession();
  const { isAuthenticated, openAuthModal } = useAuth();
  const { t, locale } = useLanguage();
  const zh = locale === 'zh';

  const [isYearly, setIsYearly] = useState(false);
  const [plans, setPlans] = useState<PricingPlan[]>(PRICING_PLANS);
  const [symbol, setSymbol] = useState('$');
  const [loadingCheckout, setLoadingCheckout] = useState(false);

  // 同步共享缓存中的套餐价格配置
  useEffect(() => {
    let cancelled = false;
    fetchAppConfig().then(cfg => {
      if (cancelled) return;
      if (cfg?.currency_symbol) setSymbol(cfg.currency_symbol);
      if (!cfg?.plans) return;
      const remote = cfg.plans;
      setPlans(PRICING_PLANS.map((base) => {
        const r = remote[base.id];
        if (!r) return base;
        return {
          ...base,
          name: r.name || base.name,
          priceMonthly: typeof r.price_monthly === 'number' ? r.price_monthly : base.priceMonthly,
          priceYearly: typeof r.price_yearly === 'number' ? r.price_yearly : base.priceYearly,
          enabled: base.id === 'free' ? true : r.enabled !== false,
          features: Array.isArray(r.features) && r.features.length > 0 ? r.features : base.features,
        };
      }));
    });
    return () => { cancelled = true; };
  }, []);

  if (!isSubscribeModalOpen) return null;

  const proPlan = plans.find(p => p.id === 'pro') || PRICING_PLANS[1];
  const proPrice = isYearly ? proPlan.priceYearly : proPlan.priceMonthly;

  // 真实剩余额度(roundCreate 402 返回):免费版按并发模型数计次
  const quotaRemaining = subscribeModalQuota?.plan === 'free' && typeof subscribeModalQuota?.remaining === 'number'
    ? Math.max(0, subscribeModalQuota.remaining)
    : null;
  // 按剩余额度精确计算可保留的并发模型数;剩余 0 次时减少模型也无法提问,必须隐藏入口
  const reduceTarget = quotaRemaining !== null && quotaRemaining >= 1
    ? Math.min(selectedModelIds.length, quotaRemaining)
    : null;
  const canReduce = reduceTarget !== null && reduceTarget < selectedModelIds.length;

  // 立即发起 Stripe Checkout 订阅
  const handleUpgradePro = async () => {
    if (!isAuthenticated) {
      closeSubscribeModal();
      openAuthModal(
        locale === 'zh'
          ? '升级套餐前请先登录您的账户'
          : locale === 'zh-TW'
          ? '升級方案前請先登入您的帳號'
          : 'Please log in before upgrading your subscription'
      );
      return;
    }

    setLoadingCheckout(true);
    const res = await api<{ url: string }>('/tiwen/checkout', {
      method: 'POST',
      json: { plan: 'pro', interval: isYearly ? 'year' : 'month' },
    });
    setLoadingCheckout(false);

    if (!res.ok || !res.data?.url) {
      showToast(
        res.message ||
          (locale === 'zh'
            ? '创建支付会话失败,请稍后重试'
            : locale === 'zh-TW'
            ? '建立支付工作階段失敗，請稍後重試'
            : 'Failed to create checkout session')
      );
      return;
    }
    window.location.href = res.data.url;
  };

  // 按剩余额度减少并发模型,减完即可继续提问
  const handleReduceModels = () => {
    if (reduceTarget === null || reduceTarget >= selectedModelIds.length) {
      closeSubscribeModal();
      return;
    }
    setSelectedModelIds(selectedModelIds.slice(0, reduceTarget));
    showToast(
      locale === 'zh'
        ? `已将并发模型减少为 ${reduceTarget} 个，与剩余额度匹配，可继续提问`
        : locale === 'zh-TW'
        ? `已將並發模型減少為 ${reduceTarget} 個，與剩餘額度相符，可繼續提問`
        : `Reduced to ${reduceTarget} models to match your remaining quota`
    );
    closeSubscribeModal();
  };

  const handleGoToPricing = () => {
    closeSubscribeModal();
    navigate('/pricing');
  };

  return (
    <div
      className="fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-3.5 sm:p-5 overflow-y-auto"
      onClick={closeSubscribeModal}
    >
      <div
        className="bg-white w-full max-w-lg rounded-3xl shadow-2xl border border-[#e2e8f0] overflow-hidden my-auto relative transition-all"
        onClick={e => e.stopPropagation()}
      >
        {/* Close Button */}
        <button
          onClick={closeSubscribeModal}
          className="absolute top-4 right-4 z-10 w-8 h-8 rounded-full bg-black/10 hover:bg-black/20 text-white flex items-center justify-center transition-colors"
          title="关闭"
        >
          <X className="w-4 h-4" />
        </button>

        {/* Top Gradient Header */}
        <div className="bg-gradient-to-r from-[#1d63ff] via-[#1a5ceb] to-[#154fd8] text-white p-5 sm:p-6 pb-6 relative overflow-hidden">
          {/* Subtle Background Glow */}
          <div className="absolute -right-10 -bottom-10 w-40 h-40 bg-white/10 rounded-full blur-2xl pointer-events-none" />

          <div className="space-y-2 relative z-1">
            <div className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-white/20 text-white text-[11px] font-bold backdrop-blur-xs">
              <Sparkles className="w-3.5 h-3.5 text-amber-300" />
              <span>{t.subscribeModal?.badge || '额度已用尽'}</span>
            </div>
            <h2 className="text-xl sm:text-2xl font-extrabold tracking-tight">
              {t.subscribeModal?.title || '升级 Pro 解锁无限多模型推演'}
            </h2>
            <p className="text-xs sm:text-sm text-white/90 leading-relaxed">
              {t.subscribeModal?.subtitle || '告别额度限制，畅享全模型并发、多轮深度追问与智能研报交叉提炼'}
            </p>
          </div>
        </div>

        <div className="p-5 sm:p-6 space-y-5">
          {/* Reason Alert Box */}
          {subscribeModalReason && (
            <div className="bg-[#fffbeb] border border-[#fef3c7] rounded-2xl p-3.5 flex items-start gap-2.5 text-xs text-[#92400e]">
              <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0 mt-0.5" />
              <div className="min-w-0 leading-relaxed font-medium">
                {subscribeModalReason}
              </div>
            </div>
          )}

          {/* 真实剩余额度(roundCreate 402 返回) */}
          {quotaRemaining !== null && (
            <div className="bg-[#f8fafc] border border-[#e2e8f0] rounded-2xl px-3.5 py-3 flex items-center justify-between text-xs text-[#475569]">
              <span className="font-semibold">
                {locale === 'zh' ? '今日剩余免费额度' : locale === 'zh-TW' ? '今日剩餘免費額度' : 'Remaining free asks today'}
              </span>
              <span className="font-mono font-bold text-[#0f172a]">
                {quotaRemaining} {locale === 'en' ? 'left' : '次'} ·{' '}
                {locale === 'zh'
                  ? `当前并发 ${selectedModelIds.length} 个模型需 ${selectedModelIds.length} 次`
                  : locale === 'zh-TW'
                  ? `當前並發 ${selectedModelIds.length} 個模型需 ${selectedModelIds.length} 次`
                  : `${selectedModelIds.length} models = ${selectedModelIds.length} asks`}
              </span>
            </div>
          )}

          {/* Pro Plan Highlight Card */}
          <div className="rounded-2xl border-2 border-brand/40 bg-gradient-to-b from-blue-50/50 to-transparent p-4 sm:p-5 space-y-4 shadow-xs">
            <div className="flex items-center justify-between">
              <div>
                <div className="flex items-center gap-2">
                  <h3 className="font-extrabold text-base sm:text-lg text-[#0f172a]">
                    {proPlan.name}
                  </h3>
                  <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-brand text-white shadow-2xs">
                    {t.pricing.popularBadge}
                  </span>
                </div>
                <p className="text-xs text-[#64748b] mt-0.5">{proPlan.tagline}</p>
              </div>

              {/* Monthly / Yearly Switch */}
              <div className="bg-[#f1f5f9] p-1 rounded-xl flex items-center gap-1 text-[11px] font-bold shrink-0">
                <button
                  type="button"
                  onClick={() => setIsYearly(false)}
                  className={`px-2.5 py-1 rounded-lg transition-all ${
                    !isYearly ? 'bg-white text-brand shadow-xs' : 'text-[#64748b] hover:text-[#0f172a]'
                  }`}
                >
                  月付
                </button>
                <button
                  type="button"
                  onClick={() => setIsYearly(true)}
                  className={`px-2.5 py-1 rounded-lg transition-all flex items-center gap-1 ${
                    isYearly ? 'bg-brand text-white shadow-xs' : 'text-[#64748b] hover:text-[#0f172a]'
                  }`}
                >
                  <span>年付</span>
                  <span className={`text-[9px] px-1 py-0.2 rounded font-extrabold ${isYearly ? 'bg-white text-brand' : 'bg-emerald-100 text-emerald-700'}`}>
                    省20%
                  </span>
                </button>
              </div>
            </div>

            {/* Price Display */}
            <div className="flex items-baseline gap-1.5 pt-1 border-t border-[#e2e8f0]">
              <span className="text-2xl sm:text-3xl font-black text-brand tracking-tight">
                {symbol}{proPrice}
              </span>
              <span className="text-xs font-semibold text-[#64748b]">
                {isYearly ? t.pricing.perYear : t.pricing.perMonth}
              </span>
              {isYearly && (
                <span className="ml-auto text-[11px] font-medium text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md">
                  {t.pricing.savePerMonth.replace('{s}', symbol).replace('{p}', (proPrice / 12).toFixed(1))}
                </span>
              )}
            </div>

            {/* Benefit Bullets */}
            <div className="space-y-2 pt-1 text-xs text-[#334155]">
              <div className="flex items-center gap-2">
                <div className="w-4 h-4 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                  <Check className="w-3 h-3 stroke-[2.5]" />
                </div>
                <span><strong>每月 1,000,000 Token</strong> 高速并发配额</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="w-4 h-4 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                  <Check className="w-3 h-3 stroke-[2.5]" />
                </div>
                <span><strong>18 个顶尖模型全解锁</strong> (GPT-5.6 / Claude Opus / Gemini / DeepSeek / Kimi)</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="w-4 h-4 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                  <Check className="w-3 h-3 stroke-[2.5]" />
                </div>
                <span><strong>最多 6 模型</strong> 同时并发深度对比</span>
              </div>
              <div className="flex items-center gap-2">
                <div className="w-4 h-4 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center shrink-0">
                  <Check className="w-3 h-3 stroke-[2.5]" />
                </div>
                <span>⚡ <strong>智能交叉分析综合</strong> + 四合一研报导出</span>
              </div>
            </div>

            {/* Primary Upgrade CTA Button */}
            <button
              onClick={handleUpgradePro}
              disabled={loadingCheckout}
              className="w-full py-3 px-4 rounded-xl bg-brand hover:bg-brand-hover text-white font-extrabold text-sm transition-all flex items-center justify-center gap-2 shadow-sm hover:shadow-md active:scale-[0.99] disabled:opacity-75 disabled:cursor-not-allowed"
            >
              {loadingCheckout ? (
                <>
                  <Loader2 className="w-4 h-4 animate-spin" />
                  <span>{t.pricing.connectingStripe}</span>
                </>
              ) : (
                <>
                  <Zap className="w-4 h-4 fill-current" />
                  <span>{t.subscribeModal?.upgradeNow || '立即升级 Pro 套餐'}</span>
                  <ArrowRight className="w-4 h-4" />
                </>
              )}
            </button>
          </div>

          {/* Secondary Actions Row */}
          <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-1">
            {canReduce && reduceTarget !== null && (
              <button
                type="button"
                onClick={handleReduceModels}
                className="w-full sm:w-auto px-4 py-2 rounded-xl border border-[#cbd5e1] hover:border-brand/40 bg-white hover:bg-slate-50 text-[#334155] font-semibold text-xs transition-colors flex items-center justify-center gap-1.5"
              >
                <SlidersHorizontal className="w-3.5 h-3.5 text-[#64748b]" />
                <span>{t.subscribeModal?.reduceModelsAction || '减少并发模型'} ({selectedModelIds.length} → {reduceTarget})</span>
              </button>
            )}
            {quotaRemaining === 0 && (
              <span className="text-xs text-[#94a3b8] font-medium">
                {locale === 'zh'
                  ? '今日免费额度已用完，减少模型也无法提问，明日自动重置'
                  : locale === 'zh-TW'
                  ? '今日免費額度已用完，減少模型也無法提問，明日自動重設'
                  : "Daily free quota used up — reducing models won't help, resets tomorrow"}
              </span>
            )}

            <button
              type="button"
              onClick={handleGoToPricing}
              className="text-xs text-brand hover:underline font-semibold flex items-center gap-1 py-1.5"
            >
              <span>{t.subscribeModal?.viewAllPlans || '查看全部套餐与权益'}</span>
              <ArrowRight className="w-3 h-3" />
            </button>
          </div>

          {/* Trust Guarantee */}
          <div className="pt-2 border-t border-[#f1f5f9] flex items-center justify-center gap-2 text-[11px] text-[#94a3b8] text-center">
            <ShieldCheck className="w-3.5 h-3.5 text-emerald-500 shrink-0" />
            <span>{t.pricing.encryptionTip}</span>
          </div>
        </div>
      </div>
    </div>
  );
};
