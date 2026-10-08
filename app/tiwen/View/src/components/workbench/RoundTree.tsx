import React, { useState } from 'react';
import { useSession } from '../../context/SessionContext';
import { useLanguage } from '../../context/LanguageContext';
import {
  Plus,
  Check,
  Layers,
  ChevronDown,
  ChevronUp,
  Loader2
} from 'lucide-react';

interface RoundTreeProps {
  onFocusFollowUp: () => void;
}

export const RoundTree: React.FC<RoundTreeProps> = ({ onFocusFollowUp }) => {
  const { currentSession, activeRoundIndex, setActiveRoundIndex, isThinking, getModelMeta, selectedModelIds, models } = useSession();
  const { t, locale } = useLanguage();
  const [mobileExpanded, setMobileExpanded] = useState(false);

  if (!currentSession) return null;

  const totalRounds = currentSession.rounds.length;

  return (
    <aside className="w-full lg:w-72 shrink-0 space-y-3">
      {/* Mobile & Tablet Compact Toggle Header */}
      <div className="lg:hidden bg-white rounded-2xl border border-[#e7ebf3] p-3 shadow-xs flex items-center justify-between">
        <button
          onClick={() => setMobileExpanded(!mobileExpanded)}
          className="flex items-center gap-2 text-xs font-bold text-[#1b2230] flex-1 text-left"
        >
          <Layers className="w-4 h-4 text-brand" />
          <span>
            {t.workbench.roundTreeHeader} ({activeRoundIndex + 1}/{totalRounds})
          </span>
          {mobileExpanded ? (
            <ChevronUp className="w-4 h-4 text-[#8c97af]" />
          ) : (
            <ChevronDown className="w-4 h-4 text-[#8c97af]" />
          )}
        </button>

        <button
          onClick={onFocusFollowUp}
          className="p-1.5 px-2.5 rounded-lg bg-brand text-white text-xs font-bold flex items-center gap-1 shadow-xs"
        >
          <Plus className="w-3.5 h-3.5" />
          <span>{locale === 'zh' ? '追问' : locale === 'zh-TW' ? '追問' : 'Ask'}</span>
        </button>
      </div>

      {/* Horizontal Mobile Round Pill Bar (when collapsed on mobile) */}
      <div className="lg:hidden flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none">
        {currentSession.rounds.map((round, idx) => {
          const isActive = idx === activeRoundIndex;
          const checkedCountInRound = round.models.filter(m => m.checked).length;

          return (
            <button
              key={round.id}
              onClick={() => setActiveRoundIndex(idx)}
              className={`px-3 py-1.5 rounded-xl text-xs font-bold whitespace-nowrap shrink-0 flex items-center gap-1.5 border transition-all ${
                isActive
                  ? 'bg-brand text-white border-brand shadow-xs'
                  : 'bg-white text-[#475569] border-[#e2e8f0]'
              }`}
            >
              <span>R{idx + 1}</span>
              {checkedCountInRound > 0 && (
                <span className={`w-4 h-4 rounded-full text-[9px] flex items-center justify-center font-extrabold ${
                  isActive ? 'bg-white text-brand' : 'bg-brand-subtle text-brand'
                }`}>
                  {checkedCountInRound}
                </span>
              )}
            </button>
          );
        })}
      </div>

      {/* Full Tree Container (Desktop always visible, Mobile collapsible) */}
      <div className={`${mobileExpanded ? 'block' : 'hidden lg:block'} bg-white rounded-3xl p-4 sm:p-5 border border-[#e7ebf3] shadow-subtle space-y-4 lg:sticky lg:top-20`}>
        {/* Header */}
        <div className="flex items-center justify-between pb-3 border-b border-[#f1f5f9]">
          <div className="flex items-center gap-2">
            <span className="w-2.5 h-2.5 rounded-full bg-brand"></span>
            <h3 className="font-extrabold text-sm text-[#1b2230]">
              {t.workbench.roundTreeHeader}
            </h3>
          </div>
          <span className="text-[11px] font-mono text-[#8c97af] bg-[#f8fafc] px-2 py-0.5 rounded-md">
            {totalRounds} {t.workbench.roundsTag} · {selectedModelIds.length}/{models.length} {t.workbench.modelsTag}
          </span>
        </div>

        {/* Tree Nodes List */}
        <div className="relative pl-3 space-y-3 before:content-[''] before:absolute before:left-2 before:top-2.5 before:bottom-2.5 before:w-0.5 before:bg-[#e7ebf3]">
          {currentSession.rounds.map((round, idx) => {
            const isActive = idx === activeRoundIndex;
            const checkedCountInRound = round.models.filter(m => m.checked).length;

            return (
              <div
                key={round.id}
                onClick={() => {
                  setActiveRoundIndex(idx);
                  setMobileExpanded(false);
                }}
                className={`relative pl-4 py-2.5 px-3 rounded-2xl cursor-pointer transition-all ${
                  isActive
                    ? 'bg-[#f0f6ff] border border-[#2f6bff]/30 shadow-2xs text-brand'
                    : 'hover:bg-[#f8fafc] text-[#475569]'
                }`}
              >
                {/* Node indicator on vertical line */}
                <span
                  className={`absolute -left-[9px] top-4 w-3.5 h-3.5 rounded-full border-2 transition-all flex items-center justify-center ${
                    isActive
                      ? 'bg-brand border-white ring-2 ring-brand/30 scale-110'
                      : 'bg-white border-[#cbd5e1]'
                  }`}
                >
                  {isActive && <span className="w-1 h-1 rounded-full bg-white"></span>}
                </span>

                  {/* Node Content */}
                  <div className="space-y-1">
                    <div className="flex items-center justify-between gap-1">
                      <span className="text-xs font-bold leading-none flex items-center gap-1.5">
                        <span>
                          {locale === 'zh' ? `第 ${idx + 1} 轮` : locale === 'zh-TW' ? `第 ${idx + 1} 輪` : `Round ${idx + 1}`} · {round.tag}
                        </span>
                        {isThinking && idx === totalRounds - 1 && (
                          <Loader2 className="w-3 h-3 animate-spin text-brand shrink-0" />
                        )}
                      </span>

                    {checkedCountInRound > 0 && (
                      <span className="text-[10px] font-bold px-1.5 py-0.2 rounded-full bg-emerald-100 text-emerald-800 flex items-center gap-0.5">
                        <Check className="w-2.5 h-2.5" />
                        <span>{checkedCountInRound}</span>
                      </span>
                    )}
                  </div>

                  <p className="text-[11px] text-[#8c97af] line-clamp-1 leading-snug">
                    {round.question}
                  </p>

                  {/* Model status chips */}
                  <div className="flex items-center gap-1 pt-1">
                    {round.models.map(m => {
                      const meta = getModelMeta(m.modelKey, m.modelName);
                      return (
                        <span
                          key={m.modelKey}
                          className="w-3.5 h-3.5 rounded text-[8px] font-extrabold text-white flex items-center justify-center"
                          style={{ backgroundColor: meta.color }}
                          title={`${meta.name}: ${m.meta}`}
                        >
                          {meta.avatarText}
                        </span>
                      );
                    })}
                  </div>
                </div>
              </div>
            );
          })}
        </div>

        {/* Action button */}
        <button
          onClick={onFocusFollowUp}
          className="w-full py-2 px-3 rounded-xl border border-dashed border-[#cbd5e1] hover:border-brand text-xs font-bold text-[#65718a] hover:text-brand hover:bg-brand-subtle flex items-center justify-center gap-1.5 transition-all"
        >
          <Plus className="w-3.5 h-3.5" />
          <span>{t.workbench.newFollowUpBtn}</span>
        </button>
      </div>
    </aside>
  );
};
