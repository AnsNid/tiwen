import React, { useState } from 'react';
import { ModelCardResponse } from '../../types';
import { useSession } from '../../context/SessionContext';
import { useLanguage } from '../../context/LanguageContext';
import { exportMarkdown } from '../../utils/exportDoc';
import { MarkdownRenderer } from '../common/MarkdownRenderer';
import { MindmapViewer } from '../common/MindmapViewer';
import { Check, Download, Trash2, Maximize2, X, GitBranch, FileText, RotateCw, AlertCircle } from 'lucide-react';

interface ModelCardItemProps {
  card: ModelCardResponse;
  roundIndex: number;
  question: string;
  /** 卡片密度由本轮对比模型数决定:越少越接近阅读态 */
  density?: CardDensity;
}

export type CardDensity = 'solo' | 'duo' | 'grid';

interface DensityChrome {
  header: string;
  body: string;
  bodyInner: string;
  footer: string;
  mdSize: 'xs' | 'sm';
}

const DENSITY: Record<CardDensity, DensityChrome> = {
  // 单模型独占整行:提高可视区、放大字号,并把行文宽度收在可读范围内居中
  solo: {
    header: 'px-5 py-3',
    body: 'p-5 text-sm max-h-[min(66vh,720px)]',
    bodyInner: 'max-w-[860px] mx-auto',
    footer: 'px-4 py-2.5',
    mdSize: 'sm',
  },
  // 双模型各占半行:略高于三列密集态
  duo: {
    header: 'px-4 py-3',
    body: 'p-4 text-[13px] max-h-[min(54vh,540px)]',
    bodyInner: '',
    footer: 'px-3.5 py-2',
    mdSize: 'sm',
  },
  grid: {
    header: 'px-3.5 py-2.5',
    body: 'p-3.5 text-xs max-h-[300px]',
    bodyInner: '',
    footer: 'px-3 py-2',
    mdSize: 'xs',
  },
};

export const ModelCardItem: React.FC<ModelCardItemProps> = ({ card, roundIndex, question, density = 'grid' }) => {
  const { toggleCardCheck, deleteCard, retryCard, showToast, getModelMeta } = useSession();
  const { t } = useLanguage();
  const [modalOpen, setModalOpen] = useState(false);
  const [modalTab, setModalTab] = useState<'md' | 'mindmap'>('md');
  const meta = getModelMeta(card.modelKey, card.modelName);
  const displayName = card.modelName || meta.name;
  const d = DENSITY[density];

  const handleExportSingle = () => {
    const text = `# ${question}\n\n**${displayName}** (${card.meta})\n\n${card.body}`;
    exportMarkdown(text, `${displayName}-answer.md`);
    showToast(`Exported ${displayName} response`);
  };

  return (
    <>
      <div
        className={`bg-white rounded-2xl border transition-all flex flex-col justify-between overflow-hidden shadow-subtle min-w-0 ${
          card.checked
            ? 'border-brand ring-2 ring-brand-subtle'
            : 'border-[#e7ebf3] hover:border-[#cbd5e1]'
        }`}
      >
        {/* Card Header */}
        <div className={`flex items-center justify-between gap-2 ${d.header} border-b border-[#f0f3f8] bg-[#fafcff]`}>
          <div className="flex items-center gap-2.5 min-w-0">
            {/* Custom Checkbox */}
            <button
              type="button"
              onClick={() => toggleCardCheck(roundIndex, card.id)}
              className={`w-4 h-4 rounded-md border flex items-center justify-center transition-all shrink-0 ${
                card.checked
                  ? 'bg-brand border-brand text-white'
                  : 'bg-white border-[#cbd5e1] hover:border-brand text-transparent'
              }`}
            >
              <Check className="w-3 h-3 stroke-[3]" />
            </button>

            {/* Model Avatar */}
            <div
              className="w-6 h-6 rounded-lg text-white font-bold text-xs flex items-center justify-center shadow-2xs shrink-0"
              style={{ backgroundColor: meta.color }}
            >
              {meta.avatarText}
            </div>

            <span className="text-xs font-bold text-[#1b2230] truncate">{displayName}</span>
          </div>

          <div className="flex items-center gap-1.5 shrink-0">
            {card.isStreaming && (
              <span className="w-2 h-2 rounded-full bg-brand animate-ping mr-1"></span>
            )}
            {card.failed ? (
              <div className="flex items-center gap-1.5">
                <span className="text-[11px] font-mono text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded-md">
                  {card.meta || '输出异常'}
                </span>
                <button
                  type="button"
                  onClick={() => retryCard(roundIndex, card.id)}
                  disabled={card.isStreaming}
                  className="w-5 h-5 rounded-full border border-slate-200 bg-white hover:bg-slate-50 hover:border-slate-300 text-slate-500 hover:text-slate-700 flex items-center justify-center shadow-2xs transition-all cursor-pointer"
                  title="重试此模型"
                >
                  <RotateCw className={`w-2.5 h-2.5 ${card.isStreaming ? 'animate-spin' : ''}`} />
                </button>
              </div>
            ) : (
              <span className="text-[11px] font-mono text-[#8c97af]">{card.meta}</span>
            )}
          </div>
        </div>

        {/* Card Body with Rich Markdown & Live Streaming Cursor */}
        <div className={`${d.body} text-[#334155] leading-relaxed overflow-y-auto select-text font-normal break-words min-w-0`}>
          <div className={d.bodyInner}>
            {card.failed ? (
              <div className="p-3.5 bg-slate-50 border border-slate-200/90 rounded-xl text-slate-700 text-xs space-y-2.5">
                <div className="flex items-start gap-2">
                  <AlertCircle className="w-4 h-4 text-slate-400 mt-0.5 shrink-0" />
                  <div className="space-y-1 min-w-0 flex-1">
                    <div className="font-semibold text-slate-800 leading-snug">
                      {card.failMessage || '输出异常，请稍后重试'}
                    </div>
                    <div className="text-[11px] text-slate-500">
                      {t.workbench.streamRetryTip}
                    </div>
                  </div>
                </div>
                <div className="pt-0.5">
                  <button
                    type="button"
                    onClick={() => retryCard(roundIndex, card.id)}
                    disabled={card.isStreaming}
                    className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:border-brand hover:text-brand hover:bg-brand-subtle shadow-2xs transition-all cursor-pointer group"
                  >
                    <span className="w-4 h-4 rounded-full bg-slate-100 group-hover:bg-brand/10 flex items-center justify-center transition-colors">
                      <RotateCw className={`w-2.5 h-2.5 text-slate-600 group-hover:text-brand transition-transform ${card.isStreaming ? 'animate-spin' : 'group-hover:rotate-180 duration-300'}`} />
                    </span>
                    <span>{card.isStreaming ? '正在重试...' : '重试'}</span>
                  </button>
                </div>
              </div>
            ) : card.body ? (
              <MarkdownRenderer content={card.body} size={d.mdSize} />
            ) : (
              <div className="flex items-center gap-2 text-xs text-brand font-medium animate-pulse py-4">
                <span className="w-2 h-2 rounded-full bg-brand"></span>
                <span>正在连接并发推理引擎...</span>
              </div>
            )}

            {card.isStreaming && (
              <span className="inline-block w-2 h-4 bg-brand ml-1 animate-pulse align-middle" />
            )}
          </div>
        </div>

        {/* Card Actions Footer */}
        <div className={`${d.footer} border-t border-[#f0f3f8] bg-[#fafcff] flex items-center justify-between gap-1`}>
          <div className="flex items-center gap-1">
            {card.failed ? (
              <button
                onClick={() => retryCard(roundIndex, card.id)}
                disabled={card.isStreaming}
                className="px-2 py-1 rounded-md text-[11px] font-medium text-slate-600 hover:text-brand hover:bg-brand-subtle flex items-center gap-1 transition-colors"
                title="重试此模型"
              >
                <RotateCw className={`w-3 h-3 ${card.isStreaming ? 'animate-spin' : ''}`} />
                <span>重试</span>
              </button>
            ) : (
              <>
                <button
                  onClick={() => {
                    setModalTab('md');
                    setModalOpen(true);
                  }}
                  className="px-2 py-1 rounded-md text-[11px] font-medium text-[#64748b] hover:text-brand hover:bg-brand-subtle flex items-center gap-1 transition-colors"
                >
                  <Maximize2 className="w-3 h-3" />
                  <span>{t.workbench.fulltext}</span>
                </button>

                <button
                  onClick={() => {
                    setModalTab('mindmap');
                    setModalOpen(true);
                  }}
                  className="px-2 py-1 rounded-md text-[11px] font-medium text-[#64748b] hover:text-brand hover:bg-brand-subtle flex items-center gap-1 transition-colors"
                  title="查看思维导图"
                >
                  <GitBranch className="w-3 h-3 text-purple-600" />
                  <span>导图</span>
                </button>

                <button
                  onClick={handleExportSingle}
                  className="px-2 py-1 rounded-md text-[11px] font-medium text-[#64748b] hover:text-brand hover:bg-brand-subtle flex items-center gap-1 transition-colors"
                >
                  <Download className="w-3 h-3" />
                  <span>{t.workbench.exportSingle}</span>
                </button>
              </>
            )}
          </div>

          <button
            onClick={() => deleteCard(roundIndex, card.id)}
            className="p-1 rounded-md text-[#94a3b8] hover:text-red-600 hover:bg-red-50 transition-colors"
            title={t.workbench.deleteCard}
          >
            <Trash2 className="w-3.5 h-3.5" />
          </button>
        </div>
      </div>

      {/* Fulltext Modal with Markdown & Mindmap Switcher */}
      {modalOpen && (
        <div className="fixed inset-0 z-[120] flex items-center justify-center p-3 sm:p-4">
          <div
            className="fixed inset-0 bg-[#0f172a]/60 backdrop-blur-xs"
            onClick={() => setModalOpen(false)}
          ></div>
          <div className="relative bg-white rounded-3xl shadow-2xl border border-[#e2e8f0] w-full max-w-3xl max-h-[88vh] flex flex-col overflow-hidden z-10 animate-in fade-in zoom-in-95 duration-150">
            {/* Modal Header */}
            <div className="p-4 px-5 border-b border-[#e7ebf3] flex items-center justify-between bg-[#fafcff]">
              <div className="flex items-center gap-3">
                <div
                  className="w-7 h-7 rounded-xl text-white font-bold text-xs flex items-center justify-center shadow-xs"
                  style={{ backgroundColor: meta.color }}
                >
                  {meta.avatarText}
                </div>
                <div>
                  <h4 className="text-sm font-bold text-[#1b2230]">{displayName} · 全屏推演视图</h4>
                  <p className="text-[11px] font-mono text-[#64748b]">{card.meta}</p>
                </div>
              </div>

              <div className="flex items-center gap-2">
                {/* Switcher tabs */}
                <div className="flex bg-[#f1f5f9] p-0.5 rounded-xl text-xs font-bold">
                  <button
                    onClick={() => setModalTab('md')}
                    className={`px-3 py-1 rounded-lg flex items-center gap-1 transition-all ${
                      modalTab === 'md' ? 'bg-white text-brand shadow-2xs' : 'text-[#64748b]'
                    }`}
                  >
                    <FileText className="w-3.5 h-3.5" />
                    <span>排版全文</span>
                  </button>
                  <button
                    onClick={() => setModalTab('mindmap')}
                    className={`px-3 py-1 rounded-lg flex items-center gap-1 transition-all ${
                      modalTab === 'mindmap' ? 'bg-white text-brand shadow-2xs' : 'text-[#64748b]'
                    }`}
                  >
                    <GitBranch className="w-3.5 h-3.5 text-purple-600" />
                    <span>思维导图</span>
                  </button>
                </div>

                <button
                  onClick={() => setModalOpen(false)}
                  className="p-1.5 rounded-xl text-[#8c97af] hover:text-[#1b2230] hover:bg-[#f1f5f9]"
                >
                  <X className="w-4 h-4" />
                </button>
              </div>
            </div>

            {/* Modal Content */}
            <div className="p-5 sm:p-7 overflow-y-auto space-y-4">
              <div className="p-3 bg-brand-subtle rounded-2xl text-brand font-bold text-xs sm:text-sm border border-brand/20">
                {question}
              </div>

              {modalTab === 'md' ? (
                <MarkdownRenderer content={card.body} className="text-xs sm:text-sm" />
              ) : (
                <MindmapViewer markdownText={card.body} title={`${displayName} · 逻辑思维导图`} />
              )}
            </div>

            {/* Modal Footer */}
            <div className="p-3.5 px-5 border-t border-[#e7ebf3] bg-[#fafcff] flex items-center justify-between">
              <span className="text-[11px] text-[#8c97af]">
                已接入实时 Markdown & 结构树多维渲染
              </span>
              <button
                onClick={handleExportSingle}
                className="px-3.5 py-1.5 rounded-xl text-xs font-bold bg-brand text-white hover:bg-brand-hover flex items-center gap-1.5 shadow-xs transition-colors"
              >
                <Download className="w-3.5 h-3.5" />
                <span>导出此篇答案</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </>
  );
};
