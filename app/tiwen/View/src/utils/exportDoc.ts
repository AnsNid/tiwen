/**
 * Utility functions for 4-in-1 pure client-side document export:
 * 1. Markdown (.md)
 * 2. HTML (.html) with standalone high-craft editorial styling & code block rendering
 * 3. Word (.doc / .docx compatible XML HTML format)
 * 4. PDF (.pdf) via dedicated print stylesheet & browser print engine
 */

export function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

export function exportMarkdown(content: string, filename = 'tiwen-report.md') {
  const blob = new Blob([content], { type: 'text/markdown;charset=utf-8' });
  downloadBlob(blob, filename);
}

function escapeHtml(str: string): string {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/**
 * 转换行内 Markdown（加粗、斜体、删除线、行内代码、超链接）
 */
function parseInlineMarkdown(text: string): string {
  let result = escapeHtml(text);

  // 行内代码 `code`
  result = result.replace(/`([^`]+)`/g, '<code class="doc-inline-code">$1</code>');
  // 加粗 **bold** 或 __bold__
  result = result.replace(/\*\*(.+?)\*\*/g, '<strong class="doc-strong">$1</strong>');
  result = result.replace(/__(.+?)__/g, '<strong class="doc-strong">$1</strong>');
  // 斜体 *italic* 或 _italic_
  result = result.replace(/\*([^*]+)\*/g, '<em class="doc-em">$1</em>');
  result = result.replace(/_([^_]+)_/g, '<em class="doc-em">$1</em>');
  // 删除线 ~~del~~
  result = result.replace(/~~(.+?)~~/g, '<del class="doc-del">$1</del>');
  // 超链接 [text](url)
  result = result.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer" class="doc-link">$1</a>');

  return result;
}

/**
 * 将 Markdown 文本解析为结构化语义 HTML
 */
export function convertMarkdownToHtml(md: string): string {
  const lines = md.split('\n');
  const htmlParts: string[] = [];

  let inCode = false;
  let codeLang = '';
  let codeBuffer: string[] = [];

  let inUl = false;
  let inOl = false;
  let inQuote = false;
  let quoteBuffer: string[] = [];
  let tableBuffer: string[] = [];

  const flushUl = () => {
    if (inUl) {
      htmlParts.push('</ul>');
      inUl = false;
    }
  };

  const flushOl = () => {
    if (inOl) {
      htmlParts.push('</ol>');
      inOl = false;
    }
  };

  const flushQuote = () => {
    if (inQuote) {
      htmlParts.push(`<blockquote class="doc-quote"><div class="doc-quote-body">${quoteBuffer.map(q => parseInlineMarkdown(q)).join('<br/>')}</div></blockquote>`);
      quoteBuffer = [];
      inQuote = false;
    }
  };

  const flushTable = () => {
    if (tableBuffer.length >= 2) {
      const headerLine = tableBuffer[0];
      const alignLine = tableBuffer[1];
      const dataLines = tableBuffer.slice(2);

      const headerCells = headerLine.split('|').slice(1, -1).map(c => c.trim());
      const alignCells = alignLine.split('|').slice(1, -1).map(c => c.trim());

      const alignments = alignCells.map(c => {
        if (c.startsWith(':') && c.endsWith(':')) return 'center';
        if (c.endsWith(':')) return 'right';
        return 'left';
      });

      let tableHtml = '<div class="doc-table-wrapper" style="width: 100%; margin: 16px 0; border: 1px solid #cbd5e1; border-radius: 8px; overflow: hidden;"><table class="doc-table" style="width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 13px;"><thead><tr style="background: #f1f5f9;">';
      headerCells.forEach((cell, idx) => {
        const align = alignments[idx] || 'left';
        tableHtml += `<th style="text-align: ${align}; padding: 9px 12px; border: 1px solid #cbd5e1; font-weight: 700; color: #1e293b; word-break: break-all; overflow-wrap: anywhere; white-space: normal; vertical-align: top;">${parseInlineMarkdown(cell)}</th>`;
      });
      tableHtml += '</tr></thead><tbody>';

      dataLines.forEach((row, rowIdx) => {
        const cells = row.split('|').slice(1, -1).map(c => c.trim());
        const bg = rowIdx % 2 === 1 ? '#f8fafc' : '#ffffff';
        tableHtml += `<tr style="background: ${bg};">`;
        cells.forEach((cell, idx) => {
          const align = alignments[idx] || 'left';
          tableHtml += `<td style="text-align: ${align}; padding: 8px 12px; border: 1px solid #e2e8f0; color: #334155; word-break: break-all; overflow-wrap: anywhere; white-space: normal; vertical-align: top; line-height: 1.6;">${parseInlineMarkdown(cell)}</td>`;
        });
        tableHtml += '</tr>';
      });

      tableHtml += '</tbody></table></div>';
      htmlParts.push(tableHtml);
    }
    tableBuffer = [];
  };

  lines.forEach(rawLine => {
    const line = rawLine.trimEnd();

    // 代码块处理
    if (line.trim().startsWith('```')) {
      flushUl();
      flushOl();
      flushQuote();
      flushTable();

      if (inCode) {
        const escapedCode = escapeHtml(codeBuffer.join('\n'));
        const displayLang = (codeLang || 'text').toUpperCase();
        htmlParts.push(`
          <div class="doc-code-container">
            <div class="doc-code-header">
              <span class="doc-code-dots">
                <span class="doc-dot red"></span>
                <span class="doc-dot yellow"></span>
                <span class="doc-dot green"></span>
              </span>
              <span class="doc-code-lang">${escapeHtml(displayLang)}</span>
            </div>
            <pre class="doc-pre"><code class="doc-code">${escapedCode}</code></pre>
          </div>
        `);
        codeBuffer = [];
        inCode = false;
        codeLang = '';
      } else {
        codeLang = line.trim().replace(/^```/, '').trim();
        inCode = true;
      }
      return;
    }

    if (inCode) {
      codeBuffer.push(rawLine);
      return;
    }

    // 表格行处理
    if (line.trim().startsWith('|') && line.trim().endsWith('|')) {
      flushUl();
      flushOl();
      flushQuote();
      tableBuffer.push(line.trim());
      return;
    } else if (tableBuffer.length > 0) {
      flushTable();
    }

    // 引用块处理
    if (line.startsWith('>')) {
      flushUl();
      flushOl();
      inQuote = true;
      quoteBuffer.push(line.replace(/^>\s?/, ''));
      return;
    } else if (inQuote) {
      flushQuote();
    }

    // 无序列表
    if (/^[-*·]\s+/.test(line)) {
      flushOl();
      if (!inUl) {
        htmlParts.push('<ul class="doc-ul">');
        inUl = true;
      }
      const itemText = line.replace(/^[-*·]\s+/, '');
      htmlParts.push(`<li class="doc-li"><span class="doc-bullet">•</span><span>${parseInlineMarkdown(itemText)}</span></li>`);
      return;
    } else {
      flushUl();
    }

    // 有序列表
    if (/^\d+\.\s+/.test(line)) {
      flushUl();
      if (!inOl) {
        htmlParts.push('<ol class="doc-ol">');
        inOl = true;
      }
      const itemNum = line.match(/^(\d+)\./)?.[1] || '1';
      const itemText = line.replace(/^\d+\.\s+/, '');
      htmlParts.push(`<li class="doc-oli"><span class="doc-num">${itemNum}.</span><span>${parseInlineMarkdown(itemText)}</span></li>`);
      return;
    } else {
      flushOl();
    }

    // 标题处理
    if (line.startsWith('# ')) {
      htmlParts.push(`<h1 class="doc-h1"><span class="doc-h1-bar"></span><span>${parseInlineMarkdown(line.replace('# ', ''))}</span></h1>`);
    } else if (line.startsWith('## ')) {
      htmlParts.push(`<h2 class="doc-h2"><span class="doc-h2-bar"></span><span>${parseInlineMarkdown(line.replace('## ', ''))}</span></h2>`);
    } else if (line.startsWith('### ')) {
      htmlParts.push(`<h3 class="doc-h3">${parseInlineMarkdown(line.replace('### ', ''))}</h3>`);
    } else if (line.startsWith('#### ')) {
      htmlParts.push(`<h4 class="doc-h4">${parseInlineMarkdown(line.replace('#### ', ''))}</h4>`);
    } else if (line.trim() === '---' || line.trim() === '***') {
      htmlParts.push('<hr class="doc-hr" />');
    } else if (line.trim() !== '') {
      htmlParts.push(`<p class="doc-p">${parseInlineMarkdown(line)}</p>`);
    }
  });

  if (inCode && codeBuffer.length > 0) {
    const escapedCode = escapeHtml(codeBuffer.join('\n'));
    htmlParts.push(`
      <div class="doc-code-container">
        <div class="doc-code-header">
          <span class="doc-code-lang">${escapeHtml((codeLang || 'text').toUpperCase())}</span>
        </div>
        <pre class="doc-pre"><code class="doc-code">${escapedCode}</code></pre>
      </div>
    `);
  }
  flushUl();
  flushOl();
  flushQuote();
  flushTable();

  return htmlParts.join('\n');
}

/**
 * 通用样式表（用于 HTML 单页与 PDF 打印排版）
 */
function getDocumentStyles(): string {
  return `
    :root {
      --brand: #2f6bff;
      --brand-hover: #1e52db;
      --brand-subtle: #eff5ff;
      --text-main: #1e293b;
      --text-heading: #0f172a;
      --text-muted: #64748b;
      --border-color: #e2e8f0;
      --code-bg: #0f172a;
      --code-text: #f8fafc;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", "Noto Sans SC", sans-serif;
      line-height: 1.85;
      color: var(--text-main);
      background-color: #f8fafc;
      text-rendering: optimizeLegibility;
      -webkit-font-smoothing: antialiased;
      padding: 40px 20px;
    }

    .doc-page {
      max-width: 900px;
      margin: 0 auto;
      background: #ffffff;
      padding: 48px 56px;
      border-radius: 24px;
      box-shadow: 0 4px 24px -2px rgba(15, 23, 42, 0.06), 0 0 0 1px rgba(15, 23, 42, 0.04);
    }

    /* Top Document Header Banner */
    .doc-header {
      margin-bottom: 32px;
      padding-bottom: 24px;
      border-bottom: 1.5px solid var(--border-color);
    }

    .doc-badge-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 14px;
    }

    .doc-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      background: var(--brand-subtle);
      color: var(--brand);
      font-size: 11px;
      font-weight: 700;
      border-radius: 8px;
      letter-spacing: 0.02em;
    }

    .doc-date-meta {
      font-size: 12px;
      color: var(--text-muted);
      font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    }

    .doc-main-title {
      font-size: 28px;
      font-weight: 800;
      color: var(--text-heading);
      line-height: 1.35;
      letter-spacing: -0.02em;
    }

    /* Top Floating Toolbar (Screen only) */
    .doc-toolbar {
      position: fixed;
      top: 20px;
      right: 24px;
      display: flex;
      gap: 10px;
      z-index: 100;
    }

    .doc-tool-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: #ffffff;
      color: #334155;
      border: 1px solid var(--border-color);
      padding: 8px 14px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
      transition: all 0.2s ease;
    }

    .doc-tool-btn:hover {
      color: var(--brand);
      border-color: var(--brand);
      background: var(--brand-subtle);
      transform: translateY(-1px);
    }

    /* Typography & Hierarchy */
    .doc-content {
      font-size: 15px;
      color: var(--text-main);
    }

    .doc-p {
      margin: 14px 0;
      color: #334155;
      line-height: 1.85;
      word-break: break-word;
    }

    .doc-h1 {
      font-size: 22px;
      font-weight: 800;
      color: var(--text-heading);
      margin: 32px 0 16px 0;
      padding-bottom: 8px;
      border-bottom: 1.5px solid var(--border-color);
      display: flex;
      align-items: center;
      gap: 10px;
      letter-spacing: -0.01em;
      page-break-after: avoid;
      break-after: avoid;
    }

    .doc-h1-bar {
      width: 4px;
      height: 20px;
      background: var(--brand);
      border-radius: 4px;
      flex-shrink: 0;
    }

    .doc-h2 {
      font-size: 18px;
      font-weight: 700;
      color: var(--text-heading);
      margin: 28px 0 12px 0;
      display: flex;
      align-items: center;
      gap: 8px;
      page-break-after: avoid;
      break-after: avoid;
    }

    .doc-h2-bar {
      width: 3.5px;
      height: 16px;
      background: #6366f1;
      border-radius: 3px;
      flex-shrink: 0;
    }

    .doc-h3 {
      font-size: 15px;
      font-weight: 700;
      color: #1e293b;
      margin: 22px 0 10px 0;
      page-break-after: avoid;
      break-after: avoid;
    }

    .doc-h4 {
      font-size: 14px;
      font-weight: 700;
      color: #334155;
      margin: 18px 0 8px 0;
      page-break-after: avoid;
      break-after: avoid;
    }

    .doc-strong {
      font-weight: 700;
      color: var(--text-heading);
    }

    .doc-em {
      font-style: italic;
    }

    .doc-del {
      text-decoration: line-through;
      color: #94a3b8;
    }

    .doc-inline-code {
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 13px;
      background: #f1f5f9;
      color: #2563eb;
      padding: 2px 6px;
      border-radius: 6px;
      border: 1px solid #e2e8f0;
    }

    .doc-link {
      color: var(--brand);
      text-decoration: underline;
      text-underline-offset: 3px;
    }

    /* Lists */
    .doc-ul, .doc-ol {
      margin: 12px 0 14px 0;
      padding-left: 6px;
      list-style: none;
    }

    .doc-li, .doc-oli {
      display: flex;
      align-items: flex-start;
      gap: 8px;
      margin: 6px 0;
      line-height: 1.75;
      color: #334155;
    }

    .doc-bullet {
      color: var(--brand);
      font-weight: bold;
      font-size: 16px;
      line-height: 1.5;
      flex-shrink: 0;
    }

    .doc-num {
      color: var(--brand);
      font-family: ui-monospace, monospace;
      font-weight: 700;
      font-size: 13px;
      line-height: 1.8;
      flex-shrink: 0;
    }

    /* Blockquotes */
    .doc-quote {
      margin: 18px 0;
      padding: 14px 18px;
      background: #f8faff;
      border-left: 4px solid var(--brand);
      border-radius: 0 14px 14px 0;
      color: #475569;
      font-size: 14px;
      line-height: 1.75;
      page-break-inside: avoid;
      break-inside: avoid;
    }

    /* Tables */
    .doc-table-wrapper {
      margin: 20px 0;
      overflow-x: auto;
      border-radius: 12px;
      border: 1px solid var(--border-color);
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .doc-table {
      width: 100% !important;
      border-collapse: collapse !important;
      table-layout: fixed !important;
      font-size: 13px;
      text-align: left;
    }

    .doc-table th {
      background: #f8fafc;
      color: #1e293b;
      font-weight: 700;
      padding: 10px 12px;
      border: 1px solid var(--border-color);
      word-break: break-all !important;
      overflow-wrap: anywhere !important;
      white-space: normal !important;
      vertical-align: top;
    }

    .doc-table td {
      padding: 9px 12px;
      border: 1px solid var(--border-color);
      color: #334155;
      word-break: break-all !important;
      overflow-wrap: anywhere !important;
      white-space: normal !important;
      vertical-align: top;
      line-height: 1.6;
    }

    .doc-table tr:last-child td {
      border-bottom: none;
    }

    .doc-table tbody tr:nth-child(even) {
      background: #fafcff;
    }

    /* Code Blocks */
    .doc-code-container {
      margin: 20px 0;
      background: var(--code-bg);
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 4px 16px rgba(15, 23, 42, 0.12);
      page-break-inside: avoid;
      break-inside: avoid;
    }

    .doc-code-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 10px 16px;
      background: #1e293b;
      border-bottom: 1px solid rgba(255, 255, 255, 0.08);
    }

    .doc-code-dots {
      display: inline-flex;
      gap: 6px;
    }

    .doc-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
    }

    .doc-dot.red { background: #ef4444; }
    .doc-dot.yellow { background: #f59e0b; }
    .doc-dot.green { background: #10b981; }

    .doc-code-lang {
      font-family: ui-monospace, SFMono-Regular, monospace;
      font-size: 11px;
      font-weight: 700;
      color: #94a3b8;
      letter-spacing: 0.05em;
    }

    .doc-pre {
      padding: 16px 20px;
      overflow-x: auto;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
      font-size: 13px;
      line-height: 1.7;
      color: var(--code-text);
      tab-size: 2;
    }

    .doc-code {
      font-family: inherit;
    }

    /* Dividers */
    .doc-hr {
      border: 0;
      border-top: 1px solid var(--border-color);
      margin: 28px 0;
    }

    /* Footer */
    .doc-footer {
      margin-top: 48px;
      padding-top: 20px;
      border-top: 1px solid var(--border-color);
      display: flex;
      align-items: center;
      justify-content: space-between;
      font-size: 12px;
      color: var(--text-muted);
    }

    /* Print & PDF Rules */
    @media print {
      @page {
        size: A4 portrait;
        margin: 18mm 16mm 18mm 16mm;
      }

      body {
        background: #ffffff !important;
        padding: 0 !important;
      }

      .doc-page {
        box-shadow: none !important;
        padding: 0 !important;
        border-radius: 0 !important;
        max-width: 100% !important;
      }

      .doc-toolbar {
        display: none !important;
      }

      .doc-pre {
        white-space: pre-wrap !important;
        word-break: break-all !important;
      }

      .doc-code-container {
        box-shadow: none !important;
        border: 1px solid #334155;
      }

      .doc-h1, .doc-h2, .doc-h3, .doc-h4 {
        page-break-after: avoid !important;
        break-after: avoid !important;
      }

      .doc-quote, .doc-table-wrapper, .doc-code-container {
        page-break-inside: avoid !important;
        break-inside: avoid !important;
      }
    }
  `;
}

/**
 * 导出独立 HTML 网页（高保真排版、带工具栏与打印优化）
 */
export function exportHtml(content: string, title = 'tiwen 研报导出', filename = 'tiwen-report.html') {
  const formattedBody = convertMarkdownToHtml(content);
  const nowStr = new Date().toLocaleString();
  const wordCount = content.trim().length;

  const fullHtml = `<!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
  <title>${escapeHtml(title)} · tiwen AI 研报</title>
  <style>
    ${getDocumentStyles()}
  </style>
</head>
<body>
  <!-- Top Floating Action Tools -->
  <div class="doc-toolbar">
    <button class="doc-tool-btn" onclick="window.print()">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"></polyline><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path><rect x="6" y="14" width="12" height="8"></rect></svg>
      <span>打印 / 存为 PDF</span>
    </button>
  </div>

  <div class="doc-page">
    <!-- Header -->
    <div class="doc-header">
      <div class="doc-badge-row">
        <span class="doc-badge">⚡ AI 多模型交叉研报</span>
        <span class="doc-date-meta">${escapeHtml(nowStr)} · ${wordCount.toLocaleString()} 字</span>
      </div>
      <h1 class="doc-main-title">${escapeHtml(title)}</h1>
    </div>

    <!-- Body Content -->
    <div class="doc-content">
      ${formattedBody}
    </div>

    <!-- Footer -->
    <div class="doc-footer">
      <span>由 <strong>tiwen.ai</strong> 智能推演工作台生成</span>
      <span>端到端纯前端高保真交付</span>
    </div>
  </div>
</body>
</html>`;

  const blob = new Blob([fullHtml], { type: 'text/html;charset=utf-8' });
  downloadBlob(blob, filename);
}

/**
 * 导出兼容 Word 格式
 */
export function exportWord(content: string, title = 'tiwen 研报导出', filename = 'tiwen-report.doc') {
  const formattedBody = convertMarkdownToHtml(content);

  const wordHtml = `
    <html xmlns:o='urn:schemas-microsoft-com:office:office' xmlns:w='urn:schemas-microsoft-com:office:word' xmlns='http://www.w3.org/TR/REC-html40'>
    <head>
      <meta charset='utf-8'>
      <title>${escapeHtml(title)}</title>
      <style>
        body { font-family: Calibri, 'Microsoft YaHei', 'PingFang SC', sans-serif; line-height: 1.8; color: #1e293b; padding: 24px; }
        h1 { font-size: 24px; color: #0f172a; border-bottom: 2px solid #2f6bff; padding-bottom: 8px; margin-top: 24px; }
        h2 { font-size: 18px; color: #1e293b; margin-top: 20px; border-left: 4px solid #2f6bff; padding-left: 8px; }
        h3 { font-size: 15px; color: #334155; margin-top: 14px; }
        p { margin: 10px 0; color: #334155; }
        blockquote { border-left: 4px solid #2f6bff; background: #f8faff; padding: 10px 14px; margin: 12px 0; color: #475569; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; }
        th, td { border: 1px solid #cbd5e1; padding: 8px 12px; text-align: left; }
        th { background: #f1f5f9; font-weight: bold; }
        pre { background: #0f172a; color: #f8fafc; padding: 14px; border-radius: 8px; font-family: monospace; font-size: 12px; }
      </style>
    </head>
    <body>
      <h1 style="color: #2f6bff; border-bottom: 2px solid #2f6bff; padding-bottom: 6px;">${escapeHtml(title)}</h1>
      ${formattedBody}
    </body>
    </html>
  `;
  const blob = new Blob(['\ufeff' + wordHtml], { type: 'application/msword' });
  downloadBlob(blob, filename);
}

/**
 * 纯客户端标准 PDF 1.4 二进制流生成器 (无外部 npm 依赖，原生标准格式)
 */
function buildPdfFromJpegs(jpegBuffers: Uint8Array[], widthPt = 595.28, heightPt = 841.89): Blob {
  const encoder = new TextEncoder();
  const chunks: Uint8Array[] = [];
  let currentOffset = 0;

  const appendString = (str: string) => {
    const bytes = encoder.encode(str);
    chunks.push(bytes);
    currentOffset += bytes.length;
  };

  const appendBytes = (bytes: Uint8Array) => {
    chunks.push(bytes);
    currentOffset += bytes.length;
  };

  // PDF 1.4 Header with binary comment
  appendString('%PDF-1.4\n%\xE2\xE3\xCF\xD3\n');

  const totalPages = jpegBuffers.length;
  const offsets: number[] = [];
  const totalObjects = 2 + totalPages * 3;

  // Object 1: Catalog
  offsets[1] = currentOffset;
  appendString(`1 0 obj\n<<\n  /Type /Catalog\n  /Pages 2 0 R\n>>\nendobj\n`);

  // Object 2: Pages tree
  offsets[2] = currentOffset;
  const kids = Array.from({ length: totalPages }, (_, i) => `${3 + i * 3} 0 R`).join(' ');
  appendString(`2 0 obj\n<<\n  /Type /Pages\n  /Kids [${kids}]\n  /Count ${totalPages}\n>>\nendobj\n`);

  // Objects per page
  for (let i = 0; i < totalPages; i++) {
    const pageObjId = 3 + i * 3;
    const imageObjId = 4 + i * 3;
    const contentsObjId = 5 + i * 3;
    const jpeg = jpegBuffers[i];

    // Page Object
    offsets[pageObjId] = currentOffset;
    appendString(`${pageObjId} 0 obj\n<<\n  /Type /Page\n  /Parent 2 0 R\n  /MediaBox [0 0 ${widthPt.toFixed(2)} ${heightPt.toFixed(2)}]\n  /Resources <<\n    /ProcSet [/PDF /ImageC]\n    /XObject << /Im1 ${imageObjId} 0 R >>\n  >>\n  /Contents ${contentsObjId} 0 R\n>>\nendobj\n`);

    // Image XObject (JPEG DCTDecode)
    offsets[imageObjId] = currentOffset;
    appendString(`${imageObjId} 0 obj\n<<\n  /Type /XObject\n  /Subtype /Image\n  /Width 1240\n  /Height 1754\n  /ColorSpace /DeviceRGB\n  /BitsPerComponent 8\n  /Filter /DCTDecode\n  /Length ${jpeg.length}\n>>\nstream\r\n`);
    appendBytes(jpeg);
    appendString(`\r\nendstream\nendobj\n`);

    // Contents Stream (drawing image on page)
    const contentStr = `q\n${widthPt.toFixed(2)} 0 0 ${heightPt.toFixed(2)} 0 0 cm\n/Im1 Do\nQ\n`;
    const contentBytes = encoder.encode(contentStr);
    offsets[contentsObjId] = currentOffset;
    appendString(`${contentsObjId} 0 obj\n<<\n  /Length ${contentBytes.length}\n>>\nstream\r\n${contentStr}endstream\nendobj\n`);
  }

  // Cross-reference table (xref)
  const xrefOffset = currentOffset;
  appendString(`xref\n0 ${totalObjects + 1}\n`);
  appendString(`0000000000 65535 f \r\n`);
  for (let i = 1; i <= totalObjects; i++) {
    const off = offsets[i] || 0;
    const offStr = off.toString().padStart(10, '0');
    appendString(`${offStr} 00000 n \r\n`);
  }

  // Trailer
  appendString(`trailer\n<<\n  /Size ${totalObjects + 1}\n  /Root 1 0 R\n>>\nstartxref\n${xrefOffset}\n%%EOF\n`);

  return new Blob(chunks as any, { type: 'application/pdf' });
}

/**
 * 将 Canvas 异步转为高品质 JPEG 二进制字节
 */
function canvasToJpeg(canvas: HTMLCanvasElement, quality = 0.92): Promise<Uint8Array> {
  return new Promise<Uint8Array>((resolve, reject) => {
    canvas.toBlob(blob => {
      if (!blob) {
        reject(new Error('Canvas 栅格化失败'));
        return;
      }
      const reader = new FileReader();
      reader.onload = () => {
        resolve(new Uint8Array(reader.result as ArrayBuffer));
      };
      reader.onerror = () => reject(new Error('FileReader 读取二进制失败'));
      reader.readAsArrayBuffer(blob);
    }, 'image/jpeg', quality);
  });
}

/**
 * 矩形圆角绘制辅助方法 (跨浏览器兼容)
 */
function drawRoundedRect(ctx: CanvasRenderingContext2D, x: number, y: number, w: number, h: number, r: number) {
  if (typeof (ctx as any).roundRect === 'function') {
    ctx.beginPath();
    (ctx as any).roundRect(x, y, w, h, r);
  } else {
    ctx.beginPath();
    ctx.moveTo(x + r, y);
    ctx.lineTo(x + w - r, y);
    ctx.quadraticCurveTo(x + w, y, x + w, y + r);
    ctx.lineTo(x + w, y + h - r);
    ctx.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    ctx.lineTo(x + r, y + h);
    ctx.quadraticCurveTo(x, y + h, x, y + h - r);
    ctx.lineTo(x, y + r);
    ctx.quadraticCurveTo(x, y, x + r, y);
    ctx.closePath();
  }
}

/**
 * 净化 Markdown 标记与 HTML 标签，返回干净文本
 */
function cleanMarkdownText(str: string): string {
  if (!str) return '';
  return str
    .replace(/<[^>]+>/g, '')
    .replace(/\*\*([^*]+)\*\*/g, '$1')
    .replace(/\*([^*]+)\*/g, '$1')
    .replace(/__([^_]+)__/g, '$1')
    .replace(/_([^_]+)_/g, '$1')
    .replace(/~~([^~]+)~~/g, '$1')
    .replace(/`([^`]+)`/g, '$1')
    .replace(/\[([^\]]+)\]\([^)]+\)/g, '$1')
    .trim();
}

/**
 * 单词与中文字符感知换行计算（绝对防止文本冲出单元格或画幅边界）
 */
function wrapText(ctx: CanvasRenderingContext2D, text: string, maxWidth: number): string[] {
  if (!text) return [''];
  const lines: string[] = [];
  const paragraphs = text.split('\n');

  for (const para of paragraphs) {
    if (!para.trim()) {
      lines.push('');
      continue;
    }

    const tokens: string[] = [];
    let curWord = '';
    for (const ch of Array.from(para)) {
      if (/^[a-zA-Z0-9_#+.\-]$/.test(ch)) {
        curWord += ch;
      } else {
        if (curWord) {
          tokens.push(curWord);
          curWord = '';
        }
        tokens.push(ch);
      }
    }
    if (curWord) tokens.push(curWord);

    let curLine = '';
    for (const tok of tokens) {
      const testLine = curLine ? (curLine + tok) : tok;
      const testWidth = ctx.measureText(testLine).width;
      if (testWidth > maxWidth && curLine.length > 0) {
        lines.push(curLine);
        if (ctx.measureText(tok).width > maxWidth) {
          let sub = '';
          for (const c of Array.from(tok)) {
            if (ctx.measureText(sub + c).width > maxWidth && sub.length > 0) {
              lines.push(sub);
              sub = c;
            } else {
              sub += c;
            }
          }
          curLine = sub;
        } else {
          curLine = tok;
        }
      } else {
        curLine = testLine;
      }
    }
    if (curLine.length > 0) {
      lines.push(curLine);
    }
  }

  return lines.length > 0 ? lines : [''];
}

interface BlockHeading { type: 'heading'; level: number; text: string }
interface BlockParagraph { type: 'paragraph'; text: string }
interface BlockList { type: 'list'; ordered: boolean; items: string[] }
interface BlockQuote { type: 'quote'; text: string }
interface BlockCode { type: 'code'; lang: string; code: string }
interface BlockTable { type: 'table'; headers: string[]; rows: string[][] }
interface BlockHr { type: 'hr' }

type DocBlock = BlockHeading | BlockParagraph | BlockList | BlockQuote | BlockCode | BlockTable | BlockHr;

/**
 * 解析 Markdown 为语义块
 */
function parseMarkdownToBlocks(md: string): DocBlock[] {
  const lines = md.split('\n');
  const blocks: DocBlock[] = [];

  let inCode = false;
  let codeLang = '';
  let codeLines: string[] = [];

  let inQuote = false;
  let quoteLines: string[] = [];

  let tableLines: string[] = [];
  let currentList: { ordered: boolean; items: string[] } | null = null;

  const flushList = () => {
    if (currentList) {
      blocks.push({ type: 'list', ordered: currentList.ordered, items: currentList.items });
      currentList = null;
    }
  };

  const flushQuote = () => {
    if (inQuote) {
      blocks.push({ type: 'quote', text: quoteLines.join('\n') });
      quoteLines = [];
      inQuote = false;
    }
  };

  const flushTable = () => {
    if (tableLines.length >= 2) {
      const headerLine = tableLines[0];
      const dataLines = tableLines.slice(2);
      const headers = headerLine.split('|').slice(1, -1).map(c => c.trim());
      const rows = dataLines.map(line => line.split('|').slice(1, -1).map(c => c.trim()));
      blocks.push({ type: 'table', headers, rows });
    }
    tableLines = [];
  };

  for (let i = 0; i < lines.length; i++) {
    const rawLine = lines[i];
    const line = rawLine.trimEnd();

    // 代码块
    if (line.trim().startsWith('```')) {
      flushList();
      flushQuote();
      flushTable();
      if (inCode) {
        blocks.push({ type: 'code', lang: codeLang || 'text', code: codeLines.join('\n') });
        codeLines = [];
        inCode = false;
        codeLang = '';
      } else {
        inCode = true;
        codeLang = line.trim().replace(/^```/, '').trim();
      }
      continue;
    }

    if (inCode) {
      codeLines.push(rawLine);
      continue;
    }

    // 表格
    if (line.trim().startsWith('|') && line.trim().endsWith('|')) {
      flushList();
      flushQuote();
      tableLines.push(line.trim());
      continue;
    } else if (tableLines.length > 0) {
      flushTable();
    }

    // 引用
    if (line.startsWith('>')) {
      flushList();
      inQuote = true;
      quoteLines.push(line.replace(/^>\s?/, ''));
      continue;
    } else if (inQuote) {
      flushQuote();
    }

    // 无序列表
    if (/^[-*·]\s+/.test(line)) {
      if (!currentList || currentList.ordered) {
        flushList();
        currentList = { ordered: false, items: [] };
      }
      currentList.items.push(line.replace(/^[-*·]\s+/, ''));
      continue;
    }

    // 有序列表
    if (/^\d+\.\s+/.test(line)) {
      if (!currentList || !currentList.ordered) {
        flushList();
        currentList = { ordered: true, items: [] };
      }
      currentList.items.push(line.replace(/^\d+\.\s+/, ''));
      continue;
    }

    flushList();

    // 标题与常规段落
    if (line.startsWith('# ')) {
      blocks.push({ type: 'heading', level: 1, text: line.replace('# ', '') });
    } else if (line.startsWith('## ')) {
      blocks.push({ type: 'heading', level: 2, text: line.replace('## ', '') });
    } else if (line.startsWith('### ')) {
      blocks.push({ type: 'heading', level: 3, text: line.replace('### ', '') });
    } else if (line.startsWith('#### ')) {
      blocks.push({ type: 'heading', level: 4, text: line.replace('#### ', '') });
    } else if (line.trim() === '---' || line.trim() === '***') {
      blocks.push({ type: 'hr' });
    } else if (line.trim() !== '') {
      blocks.push({ type: 'paragraph', text: line });
    }
  }

  flushList();
  flushQuote();
  flushTable();
  if (inCode && codeLines.length > 0) {
    blocks.push({ type: 'code', lang: codeLang || 'text', code: codeLines.join('\n') });
  }

  return blocks;
}

/**
 * 智能分配表格列宽（自适应内容密度与字符长度，杜绝溢出）
 */
function computeTableColWidths(headers: string[], rows: string[][], totalWidth: number): number[] {
  const colCount = headers.length;
  if (colCount <= 1) return [totalWidth];

  const colLengths: number[] = new Array(colCount).fill(0);
  headers.forEach((h, i) => {
    colLengths[i] += cleanMarkdownText(h).length * 1.5;
  });
  rows.forEach(r => {
    r.forEach((c, i) => {
      if (i < colCount) {
        colLengths[i] += cleanMarkdownText(c).length;
      }
    });
  });

  const totalChars = colLengths.reduce((a, b) => a + b, 0) || 1;
  const minWidth = Math.max(120, Math.floor(totalWidth / (colCount * 2)));

  let widths = colLengths.map(chars => {
    const ratio = chars / totalChars;
    return Math.max(minWidth, Math.round(totalWidth * ratio));
  });

  const sum = widths.reduce((a, b) => a + b, 0);
  const factor = totalWidth / sum;
  widths = widths.map(w => Math.round(w * factor));

  const currentSum = widths.slice(0, -1).reduce((a, b) => a + b, 0);
  widths[widths.length - 1] = totalWidth - currentSum;

  return widths;
}

/**
 * 导出高质感出版级 PDF 研报
 * (纯前端 2D 离屏 Canvas + 标准 PDF 1.4 二进制流，100% 无 Canvas Taint，零外部依赖，直接文件下载)
 */
export async function exportPdf(content: string, title = 'tiwen 研报导出', filename?: string) {
  const safeFilename = (filename || `${title}.pdf`).replace(/[\\/:*?"<>|]/g, '_');
  const nowStr = new Date().toLocaleString();
  const wordCount = content.trim().length;

  const PAGE_W = 1240;
  const PAGE_H = 1754;
  const MARGIN_LEFT = 90;
  const MARGIN_RIGHT = 90;
  const CONTENT_W = PAGE_W - MARGIN_LEFT - MARGIN_RIGHT; // 1060px
  const MARGIN_TOP_COVER = 70;
  const MARGIN_TOP_OTHER = 105;
  const MARGIN_BOTTOM = 1650;

  const pageCanvases: HTMLCanvasElement[] = [];
  let currentPageIndex = -1;
  let currentCanvas: HTMLCanvasElement = document.createElement('canvas');
  let ctx: CanvasRenderingContext2D = currentCanvas.getContext('2d')!;
  let currentY = 0;

  const createNewPage = () => {
    currentPageIndex++;
    currentCanvas = document.createElement('canvas');
    currentCanvas.width = PAGE_W;
    currentCanvas.height = PAGE_H;
    ctx = currentCanvas.getContext('2d')!;

    // 纯白背景
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, PAGE_W, PAGE_H);

    if (currentPageIndex === 0) {
      // 封面顶部雅致渐变饰条
      const grad = ctx.createLinearGradient(0, 0, PAGE_W, 0);
      grad.addColorStop(0, '#2563eb');
      grad.addColorStop(0.5, '#4f46e5');
      grad.addColorStop(1, '#7c3aed');
      ctx.fillStyle = grad;
      ctx.fillRect(0, 0, PAGE_W, 6);
      currentY = MARGIN_TOP_COVER;
    } else {
      // 次页企业双栏页眉
      ctx.save();
      ctx.font = 'bold 12px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
      ctx.fillStyle = '#2563eb';
      ctx.fillText('TIWEN.AI · 智能研报推演工作台', MARGIN_LEFT, 52);

      ctx.font = '12px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
      ctx.fillStyle = '#64748b';
      ctx.textAlign = 'right';
      const displayTitle = title.length > 36 ? title.slice(0, 34) + '...' : title;
      ctx.fillText(displayTitle, PAGE_W - MARGIN_RIGHT, 52);

      ctx.strokeStyle = '#e2e8f0';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(MARGIN_LEFT, 68);
      ctx.lineTo(PAGE_W - MARGIN_RIGHT, 68);
      ctx.stroke();
      ctx.restore();

      currentY = MARGIN_TOP_OTHER;
    }

    pageCanvases.push(currentCanvas);
  };

  const ensureSpace = (needed: number) => {
    if (currentY + needed > MARGIN_BOTTOM) {
      createNewPage();
    }
  };

  // 1. 初始化第一页
  createNewPage();

  // 2. 绘制封面 Header
  const badgeText = '⚡ AI 多模型交叉推演研报';
  ctx.font = 'bold 12px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
  const badgeW = ctx.measureText(badgeText).width + 20;
  const badgeH = 26;

  ctx.fillStyle = '#eff6ff';
  drawRoundedRect(ctx, MARGIN_LEFT, currentY, badgeW, badgeH, 6);
  ctx.fill();
  ctx.strokeStyle = '#bfdbfe';
  ctx.lineWidth = 1;
  drawRoundedRect(ctx, MARGIN_LEFT, currentY, badgeW, badgeH, 6);
  ctx.stroke();

  ctx.fillStyle = '#1d4ed8';
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.fillText(badgeText, MARGIN_LEFT + 10, currentY + badgeH / 2);

  ctx.font = '12px ui-monospace, SFMono-Regular, Menlo, monospace';
  ctx.fillStyle = '#64748b';
  ctx.textAlign = 'right';
  ctx.fillText(`${nowStr} · ${wordCount.toLocaleString()} 字`, PAGE_W - MARGIN_RIGHT, currentY + badgeH / 2);

  currentY += badgeH + 18;

  // 标题
  ctx.font = 'bold 28px -apple-system, BlinkMacSystemFont, "PingFang SC", "Segoe UI", Roboto, sans-serif';
  ctx.fillStyle = '#0f172a';
  ctx.textAlign = 'left';
  ctx.textBaseline = 'top';
  const titleLines = wrapText(ctx, title, CONTENT_W);
  for (const tLine of titleLines) {
    ctx.fillText(tLine, MARGIN_LEFT, currentY);
    currentY += 38;
  }
  currentY += 6;

  // 凭证卡片
  const cardH = 34;
  ctx.fillStyle = '#f8fafc';
  drawRoundedRect(ctx, MARGIN_LEFT, currentY, CONTENT_W, cardH, 6);
  ctx.fill();
  ctx.strokeStyle = '#e2e8f0';
  ctx.lineWidth = 1;
  drawRoundedRect(ctx, MARGIN_LEFT, currentY, CONTENT_W, cardH, 6);
  ctx.stroke();

  ctx.font = '12px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
  ctx.fillStyle = '#475569';
  ctx.textAlign = 'left';
  ctx.textBaseline = 'middle';
  ctx.fillText('由 tiwen.ai 智能推演工作台生成 · 结构化决策交付件', MARGIN_LEFT + 14, currentY + cardH / 2);

  ctx.font = 'bold 11px ui-monospace, SFMono-Regular, Menlo, monospace';
  ctx.fillStyle = '#2563eb';
  ctx.textAlign = 'right';
  ctx.fillText('CONFIDENTIAL', PAGE_W - MARGIN_RIGHT - 14, currentY + cardH / 2);

  currentY += cardH + 18;

  // 分割线
  ctx.strokeStyle = '#e2e8f0';
  ctx.lineWidth = 1;
  ctx.beginPath();
  ctx.moveTo(MARGIN_LEFT, currentY);
  ctx.lineTo(PAGE_W - MARGIN_RIGHT, currentY);
  ctx.stroke();

  currentY += 24;

  // 3. 解析与逐块排版渲染
  const blocks = parseMarkdownToBlocks(content);

  for (const block of blocks) {
    if (block.type === 'heading') {
      const isH1 = block.level === 1;
      const isH2 = block.level === 2;
      const isH3 = block.level === 3;

      const fontSize = isH1 ? 22 : isH2 ? 18 : isH3 ? 16 : 14.5;
      const topSpace = isH1 ? 26 : isH2 ? 22 : isH3 ? 18 : 14;
      const bottomSpace = isH1 ? 14 : isH2 ? 10 : isH3 ? 8 : 6;

      ctx.font = `bold ${fontSize}px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif`;
      const clean = cleanMarkdownText(block.text);
      const lines = wrapText(ctx, clean, CONTENT_W - 20);
      const neededH = topSpace + lines.length * (fontSize + 8) + bottomSpace;

      if (currentY + neededH > MARGIN_BOTTOM - 80) {
        createNewPage();
      }

      currentY += topSpace;

      if (isH1 || isH2) {
        ctx.fillStyle = isH1 ? '#2563eb' : '#3b82f6';
        ctx.fillRect(MARGIN_LEFT, currentY + 2, isH1 ? 4 : 3.5, fontSize + 2);
      }

      ctx.fillStyle = isH1 ? '#0f172a' : isH2 ? '#0f172a' : isH3 ? '#1e293b' : '#334155';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'top';
      const textX = (isH1 || isH2) ? MARGIN_LEFT + 12 : MARGIN_LEFT;

      for (const line of lines) {
        ctx.fillText(line, textX, currentY);
        currentY += fontSize + 8;
      }

      if (isH1) {
        ctx.strokeStyle = '#e2e8f0';
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(MARGIN_LEFT, currentY + 4);
        ctx.lineTo(PAGE_W - MARGIN_RIGHT, currentY + 4);
        ctx.stroke();
        currentY += 8;
      }

      currentY += bottomSpace;
    } else if (block.type === 'paragraph') {
      ctx.font = '14px -apple-system, BlinkMacSystemFont, "PingFang SC", "Segoe UI", Roboto, sans-serif';
      ctx.fillStyle = '#334155';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'top';

      const clean = cleanMarkdownText(block.text);
      const lines = wrapText(ctx, clean, CONTENT_W);
      const lineH = 24;

      for (const line of lines) {
        ensureSpace(lineH);
        ctx.fillText(line, MARGIN_LEFT, currentY);
        currentY += lineH;
      }
      currentY += 10;
    } else if (block.type === 'list') {
      const lineH = 23;
      const textIndent = block.ordered ? 28 : 22;
      const usableW = CONTENT_W - textIndent;

      ctx.font = '14px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'top';

      block.items.forEach((item, itemIdx) => {
        const clean = cleanMarkdownText(item);
        const lines = wrapText(ctx, clean, usableW);
        const itemH = lines.length * lineH;
        ensureSpace(itemH + 4);

        if (block.ordered) {
          ctx.fillStyle = '#2563eb';
          ctx.font = 'bold 13.5px ui-monospace, SFMono-Regular, Menlo, monospace';
          ctx.fillText(`${itemIdx + 1}.`, MARGIN_LEFT, currentY);
        } else {
          ctx.fillStyle = '#2563eb';
          ctx.beginPath();
          ctx.arc(MARGIN_LEFT + 6, currentY + 11, 2.5, 0, Math.PI * 2);
          ctx.fill();
        }

        ctx.fillStyle = '#334155';
        ctx.font = '14px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
        for (let l = 0; l < lines.length; l++) {
          ctx.fillText(lines[l], MARGIN_LEFT + textIndent, currentY + l * lineH);
        }
        currentY += itemH + 6;
      });
      currentY += 6;
    } else if (block.type === 'quote') {
      ctx.font = '13.5px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
      const clean = cleanMarkdownText(block.text);
      const lines = wrapText(ctx, clean, CONTENT_W - 36);
      const lineH = 24;
      const padV = 12;
      const quoteH = padV * 2 + lines.length * lineH;

      ensureSpace(quoteH);

      ctx.fillStyle = '#f8faff';
      ctx.fillRect(MARGIN_LEFT, currentY, CONTENT_W, quoteH);
      ctx.fillStyle = '#2563eb';
      ctx.fillRect(MARGIN_LEFT, currentY, 4, quoteH);

      ctx.fillStyle = '#475569';
      ctx.textAlign = 'left';
      ctx.textBaseline = 'top';
      for (let l = 0; l < lines.length; l++) {
        ctx.fillText(lines[l], MARGIN_LEFT + 18, currentY + padV + l * lineH);
      }
      currentY += quoteH + 14;
    } else if (block.type === 'code') {
      const codeLines = block.code.split('\n');
      const codeFont = '12px ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace';
      ctx.font = codeFont;

      const wrappedCodeLines: string[] = [];
      for (const cLine of codeLines) {
        const wrapped = wrapText(ctx, cLine, CONTENT_W - 32);
        wrappedCodeLines.push(...wrapped);
      }

      const headerH = 28;
      const lineH = 19;
      const padV = 12;
      const bodyH = padV * 2 + wrappedCodeLines.length * lineH;
      const totalH = headerH + bodyH;

      if (totalH < 450 && currentY + totalH > MARGIN_BOTTOM) {
        createNewPage();
      }

      ensureSpace(headerH + 40);
      ctx.fillStyle = '#1e293b';
      drawRoundedRect(ctx, MARGIN_LEFT, currentY, CONTENT_W, headerH, 6);
      ctx.fill();

      // macOS 3 窗体点
      const dotY = currentY + headerH / 2;
      const dots = [
        { color: '#ef4444', x: MARGIN_LEFT + 14 },
        { color: '#f59e0b', x: MARGIN_LEFT + 26 },
        { color: '#10b981', x: MARGIN_LEFT + 38 },
      ];
      for (const dot of dots) {
        ctx.beginPath();
        ctx.arc(dot.x, dotY, 4, 0, Math.PI * 2);
        ctx.fillStyle = dot.color;
        ctx.fill();
      }

      ctx.font = 'bold 10px ui-monospace, SFMono-Regular, Menlo, monospace';
      ctx.fillStyle = '#94a3b8';
      ctx.textAlign = 'right';
      ctx.textBaseline = 'middle';
      ctx.fillText((block.lang || 'CODE').toUpperCase(), PAGE_W - MARGIN_RIGHT - 14, dotY);

      currentY += headerH;

      if (currentY + bodyH <= MARGIN_BOTTOM) {
        ctx.fillStyle = '#0f172a';
        ctx.fillRect(MARGIN_LEFT, currentY, CONTENT_W, bodyH);
        ctx.strokeStyle = '#334155';
        ctx.lineWidth = 1;
        ctx.strokeRect(MARGIN_LEFT, currentY, CONTENT_W, bodyH);

        ctx.font = codeFont;
        ctx.fillStyle = '#f8fafc';
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';

        for (let i = 0; i < wrappedCodeLines.length; i++) {
          ctx.fillText(wrappedCodeLines[i], MARGIN_LEFT + 16, currentY + padV + i * lineH);
        }
        currentY += bodyH + 16;
      } else {
        ctx.fillStyle = '#0f172a';
        ctx.font = codeFont;
        ctx.textAlign = 'left';
        ctx.textBaseline = 'top';

        for (let i = 0; i < wrappedCodeLines.length; i++) {
          if (currentY + lineH > MARGIN_BOTTOM) {
            createNewPage();
          }
          ctx.fillStyle = '#0f172a';
          ctx.fillRect(MARGIN_LEFT, currentY, CONTENT_W, lineH);
          ctx.fillStyle = '#f8fafc';
          ctx.fillText(wrappedCodeLines[i], MARGIN_LEFT + 16, currentY);
          currentY += lineH;
        }
        currentY += 16;
      }
    } else if (block.type === 'table') {
      const colWidths = computeTableColWidths(block.headers, block.rows, CONTENT_W);
      const colCount = colWidths.length;

      const drawHeaderRow = () => {
        ctx.font = 'bold 13px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
        const headerLinesPerCol: string[][] = [];
        for (let c = 0; c < colCount; c++) {
          const hText = cleanMarkdownText(block.headers[c] || '');
          headerLinesPerCol.push(wrapText(ctx, hText, colWidths[c] - 20));
        }
        const maxLines = Math.max(1, ...headerLinesPerCol.map(l => l.length));
        const hRowH = Math.max(36, maxLines * 20 + 16);

        ensureSpace(hRowH);

        ctx.fillStyle = '#f1f5f9';
        ctx.fillRect(MARGIN_LEFT, currentY, CONTENT_W, hRowH);

        let curX = MARGIN_LEFT;
        for (let c = 0; c < colCount; c++) {
          const w = colWidths[c];
          ctx.strokeStyle = '#cbd5e1';
          ctx.lineWidth = 1;
          ctx.strokeRect(curX, currentY, w, hRowH);

          ctx.fillStyle = '#0f172a';
          ctx.textAlign = 'left';
          ctx.textBaseline = 'top';
          const lines = headerLinesPerCol[c];
          for (let l = 0; l < lines.length; l++) {
            ctx.fillText(lines[l], curX + 10, currentY + 9 + l * 20);
          }
          curX += w;
        }
        currentY += hRowH;
      };

      drawHeaderRow();

      // 数据行
      block.rows.forEach((row, rowIdx) => {
        ctx.font = '12.5px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
        const linesPerCol: string[][] = [];
        for (let c = 0; c < colCount; c++) {
          const raw = row[c] || '';
          const cellText = cleanMarkdownText(raw.replace(/<br\s*\/?>/gi, '\n'));
          linesPerCol.push(wrapText(ctx, cellText, colWidths[c] - 20));
        }
        const maxLines = Math.max(1, ...linesPerCol.map(l => l.length));
        const rowH = Math.max(34, maxLines * 19 + 14);

        if (currentY + rowH > MARGIN_BOTTOM) {
          createNewPage();
          drawHeaderRow();
          ctx.font = '12.5px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
        }

        ctx.fillStyle = rowIdx % 2 === 1 ? '#f8fafc' : '#ffffff';
        ctx.fillRect(MARGIN_LEFT, currentY, CONTENT_W, rowH);

        let curX = MARGIN_LEFT;
        for (let c = 0; c < colCount; c++) {
          const w = colWidths[c];
          ctx.strokeStyle = '#e2e8f0';
          ctx.lineWidth = 1;
          ctx.strokeRect(curX, currentY, w, rowH);

          ctx.fillStyle = '#334155';
          ctx.textAlign = 'left';
          ctx.textBaseline = 'top';
          const lines = linesPerCol[c];
          for (let l = 0; l < lines.length; l++) {
            ctx.fillText(lines[l], curX + 10, currentY + 8 + l * 19);
          }
          curX += w;
        }
        currentY += rowH;
      });

      currentY += 16;
    } else if (block.type === 'hr') {
      ensureSpace(24);
      currentY += 10;
      ctx.strokeStyle = '#e2e8f0';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(MARGIN_LEFT, currentY);
      ctx.lineTo(PAGE_W - MARGIN_RIGHT, currentY);
      ctx.stroke();
      currentY += 14;
    }
  }

  // 4. 统一绘制所有页面的页脚与胶囊页码
  const totalPages = pageCanvases.length;
  pageCanvases.forEach((pCanvas, idx) => {
    const pCtx = pCanvas.getContext('2d')!;
    pCtx.save();

    pCtx.strokeStyle = '#e2e8f0';
    pCtx.lineWidth = 1;
    pCtx.beginPath();
    pCtx.moveTo(MARGIN_LEFT, PAGE_H - 68);
    pCtx.lineTo(PAGE_W - MARGIN_RIGHT, PAGE_H - 68);
    pCtx.stroke();

    pCtx.font = '12px -apple-system, BlinkMacSystemFont, "PingFang SC", sans-serif';
    pCtx.fillStyle = '#94a3b8';
    pCtx.textAlign = 'left';
    pCtx.textBaseline = 'middle';
    pCtx.fillText('由 tiwen.ai 智能推演工作台生成 · 商业决策保密研报', MARGIN_LEFT, PAGE_H - 44);

    const pageStr = `PAGE ${idx + 1} / ${totalPages}`;
    pCtx.font = 'bold 11px ui-monospace, SFMono-Regular, Menlo, monospace';
    const pillW = pCtx.measureText(pageStr).width + 18;
    const pillH = 22;
    const pillX = PAGE_W - MARGIN_RIGHT - pillW;
    const pillY = PAGE_H - 55;

    pCtx.fillStyle = '#f1f5f9';
    drawRoundedRect(pCtx, pillX, pillY, pillW, pillH, 11);
    pCtx.fill();

    pCtx.fillStyle = '#475569';
    pCtx.textAlign = 'center';
    pCtx.textBaseline = 'middle';
    pCtx.fillText(pageStr, pillX + pillW / 2, pillY + pillH / 2);

    pCtx.restore();
  });

  // 5. 将 Canvas 数组转换为 JPEG 二进制
  const jpegs = await Promise.all(pageCanvases.map(c => canvasToJpeg(c, 0.94)));

  // 6. 打包为合法标准 PDF 1.4 文件流
  const pdfBlob = buildPdfFromJpegs(jpegs, 595.28, 841.89);

  // 7. 触发下载
  downloadBlob(pdfBlob, safeFilename.endsWith('.pdf') ? safeFilename : `${safeFilename}.pdf`);
}




