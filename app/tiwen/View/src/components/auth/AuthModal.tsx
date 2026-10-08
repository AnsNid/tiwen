import React, { useState, useEffect } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useLanguage } from '../../context/LanguageContext';
import logoImg from '../../../static/logo.png';
import { 
  X, 
  Mail, 
  Lock, 
  ShieldCheck, 
  ArrowRight, 
  Loader2, 
  LogIn, 
  UserPlus, 
  Eye, 
  EyeOff, 
  ArrowLeft, 
  KeyRound,
  AlertCircle,
  CheckCircle2
} from 'lucide-react';

export const AuthModal: React.FC = () => {
  const {
    isAuthModalOpen,
    closeAuthModal,
    authMode,
    setAuthMode,
    sendVerificationCode,
    sendResetCode,
    resetPassword,
    loginWithPassword,
    registerWithCode
  } = useAuth();
  const { t } = useLanguage();

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [countdown, setCountdown] = useState(0);
  const [loading, setLoading] = useState(false);
  const [sendingCode, setSendingCode] = useState(false);
  const [errorMsg, setErrorMsg] = useState('');
  const [infoMsg, setInfoMsg] = useState('');
  const [codeSent, setCodeSent] = useState(false);

  useEffect(() => {
    let timer: any;
    if (countdown > 0) {
      timer = setTimeout(() => setCountdown(countdown - 1), 1000);
    }
    return () => clearTimeout(timer);
  }, [countdown]);

  if (!isAuthModalOpen) return null;

  const handleSendCode = async () => {
    if (!email.trim() || !email.includes('@')) {
      setErrorMsg(t.auth.invalidEmail);
      return;
    }
    setErrorMsg('');
    setInfoMsg('');
    setSendingCode(true);

    // 找回密码走独立接口:校验邮箱已注册后发送重置验证码
    const res = authMode === 'reset' ? await sendResetCode(email) : await sendVerificationCode(email);
    setSendingCode(false);
    if (res.success) {
      setCodeSent(true);
      setCountdown(60);
    } else {
      setErrorMsg(res.message || 'Error sending code. Please try again.');
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email.trim() || !email.includes('@')) {
      setErrorMsg(t.auth.invalidEmail);
      return;
    }
    setErrorMsg('');
    setInfoMsg('');
    setLoading(true);

    let res: { success: boolean; message?: string };
    if (authMode === 'login') {
      res = await loginWithPassword(email, password);
    } else if (authMode === 'reset') {
      res = await resetPassword(email, code, password);
    } else {
      res = await registerWithCode(email, code, password);
    }
    setLoading(false);

    if (res.success) {
      if (authMode === 'reset') {
        // 重置成功不自动登录(未携带新登录态),引导用新密码登录
        setAuthMode('login');
        setInfoMsg(t.auth.resetSuccess);
        setCode('');
        setPassword('');
        setCodeSent(false);
        return;
      }
      closeAuthModal();
      setEmail('');
      setPassword('');
      setCode('');
      setCodeSent(false);
    } else {
      setErrorMsg(res.message || (authMode === 'login' ? 'Login failed' : 'Operation failed'));
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
      {/* Backdrop with frosted blur */}
      <div 
        className="fixed inset-0 bg-[#0f172a]/50 backdrop-blur-xs transition-opacity animate-in fade-in duration-200"
        onClick={closeAuthModal}
      />

      {/* Modal Dialog Box */}
      <div className="relative w-full max-w-md bg-white rounded-3xl shadow-2xl border border-[#e2e8f0] overflow-hidden z-10 animate-in zoom-in-95 duration-200">
        {/* Top Decorative Gradient Accent Bar */}
        <div className="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-brand via-indigo-500 to-emerald-400" />

        {/* Close Button */}
        <button
          onClick={closeAuthModal}
          className="absolute top-4 right-4 p-1.5 rounded-full text-[#64748b] hover:text-[#1b2230] hover:bg-[#f1f5f9] transition-colors cursor-pointer"
          aria-label="Close"
        >
          <X className="w-4 h-4" />
        </button>

        {/* Top Header Card */}
        <div className="px-6 pt-6 pb-2">
          {/* Back to login shortcut when in reset mode */}
          {authMode === 'reset' && (
            <button
              type="button"
              onClick={() => {
                setAuthMode('login');
                setErrorMsg('');
                setInfoMsg('');
              }}
              className="inline-flex items-center gap-1.5 text-xs font-semibold text-brand hover:text-brand-hover mb-3 transition-colors cursor-pointer group"
            >
              <ArrowLeft className="w-3.5 h-3.5 group-hover:-translate-x-0.5 transition-transform" />
              <span>{t.auth.hasAccountTip || '返回登录'}</span>
            </button>
          )}

          <div className="flex items-center gap-3.5 mb-3">
            <div className="w-10 h-10 rounded-2xl bg-white border border-[#e2e8f0] p-2 flex items-center justify-center shadow-xs shrink-0">
              {authMode === 'reset' ? (
                <KeyRound className="w-5 h-5 text-brand" />
              ) : (
                <img src={logoImg} alt="tiwen logo" className="w-full h-full object-contain" />
              )}
            </div>
            <div className="min-w-0 flex-1">
              <h3 className="text-base font-extrabold text-[#0f172a] leading-tight">
                {authMode === 'login' ? t.auth.loginWelcome : authMode === 'reset' ? t.auth.resetWelcome : t.auth.registerWelcome}
              </h3>
              <p className="text-xs text-[#64748b] mt-0.5 truncate">
                {authMode === 'login' ? t.auth.loginSub : authMode === 'reset' ? t.auth.resetSub : t.auth.registerSub}
              </p>
            </div>
          </div>

          {/* Segmented Tabs (Hidden when resetting password) */}
          {authMode !== 'reset' && (
            <div className="grid grid-cols-2 bg-[#f1f5f9] p-1 rounded-2xl mt-4">
              <button
                type="button"
                onClick={() => {
                  setAuthMode('login');
                  setErrorMsg('');
                  setInfoMsg('');
                }}
                className={`py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 cursor-pointer ${
                  authMode === 'login'
                    ? 'bg-white text-brand shadow-xs'
                    : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
              >
                <LogIn className="w-3.5 h-3.5" />
                <span>{t.auth.loginTab}</span>
              </button>

              <button
                type="button"
                onClick={() => {
                  setAuthMode('register');
                  setErrorMsg('');
                  setInfoMsg('');
                }}
                className={`py-2 px-3 rounded-xl text-xs font-bold transition-all flex items-center justify-center gap-1.5 cursor-pointer ${
                  authMode === 'register'
                    ? 'bg-white text-brand shadow-xs'
                    : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
              >
                <UserPlus className="w-3.5 h-3.5" />
                <span>{t.auth.registerTab}</span>
              </button>
            </div>
          )}
        </div>

        {/* Body Form */}
        <form onSubmit={handleSubmit} className="p-6 sm:p-7 space-y-4">
          {/* Error Message Banner */}
          {errorMsg && (
            <div className="p-3 rounded-2xl bg-red-50/90 border border-red-200/80 text-xs text-red-600 font-medium flex items-center gap-2 animate-in fade-in duration-150">
              <AlertCircle className="w-4 h-4 shrink-0 text-red-500" />
              <span>{errorMsg}</span>
            </div>
          )}

          {/* Info/Success Message Banner */}
          {infoMsg && (
            <div className="p-3 rounded-2xl bg-emerald-50/90 border border-emerald-200/80 text-xs text-emerald-700 font-medium flex items-center gap-2 animate-in fade-in duration-150">
              <CheckCircle2 className="w-4 h-4 shrink-0 text-emerald-500" />
              <span>{infoMsg}</span>
            </div>
          )}

          {/* Email input field */}
          <div>
            <label className="block text-xs font-bold text-[#475569] mb-1.5">{t.auth.emailLabel}</label>
            <div className="relative rounded-2xl border border-[#cbd5e1] focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10 transition-all bg-[#f8fafc] focus-within:bg-white">
              <Mail className="w-4 h-4 text-[#94a3b8] absolute left-3.5 top-1/2 -translate-y-1/2" />
              <input
                type="email"
                placeholder={t.auth.emailPlaceholder}
                value={email}
                onChange={e => setEmail(e.target.value)}
                className="w-full pl-10 pr-3 py-2.5 text-xs sm:text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none bg-transparent"
              />
            </div>
          </div>

          {/* Login Mode: Password input */}
          {authMode === 'login' && (
            <div>
              <div className="flex items-center justify-between mb-1.5">
                <label className="block text-xs font-bold text-[#475569]">{t.auth.passwordLabel}</label>
                <div className="flex items-center gap-2">
                  <button
                    type="button"
                    onClick={() => {
                      setAuthMode('reset');
                      setErrorMsg('');
                      setInfoMsg('');
                    }}
                    className="text-[11px] font-semibold text-brand hover:underline cursor-pointer"
                  >
                    {t.auth.forgotPassword}
                  </button>
                  <span className="text-[#cbd5e1]">·</span>
                  <button
                    type="button"
                    onClick={() => {
                      setAuthMode('register');
                      setErrorMsg('');
                      setInfoMsg('');
                    }}
                    className="text-[11px] font-semibold text-brand hover:underline cursor-pointer"
                  >
                    {t.auth.noAccountTip}
                  </button>
                </div>
              </div>
              <div className="relative rounded-2xl border border-[#cbd5e1] focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10 transition-all bg-[#f8fafc] focus-within:bg-white flex items-center">
                <Lock className="w-4 h-4 text-[#94a3b8] absolute left-3.5 top-1/2 -translate-y-1/2" />
                <input
                  type={showPassword ? 'text' : 'password'}
                  placeholder={t.auth.passwordPlaceholder}
                  value={password}
                  onChange={e => setPassword(e.target.value)}
                  className="w-full pl-10 pr-10 py-2.5 text-xs sm:text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none bg-transparent"
                />
                <button
                  type="button"
                  onClick={() => setShowPassword(!showPassword)}
                  className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-[#94a3b8] hover:text-[#475569] transition-colors cursor-pointer"
                  tabIndex={-1}
                >
                  {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                </button>
              </div>
            </div>
          )}

          {/* Reset Mode: Verification Code + New Password */}
          {authMode === 'reset' && (
            <>
              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label className="block text-xs font-bold text-[#475569]">{t.auth.codeLabel}</label>
                  <button
                    type="button"
                    onClick={() => {
                      setAuthMode('login');
                      setErrorMsg('');
                      setInfoMsg('');
                    }}
                    className="text-[11px] font-semibold text-brand hover:underline cursor-pointer"
                  >
                    {t.auth.hasAccountTip}
                  </button>
                </div>
                <div className="flex gap-2">
                  <div className="relative flex-1 rounded-2xl border border-[#cbd5e1] focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10 transition-all bg-[#f8fafc] focus-within:bg-white">
                    <ShieldCheck className="w-4 h-4 text-[#94a3b8] absolute left-3.5 top-1/2 -translate-y-1/2" />
                    <input
                      type="text"
                      maxLength={6}
                      placeholder={t.auth.codePlaceholder}
                      value={code}
                      onChange={e => setCode(e.target.value)}
                      className="w-full pl-10 pr-3 py-2.5 text-xs sm:text-sm text-[#0f172a] font-mono tracking-wider focus:outline-none bg-transparent"
                    />
                  </div>
                  <button
                    type="button"
                    disabled={countdown > 0 || sendingCode}
                    onClick={handleSendCode}
                    className="px-3.5 py-2.5 text-xs font-bold rounded-2xl border border-[#cbd5e1] bg-white hover:bg-[#f8fafc] text-[#334155] disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap transition-colors shadow-2xs cursor-pointer"
                  >
                    {sendingCode ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : countdown > 0 ? (
                      t.auth.resendIn.includes('{n}')
                        ? t.auth.resendIn.replace('{n}', String(countdown))
                        : `${countdown}${t.auth.resendIn}`
                    ) : (
                      t.auth.getCode
                    )}
                  </button>
                </div>
                <div className="mt-1.5 flex items-center justify-between text-[11px] text-[#65718a]">
                  <span>📮 {t.auth.codeNotice}</span>
                  {codeSent && <span className="text-emerald-600 font-semibold">{t.auth.codeSent}</span>}
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-[#475569] mb-1.5">{t.auth.newPassLabel}</label>
                <div className="relative rounded-2xl border border-[#cbd5e1] focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10 transition-all bg-[#f8fafc] focus-within:bg-white flex items-center">
                  <Lock className="w-4 h-4 text-[#94a3b8] absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type={showPassword ? 'text' : 'password'}
                    placeholder={t.auth.newPassPlaceholder}
                    value={password}
                    onChange={e => setPassword(e.target.value)}
                    className="w-full pl-10 pr-10 py-2.5 text-xs sm:text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none bg-transparent"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-[#94a3b8] hover:text-[#475569] transition-colors cursor-pointer"
                    tabIndex={-1}
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>
            </>
          )}

          {/* Register Mode: Verification Code + Set Password */}
          {authMode === 'register' && (
            <>
              <div>
                <div className="flex items-center justify-between mb-1.5">
                  <label className="block text-xs font-bold text-[#475569]">{t.auth.codeLabel}</label>
                  <button
                    type="button"
                    onClick={() => {
                      setAuthMode('login');
                      setErrorMsg('');
                      setInfoMsg('');
                    }}
                    className="text-[11px] font-semibold text-brand hover:underline cursor-pointer"
                  >
                    {t.auth.hasAccountTip}
                  </button>
                </div>
                <div className="flex gap-2">
                  <div className="relative flex-1 rounded-2xl border border-[#cbd5e1] focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10 transition-all bg-[#f8fafc] focus-within:bg-white">
                    <ShieldCheck className="w-4 h-4 text-[#94a3b8] absolute left-3.5 top-1/2 -translate-y-1/2" />
                    <input
                      type="text"
                      maxLength={6}
                      placeholder={t.auth.codePlaceholder}
                      value={code}
                      onChange={e => setCode(e.target.value)}
                      className="w-full pl-10 pr-3 py-2.5 text-xs sm:text-sm text-[#0f172a] font-mono tracking-wider focus:outline-none bg-transparent"
                    />
                  </div>
                  <button
                    type="button"
                    disabled={countdown > 0 || sendingCode}
                    onClick={handleSendCode}
                    className="px-3.5 py-2.5 text-xs font-bold rounded-2xl border border-[#cbd5e1] bg-white hover:bg-[#f8fafc] text-[#334155] disabled:opacity-50 disabled:cursor-not-allowed whitespace-nowrap transition-colors shadow-2xs cursor-pointer"
                  >
                    {sendingCode ? (
                      <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    ) : countdown > 0 ? (
                      t.auth.resendIn.includes('{n}')
                        ? t.auth.resendIn.replace('{n}', String(countdown))
                        : `${countdown}${t.auth.resendIn}`
                    ) : (
                      t.auth.getCode
                    )}
                  </button>
                </div>
                <div className="mt-1.5 flex items-center justify-between text-[11px] text-[#65718a]">
                  <span>📮 {t.auth.codeNotice}</span>
                  {codeSent && <span className="text-emerald-600 font-semibold">{t.auth.codeSent}</span>}
                </div>
              </div>

              <div>
                <label className="block text-xs font-bold text-[#475569] mb-1.5">{t.auth.setPassLabel}</label>
                <div className="relative rounded-2xl border border-[#cbd5e1] focus-within:border-brand focus-within:ring-4 focus-within:ring-brand/10 transition-all bg-[#f8fafc] focus-within:bg-white flex items-center">
                  <Lock className="w-4 h-4 text-[#94a3b8] absolute left-3.5 top-1/2 -translate-y-1/2" />
                  <input
                    type={showPassword ? 'text' : 'password'}
                    placeholder={t.auth.setPassPlaceholder}
                    value={password}
                    onChange={e => setPassword(e.target.value)}
                    className="w-full pl-10 pr-10 py-2.5 text-xs sm:text-sm text-[#0f172a] placeholder:text-[#94a3b8] focus:outline-none bg-transparent"
                  />
                  <button
                    type="button"
                    onClick={() => setShowPassword(!showPassword)}
                    className="absolute right-3 top-1/2 -translate-y-1/2 p-1 text-[#94a3b8] hover:text-[#475569] transition-colors cursor-pointer"
                    tabIndex={-1}
                  >
                    {showPassword ? <EyeOff className="w-4 h-4" /> : <Eye className="w-4 h-4" />}
                  </button>
                </div>
              </div>
            </>
          )}

          {/* Submit Action Button */}
          <button
            type="submit"
            disabled={loading}
            className="w-full py-3 px-4 rounded-2xl bg-brand text-white font-extrabold text-xs sm:text-sm hover:bg-brand-hover active:scale-[0.99] transition-all flex items-center justify-center gap-2 shadow-md shadow-brand/20 hover:shadow-lg hover:shadow-brand/25 disabled:opacity-60 mt-3 cursor-pointer"
          >
            {loading ? (
              <Loader2 className="w-4 h-4 animate-spin" />
            ) : (
              <>
                <span>
                  {authMode === 'login' ? t.auth.loginBtn : authMode === 'reset' ? t.auth.resetBtn : t.auth.registerBtn}
                </span>
                <ArrowRight className="w-4 h-4" />
              </>
            )}
          </button>

          {/* Legal / Terms footnote */}
          <p className="text-center text-[11px] text-[#94a3b8] leading-relaxed pt-1">
            {t.auth.termsPrefix}
            <Link to="/legal/register" target="_blank" rel="noopener noreferrer" className="text-[#2f6bff] font-semibold hover:underline mx-0.5">
              {t.auth.terms}
            </Link>
            {t.auth.termsAnd}
            <Link to="/legal/privacy" target="_blank" rel="noopener noreferrer" className="text-[#2f6bff] font-semibold hover:underline mx-0.5">
              {t.auth.privacy}
            </Link>
          </p>
        </form>
      </div>
    </div>
  );
};
