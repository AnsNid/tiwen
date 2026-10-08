import React, { useEffect, useRef, useState } from 'react';
import { useSearchParams, useNavigate, Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { useLanguage } from '../context/LanguageContext';
import { api } from '../api/client';
import { CheckCircle2, Crown, Sparkles, ArrowRight, ShieldCheck, Loader2, XCircle } from 'lucide-react';

type PageState = 'verifying' | 'success' | 'cancel';

export const PaymentCallbackPage: React.FC = () => {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { refreshUser } = useAuth();
  const { t } = useLanguage();
  const [state, setState] = useState<PageState>(
    searchParams.get('status') === 'cancel' ? 'cancel' : 'verifying'
  );
  const initialPlan = searchParams.get('plan') || '';
  const [confirmedPlan, setConfirmedPlan] = useState(initialPlan || 'pro');
  const [isOneTime, setIsOneTime] = useState(initialPlan === 'flagship');
  const pollTimer = useRef<number | null>(null);

  const sessionId = searchParams.get('session_id') || '';
  const initialStatus = searchParams.get('status') || 'pending';

  // 轮询订阅状态:Stripe 支付完成回跳后主动查单确认(不依赖 Webhook 到达)
  useEffect(() => {
    if (initialStatus === 'cancel') {
      setState('cancel');
      return;
    }
    if (!sessionId) {
      // 无会话号:刷新档案后按实际套餐展示,未开通付费套餐不得显示支付成功
      (async () => {
        await refreshUser();
        try {
          const res = await api<{ plan: string; isOneTime?: boolean }>('/tiwen/subscription', { method: 'POST', json: {} });
          const plan = res.data?.plan || '';
          if (res.data?.isOneTime) setIsOneTime(true);
          setState(plan === 'pro' || plan === 'team' || plan === 'flagship' ? 'success' : 'cancel');
        } catch {
          setState('cancel');
        }
      })();
      return;
    }

    let attempts = 0;
    const poll = async () => {
      attempts++;
      const res = await api<{
        paid: boolean | null;
        plan: string;
        orderPlan?: string;
        isOneTime?: boolean;
      }>('/tiwen/subscription', {
        method: 'POST',
        json: { sessionId },
      });
      if (res.ok && res.data) {
        if (res.data.paid === true) {
          await refreshUser();
          const p = res.data.orderPlan || res.data.plan;
          if (p) setConfirmedPlan(p);
          if (typeof res.data.isOneTime === 'boolean') {
            setIsOneTime(res.data.isOneTime);
          } else if (p === 'flagship') {
            setIsOneTime(true);
          }
          setState('success');
          return;
        }
        if (res.data.paid === false && attempts > 20) {
          setState('cancel');
          return;
        }
      }
      if (attempts > 40) {
        setState('cancel');
        return;
      }
      pollTimer.current = window.setTimeout(poll, 3000);
    };
    poll();

    return () => {
      if (pollTimer.current !== null) {
        window.clearTimeout(pollTimer.current);
      }
    };
  }, [sessionId, initialStatus, refreshUser]);

  const isOneTimePack = isOneTime || confirmedPlan === 'flagship';
  const planLabel = isOneTimePack
    ? (t.payment.flagshipPlanName || '旗舰积分包')
    : (confirmedPlan === 'team' ? 'Team' : 'Pro');

  if (state === 'verifying') {
    return (
      <div className="max-w-xl mx-auto px-4 py-16 text-center">
        <div className="bg-white rounded-3xl p-8 sm:p-10 border border-[#e2e8f0] shadow-xl space-y-5">
          <div className="w-16 h-16 rounded-3xl bg-brand-subtle text-brand flex items-center justify-center mx-auto ring-8 ring-brand-subtle/50">
            <Loader2 className="w-8 h-8 animate-spin" />
          </div>
          <h1 className="text-xl sm:text-2xl font-extrabold text-[#0f172a]">
            {t.payment.verifyingTitle}
          </h1>
          <p className="text-xs sm:text-sm text-[#64748b]">
            {t.payment.verifyingSub}
          </p>
          <p className="text-[11px] font-mono text-[#94a3b8] break-all max-w-full px-2">{sessionId}</p>
        </div>
      </div>
    );
  }

  if (state === 'cancel') {
    return (
      <div className="max-w-xl mx-auto px-4 py-16 text-center">
        <div className="bg-white rounded-3xl p-8 sm:p-10 border border-[#e2e8f0] shadow-xl space-y-5">
          <div className="w-16 h-16 rounded-3xl bg-amber-50 text-amber-600 flex items-center justify-center mx-auto ring-8 ring-amber-50/60">
            <XCircle className="w-8 h-8 stroke-[2.2]" />
          </div>
          <h1 className="text-xl sm:text-2xl font-extrabold text-[#0f172a]">
            {t.payment.cancelTitle}
          </h1>
          <p className="text-xs sm:text-sm text-[#64748b]">
            {t.payment.cancelSub}
          </p>
          <div className="flex flex-col sm:flex-row gap-3 justify-center pt-1">
            <button
              onClick={() => navigate('/pricing')}
              className="py-3 px-5 rounded-xl bg-brand text-white font-bold text-xs sm:text-sm hover:bg-brand-hover shadow-xs transition-all"
            >
              {t.payment.retrySubscribe}
            </button>
            <Link
              to="/chat"
              className="py-3 px-5 rounded-xl border border-[#cbd5e1] text-[#334155] font-semibold text-xs sm:text-sm hover:bg-[#f8fafc] transition-colors"
            >
              {t.payment.enterWorkbench}
            </Link>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="max-w-xl mx-auto px-4 py-16 text-center">
      <div className="bg-white rounded-3xl p-8 sm:p-10 border border-[#e2e8f0] shadow-xl space-y-6 animate-in fade-in zoom-in-95 duration-200">
        {/* Success Icon Badge */}
        <div className="w-16 h-16 rounded-3xl bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto ring-8 ring-emerald-50/60 shadow-sm">
          <CheckCircle2 className="w-9 h-9 stroke-[2.2]" />
        </div>

        <div className="space-y-2">
          <span className="text-xs font-bold uppercase tracking-wider text-emerald-600 bg-emerald-50 px-3 py-1 rounded-full">
            {t.payment.confirmedBadge}
          </span>
          <h1 className="text-2xl sm:text-3xl font-extrabold text-[#0f172a]">
            {isOneTimePack
              ? (t.payment.congratsTitleOneTime || '恭喜！您已成功购买{plan}').replace('{plan}', planLabel)
              : t.payment.congratsTitle.replace('{plan}', planLabel)}
          </h1>
          {sessionId && (
            <p className="text-xs sm:text-sm text-[#64748b] break-all max-w-full px-2">
              {t.payment.orderNo}: <span className="font-mono text-[#334155] break-all select-all">{sessionId}</span>
            </p>
          )}
        </div>

        {/* Benefits Card */}
        <div className="bg-[#f8fafc] rounded-2xl p-5 text-left border border-[#e2e8f0] space-y-3">
          <div className="flex items-center gap-2 font-bold text-xs text-[#0f172a]">
            <Crown className="w-4 h-4 text-amber-500" />
            <span>{isOneTimePack ? (t.payment.unlockedTitleOneTime || '已为您即时入账以下特权与积分：') : t.payment.unlockedTitle}</span>
          </div>

          <div className="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-[#475569]">
            {isOneTimePack ? (
              <>
                <div className="flex items-center gap-2">
                  <Sparkles className="w-3.5 h-3.5 text-amber-500 shrink-0" />
                  <span>{t.payment.bOneTime1 || '专属大额算力积分已全额到账'}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Sparkles className="w-3.5 h-3.5 text-amber-500 shrink-0" />
                  <span>{t.payment.bOneTime2 || '加赠 12 个月 Pro 尊享会员'}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Sparkles className="w-3.5 h-3.5 text-brand shrink-0" />
                  <span>{t.payment.bOneTime3 || '18 个顶尖模型全解锁并发'}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Sparkles className="w-3.5 h-3.5 text-brand shrink-0" />
                  <span>{t.payment.bOneTime4 || '⚡ 智能分析综合提纯与无水印导出'}</span>
                </div>
              </>
            ) : (
              <>
                <div className="flex items-center gap-2">
                  <Sparkles className="w-3.5 h-3.5 text-brand shrink-0" />
                  <span>{t.payment.b1}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Sparkles className="w-3.5 h-3.5 text-brand shrink-0" />
                  <span>{t.payment.b2}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Sparkles className="w-3.5 h-3.5 text-brand shrink-0" />
                  <span>{t.payment.b3}</span>
                </div>
                <div className="flex items-center gap-2">
                  <Sparkles className="w-3.5 h-3.5 text-brand shrink-0" />
                  <span>{t.payment.b4}</span>
                </div>
              </>
            )}
          </div>
        </div>

        {/* Action Buttons */}
        <div className="flex flex-col sm:flex-row gap-3 pt-2">
          <button
            onClick={() => navigate('/chat')}
            className="flex-1 py-3 px-5 rounded-xl bg-brand text-white font-bold text-xs sm:text-sm hover:bg-brand-hover shadow-xs flex items-center justify-center gap-2 transition-all"
          >
            <span>{t.payment.enterWorkbench}</span>
            <ArrowRight className="w-4 h-4" />
          </button>

          <Link
            to="/profile"
            className="py-3 px-5 rounded-xl border border-[#cbd5e1] text-[#334155] font-semibold text-xs sm:text-sm hover:bg-[#f8fafc] transition-colors"
          >
            {t.payment.manageProfile}
          </Link>
        </div>

        <div className="text-[11px] text-[#94a3b8] flex items-center justify-center gap-1.5">
          <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
          <span>{t.payment.invoiceTip}</span>
        </div>
      </div>
    </div>
  );
};
