import React from 'react';
import { useLanguage } from '../../context/LanguageContext';
import { Globe } from 'lucide-react';

/**
 * 「智能搜索」勾选按钮(联网搜索)
 * 与 AttachmentButton 同处输入框操作区,激活时用品牌色高亮;
 * 仅在后台联网搜索可用(web_search_enabled)时由父组件渲染。
 */
export const WebSearchToggle: React.FC<{
  active: boolean;
  onToggle: () => void;
  disabled?: boolean;
}> = ({ active, onToggle, disabled }) => {
  const { t } = useLanguage();
  return (
    <button
      type="button"
      onClick={onToggle}
      disabled={disabled}
      aria-pressed={active}
      title={t.workbench.smartSearchHint}
      className={`inline-flex items-center gap-1.5 px-2.5 py-2 rounded-xl border text-[11px] font-semibold whitespace-nowrap transition-colors ${
        active
          ? 'border-brand bg-brand-subtle text-brand shadow-2xs'
          : 'border-[#e2e8f0] bg-white text-[#64748b] hover:text-brand hover:border-brand'
      } disabled:opacity-50 disabled:cursor-not-allowed`}
    >
      <Globe className="w-3.5 h-3.5" />
      <span>{t.workbench.smartSearch}</span>
    </button>
  );
};
