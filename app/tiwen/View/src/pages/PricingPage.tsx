import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useSession } from '../context/SessionContext';
import { useLanguage } from '../context/LanguageContext';
import { PRICING_PLANS } from '../mock/initialData';
import { api } from '../api/client';
import { applyRemotePlans, fetchAppConfig, planFeaturesOf, planNameOf, PlanLocale } from '../api/models';
import { PricingPlan } from '../types';
import {
  Check,
  Sparkles,
  Shield,
  CreditCard,
  Zap,
  Loader2,
  ArrowRight,
  Lock,
  Gem,
  Infinity as InfinityIcon
} from 'lucide-react';

function formatFeatureText(text: string): React.ReactNode {
  if (text.includes('**')) {
    const parts = text.split(/(\*\*.*?\*\*)/g);
    return parts.map((part, idx) => {
      if (part.startsWith('**') && part.endsWith('**')) {
        return (
          <strong key={idx} className="font-bold text-[#0f172a]">
            {part.slice(2, -2)}
          </strong>
        );
      }
      return part;
    });
  }

  if (text.includes(': ') || text.includes('：')) {
    const splitChar = text.includes(': ') ? ': ' : '：';
    const [prefix, ...rest] = text.split(splitChar);
    return (
      <>
        <strong className="font-bold text-[#0f172a]">
          {prefix}{splitChar}
        </strong>
        <span>{rest.join(splitChar)}</span>
      </>
    );
  }

  const arriveMatch = text.match(/^(到账\s*[$¥€£]?\d+[\s\w]*[积分|點數|credits])(\s*\(.*?\))$/i);
  if (arriveMatch) {
    return (
      <>
        <strong className="font-bold text-[#0f172a]">{arriveMatch[1]}</strong>
        <span>{arriveMatch[2]}</span>
      </>
    );
  }

  return text;
}

export const PricingPage: React.FC = () => {
  const navigate = useNavigate();
  const { user, isAuthenticated, openAuthModal } = useAuth();
  const { showToast } = useSession();
  const { t, locale } = useLanguage();
  const [isYearly, setIsYearly] = useState(false);
  const [redirectingPlan, setRedirectingPlan] = useState<string | null>(null);
  const [plans, setPlans] = useState<PricingPlan[]>(PRICING_PLANS);
  const [symbol, setSymbol] = useState('$');
  const lang = locale as PlanLocale;
  const zh = locale === 'zh';

  // 拉取共享缓存套餐配置(自动去重并发请求)
  useEffect(() => {
    let cancelled = false;
    fetchAppConfig().then(cfg => {
      if (cancelled) return;
      if (cfg?.currency_symbol) setSymbol(cfg.currency_symbol);
      if (!cfg?.plans) return;
      setPlans(applyRemotePlans(PRICING_PLANS, cfg.plans));
    });
    return () => { cancelled = true; };
  }, []);

  const handleSubscribe = async (planId: string, isOneTime = false) => {
    const target = plans.find((p) => p.id === planId);
    if (target?.enabled === false) {
      showToast(t.pricing.planDisabledTip);
      return;
    }
    if (planId === 'free') {
      navigate('/chat');
      return;
    }

    if (!isAuthenticated) {
      openAuthModal(
        locale === 'zh'
          ? '升级套餐前请先登录您的账户'
          : locale === 'zh-TW'
          ? '升級方案前請先登入您的帳號'
          : 'Please log in before upgrading your subscription'
      );
      return;
    }

    setRedirectingPlan(planId);

    // 创建 Stripe Checkout 会话并跳转托管收银台(一次性包为单笔支付,不传周期)
    const res = await api<{ url: string }>('/tiwen/checkout', {
      method: 'POST',
      json: isOneTime ? { plan: planId } : { plan: planId, interval: isYearly ? 'year' : 'month' },
    });
    if (!res.ok || !res.data?.url) {
      setRedirectingPlan(null);
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

  // 所有上架套餐(下架套餐不展示，全部在同一行排列，一次性买断不换行)
  const activePlans = plans.filter((p) => p.enabled !== false);

  return (
    <div className="max-w-[1400px] mx-auto px-4 sm:px-6 py-10 space-y-12">
      {/* Page Title Header */}
      <div className="text-center max-w-2xl mx-auto space-y-3">
        <div className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-brand-subtle text-brand text-xs font-bold tracking-wide">
          <Sparkles className="w-3.5 h-3.5" />
          <span>{t.pricing.badge}</span>
        </div>
        <h1 className="text-3xl sm:text-4xl font-extrabold text-[#0f172a] tracking-tight">
          {t.pricing.title}
        </h1>
        <p className="text-sm sm:text-base text-[#64748b]">
          {t.pricing.subtitle}
        </p>

        {/* Monthly / Yearly Toggle */}
        <div className="pt-4 flex items-center justify-center gap-3">
          <span className={`text-xs font-semibold ${!isYearly ? 'text-[#0f172a]' : 'text-[#64748b]'}`}>
            {t.pricing.monthly}
          </span>
          <button
            onClick={() => setIsYearly(!isYearly)}
            className="relative w-12 h-6 bg-[#cbd5e1] rounded-full p-0.5 transition-colors focus:outline-none focus:ring-2 focus:ring-brand"
            style={{ backgroundColor: isYearly ? '#2f6bff' : '#cbd5e1' }}
          >
            <div
              className={`w-5 h-5 bg-white rounded-full shadow-md transform transition-transform ${
                isYearly ? 'translate-x-6' : 'translate-x-0'
              }`}
            />
          </button>
          <span className={`text-xs font-semibold flex items-center gap-1 ${isYearly ? 'text-[#0f172a]' : 'text-[#64748b]'}`}>
            <span>{t.pricing.yearly}</span>
            <span className="px-2 py-0.5 rounded-full text-[10px] font-extrabold bg-emerald-100 text-emerald-700">
              {t.pricing.save20}
            </span>
          </span>
        </div>
      </div>

      {/* 所有套餐卡片网格: 同一行并排排列 (一次性买断不换行) */}
      {activePlans.length > 0 && (
        <div
          className={`grid grid-cols-1 ${
            activePlans.length === 1
              ? 'max-w-md mx-auto'
              : activePlans.length === 2
              ? 'md:grid-cols-2 max-w-3xl mx-auto'
              : activePlans.length === 3
              ? 'md:grid-cols-3 max-w-5xl mx-auto'
              : 'md:grid-cols-2 lg:grid-cols-4 max-w-[1400px] mx-auto'
          } gap-6 items-stretch`}
        >
          {activePlans.map((plan) => {
            const isOneTime = plan.type === 'one_time';
            const planName = planNameOf(plan, lang);
            const planFeatures = planFeaturesOf(plan, lang);

            if (isOneTime) {
              const meta = plan.oneTime;
              const originalPrice = meta?.credits && meta.credits > meta.price
                ? meta.credits
                : meta && meta.price < 999
                ? 999
                : null;

              // 顶部徽标文案: 优先展示「一次买断 · 送 12 个月 Pro 会员」
              const topBadge = meta?.giftProMonths
                ? (locale === 'zh'
                    ? `一次买断 · 送 ${meta.giftProMonths} 个月 Pro 会员`
                    : locale === 'zh-TW'
                    ? `一次買斷 · 送 ${meta.giftProMonths} 個月 Pro 会员`
                    : `One-time · ${meta.giftProMonths} Mo Pro Included`)
                : plan.badge || t.pricing.oneTimeBadge;

              return (
                <div
                  key={plan.id}
                  className="relative bg-white rounded-3xl border-2 border-[#b8741a] p-6 sm:p-7 shadow-sm hover:shadow-md transition-all flex flex-col justify-between"
                >
                  {/* 顶部悬浮居中徽标 */}
                  <div className="absolute -top-3 left-1/2 -translate-x-1/2 bg-[#b8741a] text-white text-[11px] font-bold px-3.5 py-0.5 rounded-full shadow-sm whitespace-nowrap z-10 select-none">
                    {topBadge}
                  </div>

                  <div>
                    {/* 套餐名称 */}
                    <div className="flex items-center justify-between mb-2">
                      <h3 className="text-lg sm:text-xl font-black text-[#0f172a] tracking-tight">
                        {planName}
                      </h3>
                    </div>

                    {/* 副标题 */}
                    <p className="text-xs text-[#64748b] min-h-[34px] leading-relaxed">
                      {plan.tagline}
                    </p>

                    {/* 价格区块 (绝对不折行) */}
                    <div className="my-6 pb-6 border-b border-[#f1f5f9]">
                      <div className="flex items-baseline gap-1 flex-nowrap whitespace-nowrap">
                        <span className="text-xl sm:text-2xl font-bold text-[#0f172a] font-mono leading-none">
                          {symbol}
                        </span>
                        <span className="text-3xl sm:text-4xl font-extrabold text-[#0f172a] font-mono tracking-tight leading-none">
                          {meta ? meta.price : plan.priceMonthly}
                        </span>
                        <span className="text-xs font-semibold text-[#64748b] ml-0.5 shrink-0">
                          {locale === 'zh' ? '一次性' : locale === 'zh-TW' ? '一次性' : 'One-time'}
                        </span>
                        {originalPrice && (
                          <span className="text-xs sm:text-sm font-semibold text-[#94a3b8] line-through ml-1 font-mono shrink-0">
                            {symbol}{originalPrice}
                          </span>
                        )}
                      </div>
                      <p className="text-[11px] text-[#64748b] mt-1 font-medium whitespace-nowrap overflow-hidden text-ellipsis">
                        {meta?.validityMonths
                          ? (locale === 'zh'
                              ? `积分 ${meta.validityMonths} 个月有效 · 不订阅也可用`
                              : locale === 'zh-TW'
                              ? `點數 ${meta.validityMonths} 個月有效 · 不訂閱也可用`
                              : `Credits valid for ${meta.validityMonths} mo · No sub needed`)
                          : t.pricing.oneTimePriceNote}
                      </p>
                    </div>

                    {/* 购买按钮 (居于价格下方) */}
                    <button
                      onClick={() => handleSubscribe(plan.id, true)}
                      disabled={redirectingPlan !== null}
                      className="w-full py-3 px-4 rounded-xl font-bold text-xs sm:text-sm bg-[#b8741a] hover:bg-[#a36413] active:bg-[#8f560e] text-white shadow-xs transition-all flex items-center justify-center gap-2 mb-6 cursor-pointer active:scale-[0.99] disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                      {redirectingPlan === plan.id ? (
                        <>
                          <Loader2 className="w-4 h-4 animate-spin" />
                          <span>{t.pricing.connectingStripe}</span>
                        </>
                      ) : (
                        <span>
                          {locale === 'zh' ? `购买${planName}` : locale === 'zh-TW' ? `購買${planName}` : `Purchase ${planName}`}
                        </span>
                      )}
                    </button>

                    {/* 权益清单 */}
                    <div className="space-y-3 mb-2">
                      {planFeatures.map((feat, i) => {
                        const isNote = feat.startsWith('—') || feat.startsWith('-');
                        const cleanText = feat.replace(/^[—\-]\s*/, '');
                        return (
                          <div
                            key={i}
                            className={`flex items-start gap-2 text-xs ${
                              isNote ? 'text-[#64748b]' : 'text-[#0f172a]'
                            }`}
                          >
                            {isNote ? (
                              <span className="text-[#64748b] font-bold text-sm leading-none select-none mt-0.5 shrink-0">
                                —
                              </span>
                            ) : (
                              <span className="text-[#b8741a] font-bold text-sm leading-none select-none mt-0.5 shrink-0">
                                ✓
                              </span>
                            )}
                            <span className="leading-relaxed">
                              {formatFeatureText(cleanText)}
                            </span>
                          </div>
                        );
                      })}
                    </div>
                  </div>
                </div>
              );
            }

            // 订阅套餐
            const isCurrentPlan = user?.plan === plan.id;
            const isPopular = plan.popular;
            const price = isYearly ? plan.priceYearly : plan.priceMonthly;

            return (
              <div
                key={plan.id}
                className={`bg-white rounded-3xl p-6 sm:p-7 border flex flex-col justify-between transition-all relative ${
                  isPopular
                    ? 'border-brand ring-2 ring-brand-subtle shadow-xl -translate-y-1'
                    : 'border-[#e2e8f0] shadow-sm hover:border-[#cbd5e1]'
                }`}
              >
                {/* Popular Badge */}
                {isPopular && (
                  <div className="absolute -top-3 left-1/2 -translate-x-1/2 bg-gradient-to-r from-brand to-indigo-600 text-white text-[11px] font-bold px-3 py-0.5 rounded-full shadow-sm flex items-center gap-1">
                    <Sparkles className="w-3 h-3 text-amber-300" />
                    <span>{t.pricing.popularBadge}</span>
                  </div>
                )}

                <div>
                  <div className="flex items-center justify-between mb-2">
                    <h3 className="text-lg font-bold text-[#0f172a]">{planName}</h3>
                    {!isPopular && plan.badge && (
                      <span className="text-[10px] font-medium px-2 py-0.5 bg-[#f1f5f9] text-[#64748b] rounded-full">
                        {plan.badge}
                      </span>
                    )}
                  </div>
                  <p className="text-xs text-[#64748b] min-h-[34px]">{plan.tagline}</p>

                  {/* Price Display */}
                  <div className="my-6 pb-6 border-b border-[#f1f5f9]">
                    <div className="flex items-baseline gap-1">
                      <span className="text-3xl sm:text-4xl font-extrabold text-[#0f172a] font-mono">
                        {symbol}{price}
                      </span>
                      <span className="text-xs text-[#64748b]">
                        {plan.id === 'free' ? t.pricing.permanent : isYearly ? t.pricing.perYear : t.pricing.perMonth}
                      </span>
                    </div>
                    {isYearly && plan.id !== 'free' && (
                      <p className="text-[11px] text-emerald-600 font-medium mt-1">
                        {t.pricing.savePerMonth.replace('{s}', symbol).replace('{p}', (price / 12).toFixed(1))}
                      </p>
                    )}
                  </div>

                  {/* Features list */}
                  <div className="space-y-3 mb-8">
                    <p className="text-xs font-bold text-[#334155] uppercase tracking-wider">{t.pricing.benefitsInclude}</p>
                    {planFeatures.map((feat, i) => (
                      <div key={i} className="flex items-start gap-2 text-xs text-[#475569]">
                        <div className="w-4 h-4 rounded-full flex items-center justify-center shrink-0 mt-0.5 bg-emerald-50 text-emerald-600">
                          <Check className="w-2.5 h-2.5 stroke-[3]" />
                        </div>
                        <span>{feat}</span>
                      </div>
                    ))}
                  </div>
                </div>

                {/* Action CTA Button */}
                <div>
                  <button
                    onClick={() => handleSubscribe(plan.id)}
                    disabled={isCurrentPlan || redirectingPlan !== null}
                    className={`w-full py-3 px-4 rounded-xl font-bold text-xs sm:text-sm flex items-center justify-center gap-2 transition-all shadow-xs ${
                      isCurrentPlan
                        ? 'bg-[#f1f5f9] text-[#94a3b8] cursor-not-allowed'
                        : isPopular
                        ? 'bg-brand text-white hover:bg-brand-hover ring-2 ring-brand-subtle'
                        : 'bg-[#1b2230] text-white hover:bg-[#0f172a]'
                    }`}
                  >
                    {redirectingPlan === plan.id ? (
                      <>
                        <Loader2 className="w-4 h-4 animate-spin" />
                        <span>{t.pricing.connectingStripe}</span>
                      </>
                    ) : isCurrentPlan ? (
                      t.pricing.currentPlan
                    ) : (
                      <>
                        <span>{plan.id === 'free' ? t.pricing.freeUse : t.pricing.subscribeStripe}</span>
                        <ArrowRight className="w-3.5 h-3.5" />
                      </>
                    )}
                  </button>

                  {plan.id !== 'free' && (
                    <div className="mt-2.5 flex items-center justify-center gap-1.5 text-[11px] text-[#94a3b8]">
                      <Lock className="w-3 h-3" />
                      <span>{t.pricing.encryptionTip}</span>
                    </div>
                  )}
                </div>
              </div>
            );
          })}
        </div>
      )}

      {/* Stripe Gateway Security & Trust Section */}
      <div className="bg-white border border-[#e2e8f0] rounded-2xl p-6 sm:p-8 flex flex-col md:flex-row items-center justify-between gap-6 shadow-subtle">
        <div className="flex items-center gap-4">
          <div className="w-12 h-12 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center shrink-0">
            <CreditCard className="w-6 h-6" />
          </div>
          <div>
            <h4 className="font-bold text-sm text-[#0f172a]">{t.pricing.stripeGuaranteeTitle}</h4>
            <p className="text-xs text-[#64748b] mt-0.5">
              {t.pricing.stripeGuaranteeDesc}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-4 text-xs font-semibold text-[#64748b]">
          <span className="flex items-center gap-1">
            <Shield className="w-4 h-4 text-emerald-500" />
            <span>{t.pricing.taxInvoice}</span>
          </span>
          <span className="flex items-center gap-1">
            <Zap className="w-4 h-4 text-amber-500" />
            <span>{t.pricing.instantActive}</span>
          </span>
        </div>
      </div>
    </div>
  );
};
