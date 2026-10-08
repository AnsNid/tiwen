import React from 'react';
import { useSession } from '../../context/SessionContext';
import { useLanguage } from '../../context/LanguageContext';
import { ModelCardItem, type CardDensity } from './ModelCardItem';
import { GlobalBatchBar } from './GlobalBatchBar';
import { EditorZone } from './EditorZone';
import { FollowUpArea } from './FollowUpArea';
import { ChevronLeft, ChevronRight, HelpCircle, CornerDownRight, Check, Loader2 } from 'lucide-react';

interface FocusAreaProps {
  followUpInputRef: React.RefObject<HTMLInputElement>;
}

/** 三列密集对比为默认,卡片数少时收敛列数铺满整行 */
const GRID_DEFAULT = 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3';
const GRID_BY_COUNT: Record<number, string> = {
  1: 'grid-cols-1',
  2: 'grid-cols-1 md:grid-cols-2',
  4: 'grid-cols-1 md:grid-cols-2',
};

export const FocusArea: React.FC<FocusAreaProps> = ({ followUpInputRef }) => {
  const {
    currentSession,
    activeRoundIndex,
    setActiveRoundIndex,
    isThinking,
    getModelMeta,
    selectedModelIds,
    toggleSelectAll,
    isAllCardsChecked,
    checkedInRoundCount,
    selectableCardsCount,
    isSynthesizing
  } = useSession();
  const { t } = useLanguage();

  if (!currentSession) return null;

  const currentRound = currentSession.rounds[activeRoundIndex] || currentSession.rounds[0];
  const totalRounds = currentSession.rounds.length;
  const cards = currentRound?.models || [];
  const gridClass = GRID_BY_COUNT[cards.length] || GRID_DEFAULT;
  // 模型越少单卡越接近阅读态:更少对比对象时给出更高可视区与更大字号,而不是留一片空白
  const density: CardDensity = cards.length === 1 ? 'solo' : cards.length === 2 ? 'duo' : 'grid';

  const isAllChecked = isAllCardsChecked(activeRoundIndex);
  const checkedCount = checkedInRoundCount(activeRoundIndex);
  const selectableCount = selectableCardsCount(activeRoundIndex);
  const isPartial = checkedCount > 0 && !isAllChecked;

  const handlePrev = () => {
    if (activeRoundIndex > 0) setActiveRoundIndex(activeRoundIndex - 1);
  };

  const handleNext = () => {
    if (activeRoundIndex < totalRounds - 1) setActiveRoundIndex(activeRoundIndex + 1);
  };

  return (
    <main className="flex-1 min-w-0 w-full space-y-4">
      {/* Breadcrumb */}
      <div className="flex items-center gap-1.5 sm:gap-2 text-xs text-[#65718a] min-w-0 max-w-full overflow-hidden">
        <span className="shrink-0">{t.workbench.breadcrumbSession}</span>
        <span className="shrink-0">›</span>
        <strong className="text-[#1b2230] font-semibold shrink-0">
          {t.workbench.roundNum.replace('{n}', (activeRoundIndex + 1).toString())}
        </strong>
        <span className="shrink-0">·</span>
        <span className="truncate flex-1 min-w-0 text-[#475569]">{currentRound?.question}</span>
      </div>

      {/* Pager Controller Bar */}
      <div className="flex justify-center w-full">
        <div className="flex items-center justify-between gap-3 sm:gap-6 bg-white p-1.5 sm:p-2 rounded-xl border border-[#e7ebf3] shadow-2xs w-full max-w-sm sm:max-w-md min-w-0">
          <button
            onClick={handlePrev}
            disabled={activeRoundIndex === 0}
            className="px-2.5 sm:px-3 py-1.5 rounded-lg border border-[#e7ebf3] text-xs font-semibold text-[#586079] hover:border-brand hover:text-brand disabled:opacity-35 disabled:cursor-not-allowed flex items-center gap-1 transition-all shrink-0"
          >
            <ChevronLeft className="w-3.5 h-3.5" />
            <span className="hidden sm:inline">{t.workbench.prevRound}</span>
          </button>

          <div className="text-[11px] sm:text-xs font-extrabold text-brand-hover tracking-tight truncate px-1 text-center min-w-0 flex-1">
            {t.workbench.roundIndicator
              .replace('{cur}', (activeRoundIndex + 1).toString())
              .replace('{total}', totalRounds.toString())
              .replace('{tag}', currentRound?.tag || '')}
          </div>

          <button
            onClick={handleNext}
            disabled={activeRoundIndex === totalRounds - 1}
            className="px-2.5 sm:px-3 py-1.5 rounded-lg border border-[#e7ebf3] text-xs font-semibold text-[#586079] hover:border-brand hover:text-brand disabled:opacity-35 disabled:cursor-not-allowed flex items-center gap-1 transition-all shrink-0"
          >
            <span className="hidden sm:inline">{t.workbench.nextRound}</span>
            <ChevronRight className="w-3.5 h-3.5" />
          </button>
        </div>
      </div>

      {/* Current Round Question Bar with Select All */}
      <div className="flex items-start sm:items-center justify-between gap-2.5 sm:gap-3 bg-white border border-[#e7ebf3] rounded-xl p-3 sm:p-3.5 shadow-2xs min-w-0 w-full">
        <div className="flex items-start sm:items-center gap-2.5 sm:gap-3 min-w-0 flex-1">
          <div className="w-6 h-6 sm:w-7 sm:h-7 rounded-lg bg-brand-subtle text-brand flex items-center justify-center shrink-0 mt-0.5 sm:mt-0">
            {activeRoundIndex === 0 ? (
              <HelpCircle className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            ) : (
              <CornerDownRight className="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            )}
          </div>
          <div className="font-bold text-xs sm:text-sm text-[#1b2230] leading-snug min-w-0 break-words">
            {currentRound?.question}
          </div>
        </div>

        <div className="flex items-center gap-2.5 shrink-0 self-end sm:self-auto">
          <div className="text-[11px] text-[#8c97af] whitespace-nowrap hidden md:block">
            {t.workbench.questionBarMeta
              .replace('{count}', (currentRound?.models.length || selectedModelIds.length).toString())
              .replace('{n}', (activeRoundIndex + 1).toString())}
            {activeRoundIndex > 0 ? t.workbench.carriedContext : ''}
          </div>

          {/* Quick Select All Button */}
          {cards.length > 0 && (
            <button
              type="button"
              onClick={() => toggleSelectAll(activeRoundIndex)}
              disabled={selectableCount === 0 || isSynthesizing}
              title={isAllChecked ? '取消全选本轮所有模型卡片' : '全选本轮所有模型卡片'}
              className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border text-[11px] font-bold transition-all select-none cursor-pointer ${
                isAllChecked
                  ? 'bg-brand text-white border-brand shadow-2xs hover:bg-brand-hover'
                  : isPartial
                  ? 'bg-brand-subtle text-brand border-brand/40 hover:bg-brand/15'
                  : 'bg-white text-[#64748b] border-[#e2e8f0] hover:text-brand hover:border-brand hover:bg-[#f8fafc]'
              } disabled:opacity-40 disabled:cursor-not-allowed`}
            >
              <div
                className={`w-3 h-3 rounded border flex items-center justify-center transition-colors ${
                  isAllChecked
                    ? 'bg-white border-white text-brand'
                    : isPartial
                    ? 'bg-brand border-brand text-white'
                    : 'bg-white border-[#94a3b8]'
                }`}
              >
                {isAllChecked ? (
                  <Check className="w-2 h-2 stroke-[3]" />
                ) : isPartial ? (
                  <span className="w-1 h-0.5 bg-white rounded-full" />
                ) : null}
              </div>
              <span>
                {isAllChecked
                  ? '取消全选'
                  : isPartial
                  ? `全选 (${checkedCount}/${selectableCount})`
                  : '全选本轮'}
              </span>
            </button>
          )}
        </div>
      </div>

      {/* Model Cards Grid or Thinking State
          注意:流式轮次直接渲染卡片网格(卡片自带连接中/流式光标状态),
          「大思考占位卡」仅用于轮次尚无任何卡片的过渡态,避免遮住实时输出 */}
      {currentRound?.pending || (isThinking && !currentRound?.models.length) ? (
        <div className="bg-white border-2 border-dashed border-[#dce8ff] rounded-2xl p-6 sm:p-8 flex flex-col items-center justify-center gap-3 text-center w-full min-w-0">
          <div className="flex items-center gap-2">
            {selectedModelIds.map(id => {
              const m = getModelMeta(id);
              return (
                <div
                  key={id}
                  className="w-6 h-6 sm:w-7 sm:h-7 rounded-lg text-white font-bold text-xs flex items-center justify-center"
                  style={{ backgroundColor: m.color }}
                >
                  {m.avatarText}
                </div>
              );
            })}
          </div>
          <div className="flex items-center gap-2 text-xs sm:text-sm font-semibold text-brand">
            <span>{t.workbench.thinkingTitle}</span>
            <div className="flex gap-1 items-center">
              <span className="w-1.5 h-1.5 rounded-full bg-brand animate-dot-1"></span>
              <span className="w-1.5 h-1.5 rounded-full bg-brand animate-dot-2"></span>
              <span className="w-1.5 h-1.5 rounded-full bg-brand animate-dot-3"></span>
            </div>
          </div>
          <p className="text-xs text-[#8c97af]">
            {t.workbench.thinkingSub.replace('{n}', activeRoundIndex.toString())}
          </p>
        </div>
      ) : (
        <div className={`grid ${gridClass} gap-3.5 items-start w-full min-w-0`}>
          {cards.map(card => (
            <ModelCardItem
              key={card.id}
              card={card}
              roundIndex={activeRoundIndex}
              question={currentRound.question}
              density={density}
            />
          ))}
        </div>
      )}

      {/* Global Batch Action Bar */}
      <GlobalBatchBar />

      {/* Follow-up Question Input (追问区) */}
      <FollowUpArea inputRef={followUpInputRef} />

      {/* 综合分析运行中常驻状态提示 (直到输出结束前保持在页面不消失) */}
      {isSynthesizing && (
        <div className="bg-gradient-to-r from-blue-50/90 via-indigo-50/80 to-purple-50/80 border border-blue-200/90 rounded-2xl p-3.5 sm:p-4 shadow-subtle flex items-center justify-between gap-3 animate-in fade-in slide-in-from-top-2 duration-300">
          <div className="flex items-center gap-3 min-w-0">
            <div className="w-9 h-9 rounded-xl bg-brand text-white flex items-center justify-center shadow-2xs shrink-0">
              <Loader2 className="w-5 h-5 animate-spin" />
            </div>
            <div className="space-y-0.5 min-w-0">
              <div className="flex items-center gap-2">
                <h4 className="text-xs sm:text-sm font-extrabold text-[#0f172a] truncate">
                  ⚡ 正在进行多模型观点交叉比对与分析综合...
                </h4>
                <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-brand text-white shrink-0">
                  实时分析中
                </span>
              </div>
              <p className="text-[11px] text-[#64748b] leading-tight truncate">
                AI 正在融合已选观点并结构化提炼核心研报，输出结束前请稍候，结果将自动同步至下方编辑区...
              </p>
            </div>
          </div>
          <div className="flex items-center gap-1.5 shrink-0 pr-1">
            <span className="w-2.5 h-2.5 rounded-full bg-brand animate-ping" />
          </div>
        </div>
      )}

      {/* Draft & Synthesis Editor Zone (编辑区) */}
      <EditorZone />
    </main>
  );
};
