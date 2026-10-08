import React, { useState } from 'react';
import { useSession } from '../../context/SessionContext';
import { useLanguage } from '../../context/LanguageContext';
import { ModelSelector } from './ModelSelector';
import { AttachmentButton, AttachmentPreview, AttachmentData } from './AttachmentField';
import { WebSearchToggle } from './WebSearchToggle';
import { Loader2, CornerDownLeft, Sparkles } from 'lucide-react';

interface FollowUpAreaProps {
  inputRef?: React.RefObject<HTMLInputElement>;
}

export const FollowUpArea: React.FC<FollowUpAreaProps> = ({ inputRef }) => {
  const {
    currentSession,
    isThinking,
    sendFollowUp,
    showToast,
    webSearchEnabled,
    smartSearch,
    setSmartSearch,
  } = useSession();
  const { t, locale } = useLanguage();
  const [question, setQuestion] = useState('');
  const [attachment, setAttachment] = useState<AttachmentData | null>(null);

  if (!currentSession) return null;

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!question.trim() || isThinking) return;
    sendFollowUp(question.trim(), attachment);
    setQuestion('');
    setAttachment(null);
  };

  const handleOptimizePrompt = () => {
    if (!question.trim()) {
      showToast(
        locale === 'zh'
          ? '请先输入提问内容'
          : locale === 'zh-TW'
          ? '請先輸入提問內容'
          : 'Please type your question first'
      );
      return;
    }
    const optimized =
      locale === 'zh'
        ? `请针对「${question}」，分别从技术路线演进、供应链降本良率、市场规模测算及商业落地壁垒四个维度进行系统化深度拆解，并给出可量化的对比指标。`
        : locale === 'zh-TW'
        ? `請針對「${question}」，分別從技術路線演進、供應鏈降本良率、市場規模測算及商業落地壁壘四個維度進行系統化深度拆解，並給出可量化的對比指標。`
        : `Please systematically deconstruct "${question}" across 4 dimensions: technical roadmap, supply chain yield, market TAM, and commercialization barriers with measurable KPIs.`;
    setQuestion(optimized);
    showToast(
      locale === 'zh'
        ? '✨ Prompt 已优化增强'
        : locale === 'zh-TW'
        ? '✨ Prompt 已最佳化增強'
        : '✨ Prompt optimized'
    );
  };

  return (
    <div className="bg-white rounded-3xl p-4 sm:p-5 border border-[#e7ebf3] shadow-card space-y-3">
      {/* Header Title */}
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div className="flex items-center gap-2">
          <span className="w-2.5 h-2.5 rounded-full bg-brand"></span>
          <h3 className="font-extrabold text-sm text-[#1b2230]">
            {t.workbench.followUpTitle}
          </h3>
        </div>
        <span className="text-[11px] text-[#8c97af] font-mono">
          {t.workbench.currentDialogRounds.replace('{n}', currentSession.rounds.length.toString())}
        </span>
      </div>

      {/* 并发对比模型选择 */}
      <ModelSelector />

      {/* Input Box Form */}
      <form onSubmit={handleSubmit} className="space-y-2.5">
        <div className="relative rounded-2xl border border-[#cbd5e1] focus-within:border-brand focus-within:ring-4 focus-within:ring-brand-subtle transition-all bg-[#fafcff] p-2.5 sm:p-3 space-y-2">
          {/* 独立附件预览卡片（单独成行，不占用输入框宽度） */}
          {attachment && (
            <div className="flex items-center pt-0.5">
              <AttachmentPreview
                value={attachment}
                onRemove={() => setAttachment(null)}
                disabled={isThinking}
              />
            </div>
          )}

          {/* 输入框主行 + 操作按钮 */}
          <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2">
            <input
              ref={inputRef}
              type="text"
              value={question}
              onChange={e => setQuestion(e.target.value)}
              disabled={isThinking}
              placeholder={t.workbench.followUpPlaceholder}
              className="flex-1 px-2.5 py-1.5 text-xs sm:text-sm text-[#1b2230] placeholder:text-[#94a3b8] focus:outline-none bg-transparent"
            />

            <div className="flex items-center gap-1.5 sm:gap-2 self-end sm:self-auto shrink-0">
              {/* 联网搜索勾选 */}
              {webSearchEnabled && (
                <WebSearchToggle
                  active={smartSearch}
                  onToggle={() => setSmartSearch(!smartSearch)}
                  disabled={isThinking}
                />
              )}

              {/* 附件上传按钮 */}
              <AttachmentButton
                hasAttachment={!!attachment}
                onUploaded={setAttachment}
                disabled={isThinking}
              />

              {/* Prompt Optimizer */}
              <button
                type="button"
                onClick={handleOptimizePrompt}
                className="px-2.5 py-1.5 rounded-xl border border-[#e2e8f0] bg-white text-[11px] font-semibold text-[#64748b] hover:text-brand hover:border-brand flex items-center gap-1 transition-colors"
                title="Optimize Prompt / 结构化增强"
              >
                <Sparkles className="w-3 h-3 text-amber-500" />
                <span>{t.workbench.optimizePrompt}</span>
              </button>

              {/* Submit Button */}
              <button
                type="submit"
                disabled={!question.trim() || isThinking}
                className="px-4 py-2 rounded-xl bg-brand hover:bg-brand-hover text-white font-bold text-xs disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-1.5 shadow-xs transition-all"
              >
                {isThinking ? (
                  <>
                    <Loader2 className="w-3.5 h-3.5 animate-spin" />
                    <span>{t.workbench.reasoning}</span>
                  </>
                ) : (
                  <>
                    <span>{t.workbench.send}</span>
                    <CornerDownLeft className="w-3.5 h-3.5" />
                  </>
                )}
              </button>
            </div>
          </div>
        </div>
      </form>
    </div>
  );
};
