import React, { useState, useRef, useEffect } from 'react';
import { useSession } from '../../context/SessionContext';
import { useAuth } from '../../context/AuthContext';
import { useLanguage } from '../../context/LanguageContext';
import { exportMarkdown, exportHtml, exportWord, exportPdf } from '../../utils/exportDoc';
import { exportMindmapPng } from '../../utils/exportMindmap';
import { MarkdownRenderer } from '../common/MarkdownRenderer';
import { MindmapViewer } from '../common/MindmapViewer';
import {
  FileEdit,
  Download,
  Copy,
  Trash2,
  ChevronDown,
  FileText,
  FileCode,
  FileSpreadsheet,
  Printer,
  Eye,
  GitBranch,
  Edit3,
  ImageDown,
  Crown,
  Loader2,
  CheckCircle2,
  Sparkles,
  X,
  FileCheck
} from 'lucide-react';

interface ExportModalState {
  format: 'md' | 'html' | 'word' | 'pdf' | 'mindmap';
  formatLabel: string;
  currentStep: number; // 1, 2, 3, 4
  progress: number; // 0..100
  detailText: string;
  isDone: boolean;
}

export const EditorZone: React.FC = () => {
  const { currentSession, updateEditorDraft, showToast } = useSession();
  const { user } = useAuth();
  const { t } = useLanguage();
  const [exportMenuOpen, setExportMenuOpen] = useState(false);
  const [editorTab, setEditorTab] = useState<'edit' | 'preview' | 'mindmap'>('edit');
  const [exportModal, setExportModal] = useState<ExportModalState | null>(null);

  const timerRef1 = useRef<number | null>(null);
  const timerRef2 = useRef<number | null>(null);
  const timerRef3 = useRef<number | null>(null);
  const timerRef4 = useRef<number | null>(null);
  const timerRef5 = useRef<number | null>(null);
  const timerRef6 = useRef<number | null>(null);

  // 清理计时器
  const clearExportTimers = () => {
    if (timerRef1.current) clearTimeout(timerRef1.current);
    if (timerRef2.current) clearTimeout(timerRef2.current);
    if (timerRef3.current) clearTimeout(timerRef3.current);
    if (timerRef4.current) clearTimeout(timerRef4.current);
    if (timerRef5.current) clearTimeout(timerRef5.current);
    if (timerRef6.current) clearTimeout(timerRef6.current);
  };

  useEffect(() => {
    return () => clearExportTimers();
  }, []);

  if (!currentSession) return null;

  const content = currentSession.editorDraft;
  const wordCount = content.trim().length;
  // 导图图片导出为 Pro 专属(pro/team 可用)
  const isPaid = !!user && user.plan !== 'free';

  const handleCopy = () => {
    if (!content) {
      showToast('No content to copy / 编辑区尚无内容可复制');
      return;
    }
    navigator.clipboard.writeText(content);
    showToast('Copied to clipboard / 已复制编辑区全部内容到剪贴板');
  };

  const handleClear = () => {
    if (!content) return;
    if (window.confirm('Clear editor content? / 确定要清空编辑区内容吗？')) {
      updateEditorDraft('');
      showToast('Editor cleared / 编辑区已清空');
    }
  };

  // 执行实际下载
  const executeDownload = async (type: 'md' | 'html' | 'word' | 'pdf' | 'mindmap') => {
    const title = currentSession.title || 'tiwen-report';
    if (type === 'md') {
      exportMarkdown(content, `${title}.md`);
    } else if (type === 'html') {
      exportHtml(content, title, `${title}.html`);
    } else if (type === 'word') {
      exportWord(content, title, `${title}.doc`);
    } else if (type === 'pdf') {
      try {
        await exportPdf(content, title, `${title}.pdf`);
      } catch (err: any) {
        console.error('PDF export error:', err);
        showToast(`PDF 导出失败: ${err?.message || '未知错误'}`);
      }
    } else if (type === 'mindmap') {
      try {
        await exportMindmapPng(content, `${title} · 逻辑结构导图`, title);
      } catch (e) {
        showToast(t.workbench.mindmapExportFailed);
      }
    }
  };

  // 启动多步骤模拟导出动效（节奏舒适、时间适度延长）
  const startExportWithAnimation = (type: 'md' | 'html' | 'word' | 'pdf' | 'mindmap') => {
    setExportMenuOpen(false);
    if (!content.trim()) {
      showToast('Please adopt or synthesize content first / 编辑区尚无内容，请先采纳或综合分析');
      return;
    }

    if (type === 'mindmap' && !isPaid) {
      showToast(t.workbench.mindmapProOnly);
      return;
    }

    let formatLabel = 'Markdown (.md)';
    if (type === 'pdf') formatLabel = 'PDF (.pdf)';
    if (type === 'html') formatLabel = 'HTML (.html)';
    if (type === 'word') formatLabel = 'Word (.docx)';
    if (type === 'mindmap') formatLabel = '思维导图 (.png)';

    clearExportTimers();

    // 步骤 1: 扫描与解析 (0ms) -> Step 1, Progress 18%
    setExportModal({
      format: type,
      formatLabel,
      currentStep: 1,
      progress: 18,
      detailText: `正在扫描研报大纲与 ${wordCount} 字文档结构...`,
      isDone: false,
    });

    // 步骤 1 推进 (550ms) -> Progress 36%
    timerRef1.current = window.setTimeout(() => {
      setExportModal(prev => prev ? {
        ...prev,
        progress: 36,
        detailText: '段落树状结构与 Markdown 节点解析完成'
      } : null);
    }, 550);

    // 步骤 2: 排版引擎渲染与样式编译 (1200ms) -> Step 2, Progress 58%
    timerRef2.current = window.setTimeout(() => {
      setExportModal(prev => prev ? {
        ...prev,
        currentStep: 2,
        progress: 58,
        detailText: '正在编译 CSS 样式表、排版规范与语法高亮引擎...'
      } : null);
    }, 1200);

    // 步骤 2 推进 (1850ms) -> Progress 76%
    timerRef3.current = window.setTimeout(() => {
      setExportModal(prev => prev ? {
        ...prev,
        progress: 76,
        detailText: '视觉排版渲染就绪，准备封装格式数据...'
      } : null);
    }, 1850);

    // 步骤 3: 封装并生成目标格式数据流 (2450ms) -> Step 3, Progress 89%
    timerRef4.current = window.setTimeout(() => {
      setExportModal(prev => prev ? {
        ...prev,
        currentStep: 3,
        progress: 89,
        detailText: `正在封装并组装 ${formatLabel} 二进制流数据...`
      } : null);
    }, 2450);

    // 步骤 4: 导出就绪，触发文件下载 (3200ms) -> Step 4, Progress 100%, isDone = true
    timerRef5.current = window.setTimeout(() => {
      setExportModal(prev => prev ? {
        ...prev,
        currentStep: 4,
        progress: 100,
        detailText: '文件已生成完毕，正在拉起浏览器本地下载...',
        isDone: true
      } : null);
      executeDownload(type);
    }, 3200);

    // 完成后自动关闭 (4500ms)
    timerRef6.current = window.setTimeout(() => {
      setExportModal(null);
      showToast(`✨ ${formatLabel} 导出完成！`);
    }, 4500);
  };

  const stepsList = [
    { step: 1, title: '解析研报文档结构', desc: '扫描段落、多级章节与 Markdown 语法树' },
    { step: 2, title: '排版渲染与样式编译', desc: '注入高阶视觉排版规范与高亮样式表' },
    { step: 3, title: `封装 ${exportModal?.formatLabel || ''} 数据流`, desc: '生成目标数据流并进行元数据校验' },
    { step: 4, title: '导出就绪与触发下载', desc: '本地渲染完成，拉起浏览器文件下载' },
  ];

  return (
    <>
      <div className="bg-gradient-to-b from-[#f0f6ff]/70 to-[#f8fbff] border-1.5 border-dashed border-brand/70 rounded-3xl p-4 sm:p-5 shadow-subtle relative space-y-3">
        {/* Editor Header */}
        <div className="flex flex-wrap items-center justify-between gap-2.5 pb-2 border-b border-[#e2e8f0]">
          <div className="flex items-center gap-2">
            <FileEdit className="w-4 h-4 text-brand" />
            <h3 className="font-extrabold text-sm text-brand-hover">{t.workbench.editorTitle}</h3>
            <span className="text-[11px] text-[#65718a] hidden md:inline">
              {t.workbench.editorSubtitle}
            </span>
          </div>

          {/* Action Tools (Top Right) */}
          <div className="flex items-center gap-1.5 sm:gap-2">
            {/* Mode Switcher Tabs */}
            <div className="flex bg-white border border-[#cbd5e1] p-0.5 rounded-xl text-xs font-bold shadow-2xs">
              <button
                onClick={() => setEditorTab('edit')}
                className={`px-2.5 py-1 rounded-lg flex items-center gap-1 transition-all ${
                  editorTab === 'edit' ? 'bg-brand text-white shadow-2xs' : 'text-[#475569] hover:text-[#0f172a]'
                }`}
                title="Markdown 源代码编辑"
              >
                <Edit3 className="w-3.5 h-3.5" />
                <span className="hidden sm:inline">编辑</span>
              </button>

              <button
                onClick={() => setEditorTab('preview')}
                className={`px-2.5 py-1 rounded-lg flex items-center gap-1 transition-all ${
                  editorTab === 'preview' ? 'bg-brand text-white shadow-2xs' : 'text-[#475569] hover:text-[#0f172a]'
                }`}
                title="富文本渲染预览"
              >
                <Eye className="w-3.5 h-3.5" />
                <span className="hidden sm:inline">排版预览</span>
              </button>

              <button
                onClick={() => setEditorTab('mindmap')}
                className={`px-2.5 py-1 rounded-lg flex items-center gap-1 transition-all ${
                  editorTab === 'mindmap' ? 'bg-brand text-white shadow-2xs' : 'text-[#475569] hover:text-[#0f172a]'
                }`}
                title="思维导图树"
              >
                <GitBranch className="w-3.5 h-3.5 text-purple-600" />
                <span className="hidden sm:inline">导图</span>
              </button>
            </div>

            <button
              onClick={handleCopy}
              className="p-1.5 px-2 rounded-lg text-xs font-semibold bg-white border border-[#cbd5e1] text-[#475569] hover:text-brand hover:border-brand flex items-center gap-1 transition-all shadow-2xs"
              title={t.workbench.copy}
            >
              <Copy className="w-3.5 h-3.5" />
              <span className="hidden sm:inline">{t.workbench.copy}</span>
            </button>

            <button
              onClick={handleClear}
              disabled={!content}
              className="p-1.5 px-2 rounded-lg text-xs font-semibold bg-white border border-[#cbd5e1] text-[#475569] hover:text-red-600 hover:border-red-300 disabled:opacity-40 disabled:cursor-not-allowed flex items-center gap-1 transition-all shadow-2xs"
              title={t.workbench.clear}
            >
              <Trash2 className="w-3.5 h-3.5" />
            </button>
          </div>
        </div>

        {/* Editor Views */}
        {editorTab === 'edit' && (
          <textarea
            value={content}
            onChange={e => updateEditorDraft(e.target.value)}
            placeholder={t.workbench.editorPlaceholder}
            rows={16}
            className="w-full min-h-[420px] p-3.5 bg-white border border-[#e2e8f0] rounded-2xl text-xs sm:text-sm text-[#1b2230] leading-relaxed placeholder:text-[#9aa4b6] focus:outline-none focus:border-brand focus:ring-3 focus:ring-brand-subtle transition-all resize-y font-mono font-normal shadow-2xs"
          />
        )}

        {editorTab === 'preview' && (
          <div className="bg-white rounded-2xl p-5 border border-[#e2e8f0] min-h-[420px] max-h-[640px] overflow-y-auto shadow-2xs">
            {content.trim() ? (
              <MarkdownRenderer content={content} />
            ) : (
              <div className="p-8 text-center text-xs text-[#8c97af]">
                {t.workbench.editorPlaceholder}
              </div>
            )}
          </div>
        )}

        {editorTab === 'mindmap' && (
          <div className="space-y-2.5 min-h-[420px] max-h-[640px] overflow-y-auto">
            <MindmapViewer markdownText={content} title={`${currentSession.title} · 逻辑结构导图`} />
          </div>
        )}

        {/* Bottom Action Bar: Export Dropdown placed at Bottom-Left of textarea */}
        <div className="flex flex-wrap items-center justify-between gap-3 pt-1 border-t border-[#edf2f7]">
          {/* Export Multi-Format Dropdown (Bottom Left) */}
          <div className="relative">
            <button
              type="button"
              onClick={() => setExportMenuOpen(!exportMenuOpen)}
              className="py-2 px-3.5 rounded-xl text-xs font-bold bg-brand text-white hover:bg-brand-hover active:scale-[0.98] flex items-center gap-2 shadow-xs transition-all cursor-pointer"
              title="选择格式导出当前研报内容"
            >
              <Download className="w-3.5 h-3.5" />
              <span>{t.workbench.exportReport}</span>
              <ChevronDown className={`w-3.5 h-3.5 opacity-80 transition-transform duration-200 ${exportMenuOpen ? 'rotate-180' : ''}`} />
            </button>

            {exportMenuOpen && (
              <>
                <div
                  className="fixed inset-0 z-20"
                  onClick={() => setExportMenuOpen(false)}
                />
                <div className="absolute left-0 bottom-full mb-2 w-56 bg-white rounded-2xl shadow-xl border border-[#e2e8f0] p-1.5 z-30 animate-in fade-in slide-in-from-bottom-2 duration-150">
                  <div className="px-3 py-1.5 text-[10px] font-bold text-[#8c97af] uppercase tracking-wider border-b border-[#f1f5f9] flex items-center justify-between">
                    <span>{t.workbench.selectFormat}</span>
                    <span className="text-[9px] font-normal text-slate-400">多格式引擎</span>
                  </div>

                  <div className="py-1 space-y-0.5">
                    <button
                      onClick={() => startExportWithAnimation('md')}
                      className="w-full px-3 py-2 rounded-xl text-xs text-[#334155] hover:bg-[#f6f8fc] hover:text-brand flex items-center gap-2.5 text-left transition-colors cursor-pointer group"
                    >
                      <div className="w-6 h-6 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center group-hover:bg-blue-100 transition-colors shrink-0">
                        <FileCode className="w-3.5 h-3.5" />
                      </div>
                      <div className="min-w-0">
                        <div className="font-semibold text-xs text-slate-800 group-hover:text-brand">Markdown</div>
                        <div className="text-[10px] text-slate-400 font-mono">.md 纯文本源码</div>
                      </div>
                    </button>

                    <button
                      onClick={() => startExportWithAnimation('pdf')}
                      className="w-full px-3 py-2 rounded-xl text-xs text-[#334155] hover:bg-[#f6f8fc] hover:text-brand flex items-center gap-2.5 text-left transition-colors cursor-pointer group"
                    >
                      <div className="w-6 h-6 rounded-lg bg-rose-50 text-rose-600 flex items-center justify-center group-hover:bg-rose-100 transition-colors shrink-0">
                        <FileText className="w-3.5 h-3.5" />
                      </div>
                      <div className="min-w-0">
                        <div className="font-semibold text-xs text-slate-800 group-hover:text-brand">PDF 研报文件</div>
                        <div className="text-[10px] text-slate-400 font-mono">.pdf 直接下载文件</div>
                      </div>
                    </button>

                    <button
                      onClick={() => startExportWithAnimation('html')}
                      className="w-full px-3 py-2 rounded-xl text-xs text-[#334155] hover:bg-[#f6f8fc] hover:text-brand flex items-center gap-2.5 text-left transition-colors cursor-pointer group"
                    >
                      <div className="w-6 h-6 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center group-hover:bg-emerald-100 transition-colors shrink-0">
                        <FileText className="w-3.5 h-3.5" />
                      </div>
                      <div className="min-w-0">
                        <div className="font-semibold text-xs text-slate-800 group-hover:text-brand">HTML 网页</div>
                        <div className="text-[10px] text-slate-400 font-mono">.html 独立排版单页</div>
                      </div>
                    </button>

                    <button
                      onClick={() => startExportWithAnimation('word')}
                      className="w-full px-3 py-2 rounded-xl text-xs text-[#334155] hover:bg-[#f6f8fc] hover:text-brand flex items-center gap-2.5 text-left transition-colors cursor-pointer group"
                    >
                      <div className="w-6 h-6 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center group-hover:bg-indigo-100 transition-colors shrink-0">
                        <FileSpreadsheet className="w-3.5 h-3.5" />
                      </div>
                      <div className="min-w-0">
                        <div className="font-semibold text-xs text-slate-800 group-hover:text-brand">Word 文档</div>
                        <div className="text-[10px] text-slate-400 font-mono">.docx 微软文档</div>
                      </div>
                    </button>

                    <div className="my-1 border-t border-[#f1f5f9]" />

                    <button
                      onClick={() => startExportWithAnimation('mindmap')}
                      className={`w-full px-3 py-2 rounded-xl text-xs flex items-center gap-2.5 text-left transition-colors cursor-pointer group ${
                        isPaid
                          ? 'text-[#334155] hover:bg-[#f6f8fc] hover:text-brand'
                          : 'text-[#94a3b8] hover:bg-[#f6f8fc]'
                      }`}
                      title={isPaid ? '' : t.workbench.mindmapProOnly}
                    >
                      <div className={`w-6 h-6 rounded-lg flex items-center justify-center transition-colors shrink-0 ${
                        isPaid ? 'bg-purple-50 text-purple-600 group-hover:bg-purple-100' : 'bg-slate-100 text-slate-400'
                      }`}>
                        <ImageDown className="w-3.5 h-3.5" />
                      </div>
                      <div className="flex-1 min-w-0">
                        <div className="font-semibold text-xs flex items-center gap-1.5">
                          <span>思维导图图片</span>
                          {isPaid ? (
                            <span className="px-1.5 py-px rounded bg-amber-100 text-amber-700 text-[9px] font-extrabold tracking-wide">PRO</span>
                          ) : (
                            <Crown className="w-3 h-3 text-amber-400" />
                          )}
                        </div>
                        <div className="text-[10px] text-slate-400 font-mono">.png 视觉结构图</div>
                      </div>
                    </button>
                  </div>
                </div>
              </>
            )}
          </div>

          {/* Right Info: Auto-save status & word count */}
          <div className="flex items-center gap-3 text-xs text-slate-400">
            <span className="inline-flex items-center gap-1.5">
              <span className="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
              <span className="text-[11px] text-slate-500 font-medium">草稿实时同步保存</span>
            </span>
            {wordCount > 0 && (
              <span className="text-[11px] font-mono text-slate-500 bg-slate-100 px-2 py-0.5 rounded-md">
                {wordCount} {t.workbench.wordsCount}
              </span>
            )}
          </div>
        </div>
      </div>

      {/* Simulated Multi-Step Export Progress Animation Modal */}
      {exportModal && (
        <div className="fixed inset-0 z-[140] flex items-center justify-center p-4">
          <div
            className="fixed inset-0 bg-[#0f172a]/50 backdrop-blur-xs transition-opacity animate-in fade-in duration-150"
            onClick={() => setExportModal(null)}
          />
          <div className="relative bg-white rounded-3xl shadow-2xl border border-[#e2e8f0] w-full max-w-md p-6 z-10 animate-in fade-in zoom-in-95 duration-150 overflow-hidden">
            {/* Top decorative gradient bar */}
            <div className="absolute top-0 left-0 right-0 h-1.5 bg-gradient-to-r from-brand via-purple-500 to-emerald-400" />

            {/* Header */}
            <div className="flex items-start justify-between gap-3 mb-5">
              <div className="flex items-center gap-3">
                <div className="w-10 h-10 rounded-2xl bg-brand-subtle text-brand flex items-center justify-center shadow-2xs shrink-0">
                  {exportModal.isDone ? (
                    <FileCheck className="w-5 h-5 text-emerald-600 animate-in zoom-in-50 duration-200" />
                  ) : (
                    <Sparkles className="w-5 h-5 text-brand animate-pulse" />
                  )}
                </div>
                <div>
                  <h4 className="font-extrabold text-sm text-[#1b2230] flex items-center gap-2">
                    <span>{exportModal.isDone ? '研报导出成功' : '正在导出研报'}</span>
                    <span className="px-2 py-0.5 rounded-md bg-brand-subtle text-brand text-[11px] font-mono font-bold">
                      {exportModal.formatLabel}
                    </span>
                  </h4>
                  <p className="text-[11px] text-[#64748b] mt-0.5 truncate max-w-[240px]">
                    {currentSession.title || 'tiwen-report'}
                  </p>
                </div>
              </div>

              <button
                type="button"
                onClick={() => setExportModal(null)}
                className="p-1 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors cursor-pointer"
              >
                <X className="w-4 h-4" />
              </button>
            </div>

            {/* Overall Progress Bar */}
            <div className="mb-6 space-y-1.5">
              <div className="flex justify-between text-[11px] font-bold text-slate-500">
                <span>导出进度</span>
                <span className="font-mono text-brand">{exportModal.progress}%</span>
              </div>
              <div className="w-full h-2 bg-slate-100 rounded-full overflow-hidden p-0.5">
                <div
                  className="h-full bg-gradient-to-r from-brand to-emerald-500 rounded-full transition-all duration-300 ease-out"
                  style={{ width: `${exportModal.progress}%` }}
                />
              </div>
            </div>

            {/* Step-by-step progress checklist */}
            <div className="space-y-2 bg-[#f8fafc] border border-slate-200/80 rounded-2xl p-3 mb-3.5">
              {stepsList.map(item => {
                const isFinished = exportModal.currentStep > item.step || exportModal.isDone;
                const isActive = exportModal.currentStep === item.step && !exportModal.isDone;
                const isPending = exportModal.currentStep < item.step;

                return (
                  <div
                    key={item.step}
                    className={`flex items-start gap-3 p-2.5 rounded-xl border transition-all duration-300 ${
                      isFinished
                        ? 'bg-emerald-50/70 border-emerald-200/70 text-slate-800'
                        : isActive
                        ? 'bg-white border-brand/40 shadow-xs ring-2 ring-brand/15 text-[#1b2230]'
                        : 'bg-transparent border-transparent opacity-50 text-slate-400'
                    }`}
                  >
                    <div className="mt-0.5 shrink-0">
                      {isFinished ? (
                        <div className="w-5 h-5 rounded-full bg-emerald-500 text-white flex items-center justify-center shadow-2xs animate-in zoom-in-75 duration-200">
                          <CheckCircle2 className="w-3.5 h-3.5 stroke-[3]" />
                        </div>
                      ) : isActive ? (
                        <div className="w-5 h-5 rounded-full bg-brand-subtle text-brand flex items-center justify-center shadow-2xs">
                          <Loader2 className="w-3.5 h-3.5 animate-spin text-brand" />
                        </div>
                      ) : (
                        <div className="w-5 h-5 rounded-full border border-slate-300 bg-white flex items-center justify-center">
                          <span className="w-1.5 h-1.5 rounded-full bg-slate-300" />
                        </div>
                      )}
                    </div>

                    <div className="min-w-0 flex-1">
                      <div className={`text-xs font-bold leading-tight ${
                        isFinished
                          ? 'text-emerald-900'
                          : isActive
                          ? 'text-brand font-extrabold'
                          : 'text-slate-500'
                      }`}>
                        {item.title}
                      </div>
                      <div className={`text-[10px] mt-0.5 ${
                        isFinished ? 'text-emerald-700/80' : isActive ? 'text-slate-600' : 'text-slate-400'
                      }`}>
                        {item.desc}
                      </div>
                    </div>
                  </div>
                );
              })}

              {/* Real-time processing log banner */}
              <div className="mt-1.5 flex items-center gap-2 px-3 py-2 bg-slate-900 text-slate-200 rounded-xl text-[11px] font-mono shadow-inner border border-slate-800">
                <span className={`w-2 h-2 rounded-full shrink-0 ${exportModal.isDone ? 'bg-emerald-400' : 'bg-emerald-400 animate-ping'}`} />
                <span className="truncate text-slate-300">{exportModal.detailText}</span>
              </div>
            </div>

            {/* Modal Footer Note */}
            <div className="flex items-center justify-between text-[11px] text-[#8c97af] pt-1">
              <span className="inline-flex items-center gap-1.5">
                <span className="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse" />
                <span>纯前端本地高保真渲染 · 零中转</span>
              </span>
              {exportModal.isDone ? (
                <button
                  type="button"
                  onClick={() => setExportModal(null)}
                  className="px-3 py-1 bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg font-bold text-xs shadow-xs transition-colors cursor-pointer"
                >
                  ✓ 完成
                </button>
              ) : (
                <span className="text-slate-400 font-mono">正在渲染打包…</span>
              )}
            </div>
          </div>
        </div>
      )}
    </>
  );
};

