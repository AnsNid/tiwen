import React, { useEffect, useState, useRef } from 'react';
import { Link, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useLanguage } from '../../context/LanguageContext';
import logoImg from '../../../static/logo.png';
import { 
  Plus, 
  User, 
  LogOut, 
  Coins, 
  Globe, 
  Crown, 
  History, 
  LayoutDashboard,
  Menu,
  X,
  CreditCard,
  Sparkles
} from 'lucide-react';

export const Navbar: React.FC = () => {
  const location = useLocation();
  const navigate = useNavigate();
  const { user, isAuthenticated, logout, openAuthModal } = useAuth();
  const { t, locale, setLocale, toggleLocale, resetToAuto, isAuto } = useLanguage();
  const [userDropdownOpen, setUserDropdownOpen] = useState(false);
  const [langDropdownOpen, setLangDropdownOpen] = useState(false);
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
  const userDropdownRef = useRef<HTMLDivElement>(null);
  const langDropdownRef = useRef<HTMLDivElement>(null);

  // 路由切换时自动收起用户下拉与移动端菜单,避免跳转后残留展开态
  useEffect(() => {
    setUserDropdownOpen(false);
    setLangDropdownOpen(false);
    setMobileMenuOpen(false);
  }, [location.pathname]);

  // 点击外部区域或按 Escape 键关闭下拉菜单
  useEffect(() => {
    if (!userDropdownOpen && !langDropdownOpen) return;

    const handleClickOutside = (event: MouseEvent | TouchEvent) => {
      if (userDropdownRef.current && !userDropdownRef.current.contains(event.target as Node)) {
        setUserDropdownOpen(false);
      }
      if (langDropdownRef.current && !langDropdownRef.current.contains(event.target as Node)) {
        setLangDropdownOpen(false);
      }
    };

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        setUserDropdownOpen(false);
        setLangDropdownOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside, true);
    document.addEventListener('touchstart', handleClickOutside, true);
    document.addEventListener('keydown', handleKeyDown);

    return () => {
      document.removeEventListener('mousedown', handleClickOutside, true);
      document.removeEventListener('touchstart', handleClickOutside, true);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [userDropdownOpen, langDropdownOpen]);

  const isActive = (path: string) => {
    if (path === '/') return location.pathname === '/';
    return location.pathname.startsWith(path);
  };

  const navLinks = [
    { label: t.nav.home, path: '/' },
    { label: t.nav.workbench, path: '/chat' },
    { label: t.nav.pricing, path: '/pricing' }
  ];

  return (
    <header className="sticky top-0 z-50 bg-white/95 backdrop-blur-md border-b border-[#e7ebf3] transition-all">
      <div className="max-w-[1400px] mx-auto px-4 sm:px-6 h-14 flex items-center justify-between gap-3">
        {/* Left: Brand & Nav Tabs */}
        <div className="flex items-center gap-4 sm:gap-7">
          <Link 
            to="/" 
            onClick={() => setMobileMenuOpen(false)}
            className="flex items-center group shrink-0"
          >
            <img src={logoImg} alt="tiwen" className="h-8 w-auto object-contain group-hover:scale-105 transition-transform" />
          </Link>

          {/* Desktop Navigation links */}
          <nav className="hidden md:flex items-center gap-1">
            {navLinks.map(link => (
              <Link
                key={link.path}
                to={link.path}
                className={`px-3 py-1.5 rounded-lg text-xs font-semibold transition-colors ${
                  isActive(link.path)
                    ? 'bg-brand-subtle text-brand font-bold'
                    : 'text-[#65718a] hover:text-[#1b2230] hover:bg-[#f1f5f9]'
                }`}
              >
                {link.label}
              </Link>
            ))}
          </nav>
        </div>

        {/* Right: Actions & User Menu */}
        <div className="flex items-center gap-2 sm:gap-3">
          {/* Language Switcher */}
          <div className="relative" ref={langDropdownRef}>
            <button
              onClick={() => setLangDropdownOpen(!langDropdownOpen)}
              className="px-2 sm:px-2.5 py-1.5 rounded-lg border border-[#e2e8f0] text-xs font-medium text-[#64748b] hover:text-brand hover:bg-[#f8fafc] flex items-center gap-1 transition-colors"
              title="切换语言 / 切換語言 / Switch Language"
            >
              <Globe className="w-3.5 h-3.5" />
              <span className="text-[11px] font-bold">
                {locale === 'zh' ? '简体' : locale === 'zh-TW' ? '繁體' : 'EN'}
              </span>
            </button>

            {langDropdownOpen && (
              <div className="absolute right-0 mt-2 w-36 bg-white rounded-xl shadow-lg border border-[#e7ebf3] py-1 z-50 animate-in fade-in zoom-in-95 duration-100">
                <button
                  onClick={() => { setLocale('zh'); setLangDropdownOpen(false); }}
                  className={`w-full text-left px-3 py-1.5 text-xs flex items-center justify-between hover:bg-[#f8fafc] ${
                    !isAuto && locale === 'zh' ? 'text-brand font-bold bg-brand-subtle/50' : 'text-[#475569]'
                  }`}
                >
                  <span>简体中文</span>
                  {!isAuto && locale === 'zh' && <span className="w-1.5 h-1.5 rounded-full bg-brand" />}
                </button>
                <button
                  onClick={() => { setLocale('zh-TW'); setLangDropdownOpen(false); }}
                  className={`w-full text-left px-3 py-1.5 text-xs flex items-center justify-between hover:bg-[#f8fafc] ${
                    !isAuto && locale === 'zh-TW' ? 'text-brand font-bold bg-brand-subtle/50' : 'text-[#475569]'
                  }`}
                >
                  <span>繁體中文</span>
                  {!isAuto && locale === 'zh-TW' && <span className="w-1.5 h-1.5 rounded-full bg-brand" />}
                </button>
                <button
                  onClick={() => { setLocale('en'); setLangDropdownOpen(false); }}
                  className={`w-full text-left px-3 py-1.5 text-xs flex items-center justify-between hover:bg-[#f8fafc] ${
                    !isAuto && locale === 'en' ? 'text-brand font-bold bg-brand-subtle/50' : 'text-[#475569]'
                  }`}
                >
                  <span>English</span>
                  {!isAuto && locale === 'en' && <span className="w-1.5 h-1.5 rounded-full bg-brand" />}
                </button>
                <div className="h-px bg-[#f1f5f9] my-1" />
                <button
                  onClick={() => { resetToAuto(); setLangDropdownOpen(false); }}
                  className={`w-full text-left px-3 py-1.5 text-xs flex items-center justify-between hover:bg-[#f8fafc] ${
                    isAuto ? 'text-brand font-bold bg-brand-subtle/50' : 'text-[#64748b]'
                  }`}
                  title="根据浏览器设置自动识别语言 (港澳台繁体 / 大陆简体 / 其它英文)"
                >
                  <span className="text-[11px]">{locale === 'zh' ? '自动 (跟随浏览器)' : locale === 'zh-TW' ? '自動 (跟隨瀏覽器)' : 'Auto (Browser)'}</span>
                  {isAuto && <span className="w-1.5 h-1.5 rounded-full bg-brand" />}
                </button>
              </div>
            )}
          </div>

          {/* New Chat Button (Desktop) */}
          <Link
            to="/chat"
            className="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-brand hover:bg-brand-hover text-white text-xs font-bold shadow-xs transition-colors"
          >
            <Plus className="w-3.5 h-3.5" />
            <span>{t.nav.newChat}</span>
          </Link>

          {/* Auth / User Profile */}
          {isAuthenticated && user ? (
            <div className="relative" ref={userDropdownRef}>
              <button
                onClick={() => setUserDropdownOpen(!userDropdownOpen)}
                className="flex items-center gap-2 p-1 rounded-full hover:ring-2 hover:ring-brand-subtle transition-all focus:outline-none"
              >
                <div className="w-8 h-8 rounded-full bg-brand text-white font-bold text-xs flex items-center justify-center shadow-xs">
                  {user.email[0]?.toUpperCase()}
                </div>
                <div className="hidden lg:flex flex-col text-left">
                  <span className="text-xs font-bold text-[#1b2230] leading-none truncate max-w-[100px]">
                    {user.name}
                  </span>
                  <span className="text-[10px] text-[#8c97af] font-mono mt-0.5">
                    {user.plan.toUpperCase()}
                  </span>
                </div>
              </button>

              {/* User Dropdown */}
              {userDropdownOpen && (
                <>
                  <div
                    className="fixed inset-0 z-40"
                    onClick={() => setUserDropdownOpen(false)}
                  />
                  <div className="absolute right-0 mt-2 w-64 bg-white rounded-2xl shadow-xl border border-[#e7ebf3] py-2 z-50 animate-in fade-in zoom-in-95 duration-150">
                    <div className="px-4 py-2 border-b border-[#f1f5f9]">
                      <div className="font-bold text-xs text-[#1b2230] truncate">{user.name}</div>
                      <div className="text-[11px] text-[#8c97af] truncate">{user.email}</div>
                      <div className="mt-2 flex items-center justify-between text-[11px] bg-[#f8fafc] p-2 rounded-xl">
                        <span className="text-[#65718a]">
                          {user.plan === 'free' ? (t.nav.dailyQuota || '今日提问') : `${t.nav.quota}:`}
                        </span>
                        <span className="font-mono font-bold text-brand">
                          {user.plan === 'free'
                            ? `${user.dailyAsks?.used ?? 0}/${user.dailyAsks?.limit ?? 20}`
                            : `${Math.max(0, user.tokenQuota.total - user.tokenQuota.used).toLocaleString()} tok`}
                        </span>
                      </div>
                    </div>

                    <div className="py-1">
                      <Link
                        to="/profile"
                        onClick={() => setUserDropdownOpen(false)}
                        className="flex items-center gap-2 px-4 py-2 text-xs text-[#334155] hover:bg-[#f8fafc] hover:text-brand"
                      >
                        <LayoutDashboard className="w-3.5 h-3.5 text-[#8c97af]" />
                        <span>{t.nav.profileOverview}</span>
                      </Link>

                      <Link
                        to="/profile/history"
                        onClick={() => setUserDropdownOpen(false)}
                        className="flex items-center gap-2 px-4 py-2 text-xs text-[#334155] hover:bg-[#f8fafc] hover:text-brand"
                      >
                        <History className="w-3.5 h-3.5 text-[#8c97af]" />
                        <span>{t.nav.historyRecords}</span>
                      </Link>

                      <Link
                        to="/pricing"
                        onClick={() => setUserDropdownOpen(false)}
                        className="flex items-center gap-2 px-4 py-2 text-xs text-[#334155] hover:bg-[#f8fafc] hover:text-brand"
                      >
                        <Crown className="w-3.5 h-3.5 text-amber-500" />
                        <span>{t.nav.planUpgrade}</span>
                      </Link>
                    </div>

                    <div className="pt-1 border-t border-[#f1f5f9]">
                      <button
                        onClick={() => {
                          setUserDropdownOpen(false);
                          logout();
                        }}
                        className="w-full flex items-center gap-2 px-4 py-2 text-xs text-red-600 hover:bg-red-50"
                      >
                        <LogOut className="w-3.5 h-3.5" />
                        <span>{t.nav.logout}</span>
                      </button>
                    </div>
                  </div>
                </>
              )}
            </div>
          ) : (
            <div className="flex items-center gap-1.5 sm:gap-2">
              <button
                onClick={() => openAuthModal(t.auth.loginSub, 'login')}
                className="px-2.5 sm:px-3 py-1.5 text-xs font-semibold text-[#475569] hover:text-[#0f172a] rounded-lg transition-colors"
              >
                {t.nav.login}
              </button>
              <button
                onClick={() => openAuthModal(t.auth.registerSub, 'register')}
                className="px-3 sm:px-3.5 py-1.5 text-xs font-bold text-white bg-brand hover:bg-brand-hover rounded-lg shadow-xs transition-colors"
              >
                {t.nav.register}
              </button>
            </div>
          )}

          {/* Mobile Hamburger Menu Toggle */}
          <button
            type="button"
            onClick={() => setMobileMenuOpen(!mobileMenuOpen)}
            className="md:hidden p-1.5 rounded-lg text-[#64748b] hover:text-[#0f172a] hover:bg-[#f1f5f9] transition-colors"
            aria-label="Toggle menu"
          >
            {mobileMenuOpen ? <X className="w-5 h-5" /> : <Menu className="w-5 h-5" />}
          </button>
        </div>
      </div>

      {/* Mobile Drawer / Slide-Down Menu */}
      {mobileMenuOpen && (
        <div className="md:hidden bg-white border-b border-[#e7ebf3] px-4 py-4 space-y-3 animate-in slide-in-from-top-2 duration-150 shadow-lg">
          <nav className="flex flex-col space-y-1">
            {navLinks.map(link => (
              <Link
                key={link.path}
                to={link.path}
                onClick={() => setMobileMenuOpen(false)}
                className={`px-3.5 py-2.5 rounded-xl text-xs font-bold transition-colors flex items-center justify-between ${
                  isActive(link.path)
                    ? 'bg-brand-subtle text-brand'
                    : 'text-[#475569] hover:bg-[#f8fafc]'
                }`}
              >
                <span>{link.label}</span>
                {isActive(link.path) && <span className="w-1.5 h-1.5 rounded-full bg-brand"></span>}
              </Link>
            ))}

            {isAuthenticated && (
              <>
                <Link
                  to="/profile"
                  onClick={() => setMobileMenuOpen(false)}
                  className="px-3.5 py-2.5 rounded-xl text-xs font-bold text-[#475569] hover:bg-[#f8fafc] flex items-center gap-2"
                >
                  <LayoutDashboard className="w-4 h-4 text-[#8c97af]" />
                  <span>{t.nav.profileOverview}</span>
                </Link>
                <Link
                  to="/profile/history"
                  onClick={() => setMobileMenuOpen(false)}
                  className="px-3.5 py-2.5 rounded-xl text-xs font-bold text-[#475569] hover:bg-[#f8fafc] flex items-center gap-2"
                >
                  <History className="w-4 h-4 text-[#8c97af]" />
                  <span>{t.nav.historyRecords}</span>
                </Link>
              </>
            )}
          </nav>

          {/* Mobile Language Switcher */}
          <div className="flex items-center justify-between px-3.5 py-2 rounded-xl bg-[#f8fafc] text-xs text-[#64748b]">
            <span className="flex items-center gap-1.5 font-medium">
              <Globe className="w-3.5 h-3.5" />
              <span>{locale === 'zh' ? '语言' : locale === 'zh-TW' ? '語言' : 'Language'}</span>
            </span>
            <div className="flex items-center gap-1">
              <button
                onClick={() => setLocale('zh')}
                className={`px-2 py-1 rounded-md text-[11px] font-bold transition-colors ${
                  !isAuto && locale === 'zh' ? 'bg-white text-brand shadow-xs' : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
              >
                简体
              </button>
              <button
                onClick={() => setLocale('zh-TW')}
                className={`px-2 py-1 rounded-md text-[11px] font-bold transition-colors ${
                  !isAuto && locale === 'zh-TW' ? 'bg-white text-brand shadow-xs' : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
              >
                繁體
              </button>
              <button
                onClick={() => setLocale('en')}
                className={`px-2 py-1 rounded-md text-[11px] font-bold transition-colors ${
                  !isAuto && locale === 'en' ? 'bg-white text-brand shadow-xs' : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
              >
                EN
              </button>
              <button
                onClick={resetToAuto}
                className={`px-2 py-1 rounded-md text-[11px] font-bold transition-colors ${
                  isAuto ? 'bg-white text-brand shadow-xs' : 'text-[#64748b] hover:text-[#0f172a]'
                }`}
                title="跟随浏览器"
              >
                自动
              </button>
            </div>
          </div>

          <div className="pt-2 border-t border-[#f1f5f9] flex items-center gap-2">
            <Link
              to="/chat"
              onClick={() => setMobileMenuOpen(false)}
              className="flex-1 py-2.5 rounded-xl bg-brand text-white font-bold text-xs flex items-center justify-center gap-1.5 shadow-xs"
            >
              <Plus className="w-4 h-4" />
              <span>{t.nav.newChat}</span>
            </Link>
          </div>
        </div>
      )}
    </header>
  );
};
