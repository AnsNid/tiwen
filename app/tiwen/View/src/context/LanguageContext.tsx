import React, { createContext, useContext, useState, useEffect } from 'react';
import { Locale, TRANSLATIONS } from '../i18n/translations';

interface LanguageContextType {
  locale: Locale;
  setLocale: (l: Locale, isManual?: boolean) => void;
  toggleLocale: () => void;
  resetToAuto: () => void;
  isAuto: boolean;
  t: typeof TRANSLATIONS.zh;
}

const MANUAL_LANG_KEY = 'tiwen_lang_manual';

/**
 * 依据浏览器语言偏好自动适配语言:
 * 1. 港澳台 (zh-TW, zh-HK, zh-MO, zh-Hant 等) -> 繁体中文 (zh-TW)
 * 2. 大陆 (zh-CN, zh-SG, zh-Hans, zh) -> 简体中文 (zh)
 * 3. 其它所有语言 (en, ja, fr 等非中文) -> 英文 (en)
 */
export function detectBrowserLocale(): Locale {
  const candidates =
    navigator.languages && navigator.languages.length > 0
      ? Array.from(navigator.languages)
      : [navigator.language || ''];

  for (const lang of candidates) {
    if (!lang) continue;
    const l = lang.toLowerCase().trim();

    // 1. 港澳台 / 繁体中文
    if (
      l.startsWith('zh-tw') ||
      l.startsWith('zh-hk') ||
      l.startsWith('zh-mo') ||
      l.includes('hant') ||
      l.includes('-tw') ||
      l.includes('-hk') ||
      l.includes('-mo')
    ) {
      return 'zh-TW';
    }

    // 2. 大陆 / 简体中文
    if (
      l.startsWith('zh-cn') ||
      l.startsWith('zh-sg') ||
      l.includes('hans') ||
      l === 'zh'
    ) {
      return 'zh';
    }

    // 3. 非中文首选语言直接走英文
    if (!l.startsWith('zh')) {
      return 'en';
    }
  }

  return 'en';
}

const LanguageContext = createContext<LanguageContextType | undefined>(undefined);

export const LanguageProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [isAuto, setIsAuto] = useState<boolean>(() => {
    return !localStorage.getItem(MANUAL_LANG_KEY);
  });

  const [locale, setLocaleState] = useState<Locale>(() => {
    const manual = localStorage.getItem(MANUAL_LANG_KEY) as Locale;
    if (manual === 'zh' || manual === 'zh-TW' || manual === 'en') {
      return manual;
    }
    return detectBrowserLocale();
  });

  // 监听浏览器系统语言动态变更
  useEffect(() => {
    const handleLangChange = () => {
      if (!localStorage.getItem(MANUAL_LANG_KEY)) {
        setLocaleState(detectBrowserLocale());
        setIsAuto(true);
      }
    };
    window.addEventListener('languagechange', handleLangChange);
    return () => window.removeEventListener('languagechange', handleLangChange);
  }, []);

  useEffect(() => {
    document.documentElement.lang = locale === 'zh' ? 'zh-CN' : locale === 'zh-TW' ? 'zh-TW' : 'en';
  }, [locale]);

  const setLocale = (l: Locale, isManual = true) => {
    if (isManual) {
      localStorage.setItem(MANUAL_LANG_KEY, l);
      setIsAuto(false);
    }
    setLocaleState(l);
  };

  const toggleLocale = () => {
    setLocaleState(prev => {
      const next: Locale = prev === 'zh' ? 'zh-TW' : prev === 'zh-TW' ? 'en' : 'zh';
      localStorage.setItem(MANUAL_LANG_KEY, next);
      setIsAuto(false);
      return next;
    });
  };

  const resetToAuto = () => {
    localStorage.removeItem(MANUAL_LANG_KEY);
    setIsAuto(true);
    setLocaleState(detectBrowserLocale());
  };

  const t = TRANSLATIONS[locale] || TRANSLATIONS.zh;

  return (
    <LanguageContext.Provider value={{ locale, setLocale, toggleLocale, resetToAuto, isAuto, t }}>
      {children}
    </LanguageContext.Provider>
  );
};

export const useLanguage = () => {
  const context = useContext(LanguageContext);
  if (!context) throw new Error('useLanguage must be used within a LanguageProvider');
  return context;
};
