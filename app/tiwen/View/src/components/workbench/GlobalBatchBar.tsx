import React from 'react';
import { useSession } from '../../context/SessionContext';
import { useLanguage } from '../../context/LanguageContext';
import { Merge, Zap, Loader2, Check } from 'lucide-react';

export const GlobalBatchBar: React.FC = () => {
  const {
    totalCheckedCount,
    adoptMergedContent,
    synthesizeContent,
    currentSession,
    activeRoundIndex,
    toggleSelectAll,
    isAllCardsChecked,
    checkedInRoundCount,
    selectableCardsCount,
    isSynthesizing
  } = useSession();
  const { t } = useLanguage();

  if (!currentSession) return null;

  const isAllChecked = isAllCardsChecked(activeRoundIndex);
  const checkedCount = checkedInRoundCount(activeRoundIndex);
  const selectableCount = selectableCardsCount(activeRoundIndex);
  const isPartial = checkedCount > 0 && !isAllChecked;

  const handleSynthesize = async () => {
    await synthesizeContent();
  };

  return (
    <div className="bg-white rounded-2xl p-3 sm:p-3.5 border border-[#e7ebf3] shadow-card flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3 transition-all">
      {/* Left: Select All button + Checked Counter info */}
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={() => toggleSelectAll(activeRoundIndex)}
          disabled={selectableCount === 0 || isSynthesizing}
          title={isAllChecked ? '取消全选本轮所有模型卡片' : '全选本轮所有模型卡片'}
          className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border text-xs font-bold transition-all select-none cursor-pointer ${
            isAllChecked
              ? 'bg-brand text-white border-brand shadow-2xs hover:bg-brand-hover'
              : isPartial
              ? 'bg-brand-subtle text-brand border-brand/40 hover:bg-brand/15'
              : 'bg-white text-[#475569] border-[#cbd5e1] hover:text-brand hover:border-brand hover:bg-[#f8fafc]'
          } disabled:opacity-40 disabled:cursor-not-allowed`}
        >
          <div
            className={`w-3.5 h-3.5 rounded border flex items-center justify-center transition-colors ${
              isAllChecked
                ? 'bg-white border-white text-brand'
                : isPartial
                ? 'bg-brand border-brand text-white'
                : 'bg-white border-[#94a3b8]'
            }`}
          >
            {isAllChecked ? (
              <Check className="w-2.5 h-2.5 stroke-[3]" />
            ) : isPartial ? (
              <span className="w-1.5 h-0.5 bg-white rounded-full" />
            ) : null}
          </div>
          <span>{isAllChecked ? '取消全选' : '全选'}</span>
        </button>

        <div className="flex items-center gap-1.5 text-xs text-[#475569]">
          <span className="text-[#cbd5e1]">|</span>
          <span className="leading-tight">
            {t.workbench.selectedCount}{' '}
            <strong className="text-[#1b2230] font-extrabold text-sm">{totalCheckedCount}</strong>{' '}
            {t.workbench.selectedUnit}
          </span>
        </div>
      </div>

      {/* Right: Action Buttons */}
      <div className="flex items-center gap-2">
        <button
          type="button"
          onClick={adoptMergedContent}
          disabled={totalCheckedCount === 0 || isSynthesizing}
          className="flex-1 sm:flex-initial px-3 sm:px-3.5 py-2 rounded-xl border border-[#cbd5e1] text-xs font-semibold text-[#334155] hover:bg-[#f8fafc] disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5 transition-colors"
        >
          <Merge className="w-3.5 h-3.5 text-[#64748b] rotate-180" />
          <span>{t.workbench.adoptMerge}</span>
        </button>

        <button
          type="button"
          onClick={handleSynthesize}
          disabled={totalCheckedCount === 0 || isSynthesizing}
          className="flex-1 sm:flex-initial px-3.5 sm:px-4 py-2 rounded-xl bg-gradient-to-r from-brand to-indigo-600 hover:from-brand-hover hover:to-indigo-700 text-white font-bold text-xs disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5 shadow-xs transition-all"
        >
          {isSynthesizing ? (
            <Loader2 className="w-3.5 h-3.5 animate-spin" />
          ) : (
            <Zap className="w-3.5 h-3.5 text-amber-300" />
          )}
          <span>{isSynthesizing ? '正在综合分析...' : t.workbench.analyzeSynthesis}</span>
        </button>
      </div>
    </div>
  );
};
