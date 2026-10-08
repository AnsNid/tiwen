import React, { useEffect, useState } from 'react';
import { Link, Navigate, useParams } from 'react-router-dom';
import { FileText, Loader2 } from 'lucide-react';
import { api } from '../api/client';
import { useLanguage } from '../context/LanguageContext';
import { MarkdownRenderer } from '../components/common/MarkdownRenderer';

const LEGAL_DOC_IDS = ['register', 'privacy', 'about', 'payment'] as const;
type LegalDocId = (typeof LEGAL_DOC_IDS)[number];

/**
 * 协议与条款展示页(/legal/:doc)
 * 内容由后台「协议与条款」分区维护(Markdown,简体／繁體／English 三语),语言跟随站点语言切换
 */
export const LegalPage: React.FC = () => {
  const { doc: docParam } = useParams<{ doc?: string }>();
  const doc = (LEGAL_DOC_IDS as readonly string[]).includes(docParam ?? '') ? (docParam as LegalDocId) : null;
  const { t, locale } = useLanguage();
  const [content, setContent] = useState('');
  const [loading, setLoading] = useState(true);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    if (!doc) return;
    let cancelled = false;
    setLoading(true);
    setFailed(false);
    window.scrollTo({ top: 0 });
    (async () => {
      const res = await api<{ content: string }>(`/tiwen/legal?doc=${doc}&lang=${locale}`);
      if (cancelled) return;
      setLoading(false);
      if (res.ok && res.data) {
        setContent(res.data.content || '');
        setFailed(false);
      } else {
        setContent('');
        setFailed(true);
      }
    })();
    return () => { cancelled = true; };
  }, [doc, locale]);

  if (!doc) return <Navigate to="/legal/register" replace />;

  return (
    <div className="flex-1 flex flex-col">
      {/* 文档头:标题 + 文档切换 Tab */}
      <header className="border-b border-[#e2e8f0] bg-white">
        <div className="max-w-[820px] mx-auto px-4 sm:px-6 pt-10 sm:pt-12">
          <h1 className="text-2xl sm:text-3xl font-extrabold text-[#0f172a] tracking-tight">
            {t.legal.docs[doc]}
          </h1>
          <p className="mt-2 text-sm text-[#65718a]">{t.legal.subtitle}</p>
          <nav className="flex gap-1 overflow-x-auto mt-6 -mb-px" aria-label={t.legal.title}>
            {LEGAL_DOC_IDS.map((id) => {
              const active = id === doc;
              return (
                <Link
                  key={id}
                  to={`/legal/${id}`}
                  className={`relative whitespace-nowrap px-4 py-3 text-sm font-bold transition-colors ${
                    active ? 'text-brand' : 'text-[#65718a] hover:text-[#0f172a]'
                  }`}
                >
                  {t.legal.docs[id]}
                  {active && <span className="absolute left-3 right-3 bottom-0 h-0.5 rounded-full bg-brand" />}
                </Link>
              );
            })}
          </nav>
        </div>
      </header>

      {/* 正文 */}
      <div className="max-w-[820px] w-full mx-auto px-4 sm:px-6 py-8 sm:py-10">
        <div className="rounded-2xl border border-[#e2e8f0] bg-white shadow-card p-5 sm:p-8 min-h-[320px]">
          {loading ? (
            <div className="py-20 flex flex-col items-center justify-center gap-3 text-[#65718a]">
              <Loader2 className="w-5 h-5 animate-spin text-brand" />
              <span className="text-xs">…</span>
            </div>
          ) : failed ? (
            <div className="py-16 flex flex-col items-center justify-center gap-3 text-center">
              <div className="w-12 h-12 rounded-2xl bg-brand-subtle flex items-center justify-center">
                <FileText className="w-5 h-5 text-brand" />
              </div>
              <p className="text-sm font-bold text-[#0f172a]">{t.legal.loadFailed}</p>
              <Link
                to="/"
                className="mt-1 h-9 px-4 inline-flex items-center rounded-xl border border-[#e2e8f0] text-xs font-bold text-[#64748b] hover:bg-[#f8fafc] hover:text-[#0f172a] transition-colors"
              >
                {t.legal.backHome}
              </Link>
            </div>
          ) : content.trim() ? (
            <MarkdownRenderer content={content} size="base" />
          ) : (
            <div className="py-16 flex flex-col items-center justify-center gap-3 text-center">
              <div className="w-12 h-12 rounded-2xl bg-brand-subtle flex items-center justify-center">
                <FileText className="w-5 h-5 text-brand" />
              </div>
              <p className="text-sm font-bold text-[#0f172a]">{t.legal.empty}</p>
              <p className="text-xs text-[#65718a] max-w-xs leading-relaxed">{t.legal.emptyHint}</p>
              <Link
                to="/"
                className="mt-1 h-9 px-4 inline-flex items-center rounded-xl border border-[#e2e8f0] text-xs font-bold text-[#64748b] hover:bg-[#f8fafc] hover:text-[#0f172a] transition-colors"
              >
                {t.legal.backHome}
              </Link>
            </div>
          )}
        </div>

        {/* 其他文档快捷导航 */}
        <div className="mt-6 flex flex-wrap items-center gap-2">
          {LEGAL_DOC_IDS.filter((id) => id !== doc).map((id) => (
            <Link
              key={id}
              to={`/legal/${id}`}
              className="h-9 px-4 inline-flex items-center rounded-xl border border-[#e2e8f0] bg-white text-xs font-bold text-[#65718a] hover:border-brand hover:text-brand transition-colors"
            >
              {t.legal.docs[id]}
            </Link>
          ))}
        </div>
      </div>
    </div>
  );
};
