import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useSession } from '../context/SessionContext';
import { useLanguage } from '../context/LanguageContext';
import { api } from '../api/client';
import { currencySymbolOf } from '../api/models';
import { UsageStats } from '../types';
import {
  User,
  Crown,
  Coins,
  CreditCard,
  Sparkles,
  ArrowUpRight,
  ShieldCheck,
  ExternalLink,
  Loader2,
  MessageSquareText
} from 'lucide-react';

const BREAKDOWN_COLORS = ['#2f6bff', '#7b52d7', '#0d9488', '#b45309', '#be123c'];

/** 注册时间兼容 unix 秒 / ISO 日期字符串 */
function formatJoinDate(raw: string): string {
  if (!raw) return '-';
  const n = parseInt(raw, 10);
  const d = !isNaN(n) && String(n).length >= 9 ? new Date(n * 1000) : new Date(raw);
  return isNaN(d.getTime()) ? raw : d.toLocaleDateString();
}

export const ProfileOverviewPage: React.FC = () => {
  const { user, isAuthenticated, openAuthModal } = useAuth();
  const { showToast, getModelMeta } = useSession();
  const { t } = useLanguage();
  const [usage, setUsage] = useState<UsageStats | null>(null);
  const [usageLoading, setUsageLoading] = useState(true);
  const [portalOpening, setPortalOpening] = useState(false);

  useEffect(() => {
    if (!isAuthenticated) return;
    let cancelled = false;
    (async () => {
      setUsageLoading(true);
      const res = await api<UsageStats>('/tiwen/usage');
      if (cancelled) return;
      if (res.ok && res.data) {
        setUsage(res.data);
      }
      setUsageLoading(false);
    })();
    return () => { cancelled = true; };
  }, [isAuthenticated]);

  if (!isAuthenticated || !user) {
    return (
      <div className="max-w-md mx-auto my-16 p-8 bg-white rounded-3xl border border-[#e2e8f0] text-center shadow-lg space-y-4">
        <div className="w-12 h-12 rounded-2xl bg-brand-subtle text-brand flex items-center justify-center mx-auto">
          <User className="w-6 h-6" />
        </div>
        <h2 className="text-xl font-bold text-[#0f172a]">{t.profile.loginRequired}</h2>
        <p className="text-xs text-[#64748b]">{t.profile.loginRequiredSub}</p>
        <button
          onClick={() => openAuthModal(t.profile.loginRequiredSub)}
          className="w-full py-2.5 rounded-xl bg-brand text-white font-bold text-xs hover:bg-brand-hover transition-colors shadow-xs"
        >
          {t.profile.loginBtn}
        </button>
      </div>
    );
  }

  const isFree = user.plan === 'free';
  const quotaPercent = !isFree && user.tokenQuota.total > 0
    ? Math.min(100, Math.round((user.tokenQuota.used / user.tokenQuota.total) * 100))
    : 0;
  const dailyUsed = user.dailyAsks?.used || 0;
  // 每日限次配 0 表示不限制
  const dailyUnlimited = (user.dailyAsks?.limit ?? 0) <= 0;
  const dailyLimit = dailyUnlimited ? 0 : (user.dailyAsks?.limit as number);
  const dailyPercent = dailyUnlimited ? 0 : Math.min(100, Math.round((dailyUsed / Math.max(1, dailyLimit)) * 100));

  const handleManageStripe = async () => {
    setPortalOpening(true);
    const res = await api<{ url: string }>('/tiwen/billingPortal', {
      method: 'POST',
      json: { returnUrl: window.location.origin + '/profile' },
    });
    setPortalOpening(false);
    if (!res.ok || !res.data?.url) {
      showToast(res.message || '打开订阅管理失败');
      return;
    }
    window.location.href = res.data.url;
  };

  const topModels = (usage?.models || [])
    .slice()
    .sort((a, b) => b.tokens - a.tokens)
    .slice(0, 3);

  const invoices = usage?.invoices || [];

  return (
    <div className="max-w-[1400px] mx-auto px-4 sm:px-6 py-8 space-y-8">
      {/* Subnav Tabs */}
      <div className="flex items-center gap-3 border-b border-[#e2e8f0] pb-3">
        <Link
          to="/profile"
          className="px-3.5 py-1.5 rounded-lg text-xs font-bold bg-brand text-white shadow-xs"
        >
          {t.profile.tabOverview}
        </Link>
        <Link
          to="/profile/history"
          className="px-3.5 py-1.5 rounded-lg text-xs font-semibold text-[#64748b] hover:text-[#0f172a] hover:bg-[#f1f5f9] transition-colors"
        >
          {t.profile.tabHistory}
        </Link>
      </div>

      {/* User Header & Quota Grid */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Left: User Card */}
        <div className="bg-white rounded-3xl p-6 border border-[#e2e8f0] shadow-subtle space-y-5">
          <div className="flex items-center gap-4">
            <div className="w-14 h-14 rounded-2xl bg-gradient-to-tr from-brand to-indigo-500 text-white font-extrabold text-xl flex items-center justify-center shadow-md">
              {user.email[0]?.toUpperCase()}
            </div>
            <div>
              <div className="flex items-center gap-2">
                <h2 className="text-base font-bold text-[#0f172a]">{user.name}</h2>
                <span className={`px-2 py-0.5 rounded-full text-[10px] font-extrabold flex items-center gap-1 ${
                  user.plan === 'pro'
                    ? 'bg-amber-100 text-amber-800'
                    : user.plan === 'team'
                    ? 'bg-purple-100 text-purple-800'
                    : user.plan === 'flagship'
                    ? 'bg-gradient-to-r from-[#2f6bff] to-indigo-600 text-white'
                    : 'bg-slate-100 text-slate-700'
                }`}>
                  <Crown className="w-3 h-3" />
                  {user.plan.toUpperCase()}
                </span>
              </div>
              <p className="text-xs text-[#64748b] mt-0.5">{user.email}</p>
            </div>
          </div>

          <div className="pt-4 border-t border-[#f1f5f9] space-y-2 text-xs text-[#475569]">
            <div className="flex justify-between">
              <span className="text-[#8c97af]">{t.profile.joinDate}</span>
              <span className="font-mono">{formatJoinDate(user.createdAt)}</span>
            </div>
            <div className="flex justify-between">
              <span className="text-[#8c97af]">{t.profile.statusLabel}</span>
              <span className="text-emerald-600 font-semibold flex items-center gap-1">
                <ShieldCheck className="w-3.5 h-3.5" />
                {!isFree && user.subscriptionStatus
                  ? user.subscriptionStatus
                  : t.profile.statusValid}
              </span>
            </div>
            {(user.credits?.balance ?? 0) > 0 && (
              <div className="flex justify-between">
                <span className="text-[#8c97af]">{t.profile.creditsBalance}</span>
                <span className="font-mono text-amber-600 font-bold">
                  {user.credits!.balance.toLocaleString()}
                  {user.credits!.expiresAt && (
                    <span className="ml-1.5 text-[10px] font-medium text-[#94a3b8] font-sans">
                      {t.profile.creditsExpire.replace('{d}', user.credits!.expiresAt)}
                    </span>
                  )}
                </span>
              </div>
            )}
          </div>

          {isFree ? (
            <Link
              to="/pricing"
              className="w-full py-2.5 rounded-xl bg-gradient-to-r from-brand to-indigo-600 text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-xs hover:opacity-95 transition-opacity"
            >
              <Sparkles className="w-3.5 h-3.5 text-amber-300" />
              <span>{t.profile.upgradePro}</span>
            </Link>
          ) : (
            <button
              onClick={handleManageStripe}
              disabled={portalOpening}
              className="w-full py-2.5 rounded-xl border border-[#cbd5e1] text-[#334155] font-semibold text-xs hover:bg-[#f8fafc] flex items-center justify-center gap-1.5 transition-colors disabled:opacity-60"
            >
              {portalOpening ? (
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
              ) : (
                <CreditCard className="w-3.5 h-3.5 text-[#64748b]" />
              )}
              <span>{t.profile.manageStripe}</span>
              <ExternalLink className="w-3 h-3 text-[#94a3b8]" />
            </button>
          )}
        </div>

        {/* Right: Quota & Model Breakdown */}
        <div className="lg:col-span-2 bg-white rounded-3xl p-6 border border-[#e2e8f0] shadow-subtle space-y-6 flex flex-col justify-between">
          <div>
            <div className="flex items-center justify-between mb-3">
              <div className="flex items-center gap-2">
                {isFree ? (
                  <MessageSquareText className="w-4 h-4 text-brand" />
                ) : (
                  <Coins className="w-4 h-4 text-amber-500" />
                )}
                <h3 className="font-bold text-sm text-[#0f172a]">
                  {isFree ? t.profile.dailyQuotaTitle : t.profile.quotaTitle}
                </h3>
              </div>
              <span className="text-xs font-mono text-[#64748b]">
                {isFree
                  ? t.profile.dailyQuotaReset
                  : t.profile.resetPeriod.replace('{d}', user.tokenQuota.resetDate)}
              </span>
            </div>

            {/* Quota Progress Bar */}
            <div className="space-y-2">
              <div className="w-full h-3 rounded-full bg-[#f1f5f9] overflow-hidden p-0.5">
                <div
                  className={`h-full rounded-full transition-all duration-500 ${
                    isFree
                      ? 'bg-gradient-to-r from-brand to-sky-400'
                      : 'bg-gradient-to-r from-brand to-indigo-500'
                  }`}
                  style={{ width: `${isFree ? dailyPercent : quotaPercent}%` }}
                />
              </div>
              <div className="flex justify-between text-xs text-[#64748b]">
                <span>
                  {isFree ? (
                    <>
                      {t.profile.todayAsks}: <strong className="text-[#0f172a] font-mono">{dailyUsed} / {dailyUnlimited ? '∞' : dailyLimit}</strong>
                    </>
                  ) : (
                    <>
                      {t.profile.usedTokens}: <strong className="text-[#0f172a] font-mono">{user.tokenQuota.used.toLocaleString()}</strong> tok ({quotaPercent}%)
                    </>
                  )}
                </span>
                {!isFree && (
                  <span>
                    {t.profile.totalTokens}: <strong className="text-[#0f172a] font-mono">{user.tokenQuota.total.toLocaleString()}</strong> tok
                  </span>
                )}
              </div>
            </div>
          </div>

          {/* Model Breakdown(真实用量占比) */}
          <div className="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-4 border-t border-[#f1f5f9]">
            {usageLoading ? (
              <div className="sm:col-span-3 py-4 flex items-center justify-center gap-2 text-xs text-[#8c97af]">
                <Loader2 className="w-3.5 h-3.5 animate-spin" />
                <span>{t.profile.loadingUsage}</span>
              </div>
            ) : topModels.length === 0 ? (
              <div className="sm:col-span-3 py-4 text-center text-xs text-[#94a3b8]">
                {t.profile.noUsage}
              </div>
            ) : topModels.map((m, i) => {
              const meta = getModelMeta(m.modelId, m.modelName);
              return (
                <div key={m.modelId} className="p-3 bg-[#f8fafc] rounded-xl border border-[#f1f5f9]">
                  <div className="text-[11px] font-bold truncate" style={{ color: BREAKDOWN_COLORS[i] || '#475569' }}>
                    {m.modelName || meta.name}
                  </div>
                  <div className="text-sm font-extrabold text-[#0f172a] font-mono mt-1">
                    {m.percent.toFixed(0)}%
                  </div>
                  <div className="text-[10px] text-[#94a3b8]">
                    {m.tokens.toLocaleString()} tok · {m.asks} {t.profile.askUnit}
                  </div>
                </div>
              );
            })}
          </div>
        </div>
      </div>

      {/* Stripe Billing & Invoices Table */}
      <div className="bg-white rounded-3xl p-6 border border-[#e2e8f0] shadow-subtle space-y-4">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-2">
            <CreditCard className="w-4 h-4 text-brand" />
            <h3 className="font-bold text-sm text-[#0f172a]">{t.profile.invoiceTitle}</h3>
          </div>
          {!isFree && (
            <button
              onClick={handleManageStripe}
              disabled={portalOpening}
              className="text-xs text-brand font-semibold hover:underline flex items-center gap-1 disabled:opacity-60"
            >
              <span>{t.profile.vatInvoice}</span>
              <ArrowUpRight className="w-3.5 h-3.5" />
            </button>
          )}
        </div>

        <div className="overflow-x-auto">
          <table className="w-full text-left text-xs">
            <thead>
              <tr className="border-b border-[#f1f5f9] text-[#8c97af] font-semibold">
                <th className="pb-3">{t.profile.thCycle}</th>
                <th className="pb-3">{t.profile.thPlan}</th>
                <th className="pb-3">{t.profile.thAmount}</th>
                <th className="pb-3">{t.profile.thStatus}</th>
                <th className="pb-3 text-right">{t.profile.thReceipt}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-[#f8fafc] text-[#334155]">
              {invoices.length === 0 ? (
                <tr>
                  <td colSpan={5} className="py-6 text-center text-[#94a3b8]">
                    {t.profile.noInvoices}
                  </td>
                </tr>
              ) : invoices.map(inv => (
                <tr key={inv.id}>
                  <td className="py-3 font-mono">{inv.date}</td>
                  <td className="py-3 font-medium">{inv.title}</td>
                  <td className="py-3 font-mono font-bold">{currencySymbolOf(inv.currency)}{inv.amount.toFixed(2)}</td>
                  <td className="py-3">
                    <span className={`px-2 py-0.5 rounded-full text-[10px] font-bold ${
                      inv.failed ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-700'
                    }`}>
                      {inv.failed ? t.profile.statusFailed : t.profile.statusPaid}
                    </span>
                  </td>
                  <td className="py-3 text-right">
                    <button
                      onClick={handleManageStripe}
                      className="text-brand hover:underline font-semibold"
                    >
                      {t.profile.viewInPortal}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};
