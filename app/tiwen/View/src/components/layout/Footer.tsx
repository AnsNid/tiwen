import React from 'react';
import { Link } from 'react-router-dom';
import { useLanguage } from '../../context/LanguageContext';
import logoImg from '../../../static/logo.png';
import { Globe, ShieldCheck } from 'lucide-react';

export const Footer: React.FC = () => {
  const { t, locale, toggleLocale } = useLanguage();

  return (
    <footer className="bg-white border-t border-[#e2e8f0] text-[#65718a] text-xs pt-8 sm:pt-12 pb-6 sm:pb-8 mt-auto w-full overflow-hidden">
      <div className="max-w-[1400px] mx-auto px-4 sm:px-6 space-y-6 sm:space-y-10">
        {/* Desktop 4-Column Navigation Grid (Hidden on Mobile) */}
        <div className="hidden md:grid grid-cols-2 lg:grid-cols-5 gap-8">
          {/* Brand Col (Span 2) */}
          <div className="lg:col-span-2 space-y-4 pr-4">
            <Link to="/" className="flex items-center group">
              <img src={logoImg} alt="tiwen" className="h-8 w-auto object-contain group-hover:scale-105 transition-transform" />
            </Link>
            <p className="text-xs text-[#65718a] leading-relaxed max-w-sm">
              {t.footer.desc}
            </p>
            <div className="flex items-center gap-2 text-[11px] text-emerald-600 bg-emerald-50 border border-emerald-200/60 px-2.5 py-1 rounded-full w-fit font-medium">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
              <span>{t.footer.allSystemsNormal}</span>
            </div>
          </div>

          {/* Col 1: Features */}
          <div className="space-y-3">
            <h4 className="font-bold text-xs text-[#0f172a] uppercase tracking-wider">
              {t.footer.col1Title}
            </h4>
            <ul className="space-y-2 text-xs">
              <li>
                <Link to="/chat" className="hover:text-brand transition-colors">
                  {t.footer.col1Item1}
                </Link>
              </li>
              <li>
                <Link to="/chat" className="hover:text-brand transition-colors">
                  {t.footer.col1Item2}
                </Link>
              </li>
              <li>
                <Link to="/chat" className="hover:text-brand transition-colors">
                  {t.footer.col1Item3}
                </Link>
              </li>
              <li>
                <Link to="/chat" className="hover:text-brand transition-colors">
                  {t.footer.col1Item4}
                </Link>
              </li>
            </ul>
          </div>

          {/* Col 2: Solutions */}
          <div className="space-y-3">
            <h4 className="font-bold text-xs text-[#0f172a] uppercase tracking-wider">
              {t.footer.col2Title}
            </h4>
            <ul className="space-y-2 text-xs">
              <li>
                <Link to="/chat" className="hover:text-brand transition-colors">
                  {t.footer.col2Item1}
                </Link>
              </li>
              <li>
                <Link to="/chat" className="hover:text-brand transition-colors">
                  {t.footer.col2Item2}
                </Link>
              </li>
              <li>
                <Link to="/chat" className="hover:text-brand transition-colors">
                  {t.footer.col2Item3}
                </Link>
              </li>
              <li>
                <Link to="/chat" className="hover:text-brand transition-colors">
                  {t.footer.col2Item4}
                </Link>
              </li>
            </ul>
          </div>

          {/* Col 3: Resources & Support */}
          <div className="space-y-3">
            <h4 className="font-bold text-xs text-[#0f172a] uppercase tracking-wider">
              {t.footer.col3Title}
            </h4>
            <ul className="space-y-2 text-xs">
              <li>
                <Link to="/pricing" className="hover:text-brand transition-colors">
                  {t.footer.col3Item1}
                </Link>
              </li>
              <li>
                <Link to="/pricing" className="hover:text-brand transition-colors">
                  {t.footer.col3Item2}
                </Link>
              </li>
              <li>
                <Link to="/legal/register" className="hover:text-brand transition-colors">
                  {t.footer.col3Item3}
                </Link>
              </li>
              <li>
                <Link to="/legal/privacy" className="hover:text-brand transition-colors">
                  {t.footer.col3Item4}
                </Link>
              </li>
              <li>
                <Link to="/legal/payment" className="hover:text-brand transition-colors">
                  {t.footer.col3Item5}
                </Link>
              </li>
              <li>
                <Link to="/legal/about" className="hover:text-brand transition-colors">
                  {t.footer.col3Item6}
                </Link>
              </li>
            </ul>
          </div>
        </div>

        {/* Mobile Minimalist Footer (Clean & Compact) */}
        <div className="md:hidden flex flex-col items-center text-center space-y-3">
          <Link to="/" className="flex items-center gap-2">
            <span className="w-2.5 h-2.5 rounded-full bg-brand ring-4 ring-brand-subtle"></span>
            <span className="font-extrabold text-lg tracking-tight text-brand">tiwen</span>
          </Link>

          <p className="text-[11px] text-[#65718a] max-w-xs leading-snug">
            {t.footer.desc}
          </p>

          <div className="flex items-center gap-2 text-[10px] text-emerald-600 bg-emerald-50 px-2.5 py-0.5 rounded-full font-medium">
            <span className="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
            <span>{t.footer.allSystemsNormal}</span>
          </div>
        </div>

        {/* Bottom Copyright & Language Switch Bar */}
        <div className="pt-4 sm:pt-6 border-t border-[#f1f5f9] flex flex-col sm:flex-row items-center justify-between gap-3 text-[11px] text-[#8c97af] text-center sm:text-left">
          <p>{t.footer.rights}</p>

          <div className="flex items-center justify-center gap-4">
            <button
              onClick={toggleLocale}
              className="flex items-center gap-1 hover:text-brand transition-colors"
              title="切换语言 / 切換語言 / Switch Language"
            >
              <Globe className="w-3.5 h-3.5" />
              <span>{locale === 'zh' ? '繁體中文' : locale === 'zh-TW' ? 'English' : '简体中文'}</span>
            </button>

            <span className="flex items-center gap-1">
              <ShieldCheck className="w-3.5 h-3.5 text-emerald-500" />
              <span>SSL Secured</span>
            </span>
          </div>
        </div>
      </div>
    </footer>
  );
};
