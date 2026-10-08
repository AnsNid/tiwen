import React, { useState } from 'react';
import { Copy, Check, Terminal, FileCode } from 'lucide-react';

interface MarkdownRendererProps {
  content: string;
  className?: string;
  /** 正文字号档位:xs 为工作台默认(向后兼容),sm/base 用于前台阅读页,标题按比例放大 */
  size?: 'xs' | 'sm' | 'base';
}

/** 各档位的正文与标题字号映射 */
const SIZE_SCALES: Record<NonNullable<MarkdownRendererProps['size']>, { wrap: string; h1: string; h2: string; h3: string }> = {
  xs: { wrap: 'text-xs', h1: 'text-base', h2: 'text-sm', h3: 'text-xs' },
  sm: { wrap: 'text-[13px]', h1: 'text-lg', h2: 'text-[15px]', h3: 'text-[13px]' },
  base: { wrap: 'text-[15px] leading-[1.9]', h1: 'text-2xl', h2: 'text-lg', h3: 'text-[15px]' },
};

export const MarkdownRenderer: React.FC<MarkdownRendererProps> = ({ content, className = '', size = 'xs' }) => {
  const scale = SIZE_SCALES[size];
  const [copiedIndex, setCopiedIndex] = useState<number | null>(null);

  const handleCopyCode = (codeText: string, index: number) => {
    navigator.clipboard.writeText(codeText);
    setCopiedIndex(index);
    setTimeout(() => setCopiedIndex(null), 2000);
  };

  // Basic markdown parser dividing into blocks
  const parseBlocks = (raw: string) => {
    const lines = raw.split('\n');
    const blocks: Array<{ type: string; content: any; lang?: string }> = [];
    let inCode = false;
    let codeLang = '';
    let codeBuffer: string[] = [];
    let tableBuffer: string[] = [];

    lines.forEach((line) => {
      // Code block toggle
      if (line.trim().startsWith('```')) {
        if (inCode) {
          blocks.push({
            type: 'code',
            lang: codeLang || 'text',
            content: codeBuffer.join('\n')
          });
          codeBuffer = [];
          inCode = false;
        } else {
          codeLang = line.trim().replace('```', '').trim();
          inCode = true;
        }
        return;
      }

      if (inCode) {
        codeBuffer.push(line);
        return;
      }

      // Table row
      if (line.trim().startsWith('|') && line.trim().endsWith('|')) {
        tableBuffer.push(line.trim());
        return;
      } else if (tableBuffer.length > 0) {
        blocks.push({
          type: 'table',
          content: [...tableBuffer]
        });
        tableBuffer = [];
      }

      // Headings
      if (line.startsWith('# ')) {
        blocks.push({ type: 'h1', content: line.replace('# ', '') });
      } else if (line.startsWith('## ')) {
        blocks.push({ type: 'h2', content: line.replace('## ', '') });
      } else if (line.startsWith('### ')) {
        blocks.push({ type: 'h3', content: line.replace('### ', '') });
      } else if (line.startsWith('> ')) {
        blocks.push({ type: 'quote', content: line.replace('> ', '') });
      } else if (line.startsWith('- ') || line.startsWith('* ') || line.startsWith('· ')) {
        blocks.push({ type: 'list-item', content: line.replace(/^[-*·]\s*/, '') });
      } else if (/^\d+\.\s/.test(line.trim())) {
        blocks.push({ type: 'ordered-item', content: line.trim().replace(/^\d+\.\s*/, '') });
      } else if (line.trim() === '---') {
        blocks.push({ type: 'hr', content: '' });
      } else if (line.trim() !== '') {
        blocks.push({ type: 'p', content: line });
      }
    });

    if (inCode && codeBuffer.length > 0) {
      blocks.push({ type: 'code', lang: codeLang, content: codeBuffer.join('\n') });
    }
    if (tableBuffer.length > 0) {
      blocks.push({ type: 'table', content: tableBuffer });
    }

    return blocks;
  };

  const renderInlineFormatted = (text: string) => {
    // Replace **bold** and `code`
    const parts: React.ReactNode[] = [];
    let remaining = text;
    let keyIdx = 0;

    while (remaining.length > 0) {
      // Check for bold **...**
      const boldMatch = remaining.match(/\*\*(.*?)\*\*/);
      // Check for inline code `...`
      const codeMatch = remaining.match(/`([^`]+)`/);

      let firstMatchIndex = Infinity;
      let matchType = '';
      let matchLength = 0;
      let matchedContent = '';

      if (boldMatch && boldMatch.index !== undefined && boldMatch.index < firstMatchIndex) {
        firstMatchIndex = boldMatch.index;
        matchType = 'bold';
        matchLength = boldMatch[0].length;
        matchedContent = boldMatch[1];
      }

      if (codeMatch && codeMatch.index !== undefined && codeMatch.index < firstMatchIndex) {
        firstMatchIndex = codeMatch.index;
        matchType = 'code';
        matchLength = codeMatch[0].length;
        matchedContent = codeMatch[1];
      }

      if (firstMatchIndex === Infinity) {
        parts.push(remaining);
        break;
      }

      if (firstMatchIndex > 0) {
        parts.push(remaining.substring(0, firstMatchIndex));
      }

      if (matchType === 'bold') {
        parts.push(
          <strong key={`bold-${keyIdx++}`} className="font-bold text-[#0f172a]">
            {matchedContent}
          </strong>
        );
      } else if (matchType === 'code') {
        parts.push(
          <code
            key={`code-${keyIdx++}`}
            className="px-1.5 py-0.5 rounded-md bg-[#f1f5f9] text-brand font-mono text-[11px] border border-[#e2e8f0]"
          >
            {matchedContent}
          </code>
        );
      }

      remaining = remaining.substring(firstMatchIndex + matchLength);
    }

    return parts;
  };

  const blocks = parseBlocks(content);

  return (
    <div className={`space-y-3 leading-relaxed text-[#334155] ${scale.wrap} ${className}`}>
      {blocks.map((block, idx) => {
        if (block.type === 'h1') {
          return (
            <h1 key={idx} className={`${scale.h1} font-extrabold text-[#0f172a] pt-2 pb-1 border-b border-[#e2e8f0] flex items-center gap-1.5`}>
              <span className="w-1.5 h-4 bg-brand rounded-full"></span>
              <span>{renderInlineFormatted(block.content)}</span>
            </h1>
          );
        }

        if (block.type === 'h2') {
          return (
            <h2 key={idx} className={`${scale.h2} font-bold text-[#0f172a] pt-2 flex items-center gap-1.5`}>
              <span className="w-1.5 h-3 bg-indigo-500 rounded-full"></span>
              <span>{renderInlineFormatted(block.content)}</span>
            </h2>
          );
        }

        if (block.type === 'h3') {
          return (
            <h3 key={idx} className={`${scale.h3} font-bold text-[#1e293b] pt-1`}>
              {renderInlineFormatted(block.content)}
            </h3>
          );
        }

        if (block.type === 'quote') {
          return (
            <blockquote key={idx} className="p-3 pl-3.5 bg-[#f8faff] border-l-3 border-brand rounded-r-xl text-xs text-[#475569] italic">
              {renderInlineFormatted(block.content)}
            </blockquote>
          );
        }

        if (block.type === 'list-item') {
          return (
            <div key={idx} className="flex items-start gap-2 pl-2">
              <span className="w-1.5 h-1.5 rounded-full bg-brand shrink-0 mt-1.5"></span>
              <span className="flex-1">{renderInlineFormatted(block.content)}</span>
            </div>
          );
        }

        if (block.type === 'ordered-item') {
          return (
            <div key={idx} className="flex items-start gap-2 pl-2">
              <span className="font-mono font-bold text-brand shrink-0">{idx + 1}.</span>
              <span className="flex-1">{renderInlineFormatted(block.content)}</span>
            </div>
          );
        }

        if (block.type === 'hr') {
          return <hr key={idx} className="border-t border-[#e2e8f0] my-2" />;
        }

        if (block.type === 'code') {
          return (
            <div key={idx} className="rounded-2xl border border-slate-800 bg-[#0f172a] text-slate-200 overflow-hidden shadow-md my-2">
              {/* Code header bar */}
              <div className="flex items-center justify-between px-3.5 py-1.5 bg-slate-900 border-b border-slate-800 text-[11px] font-mono text-slate-400">
                <div className="flex items-center gap-2">
                  <Terminal className="w-3.5 h-3.5 text-brand" />
                  <span className="text-slate-300 font-bold uppercase">{block.lang || 'code'}</span>
                </div>
                <button
                  type="button"
                  onClick={() => handleCopyCode(block.content, idx)}
                  className="px-2 py-0.5 rounded-md hover:bg-slate-800 text-slate-300 flex items-center gap-1 transition-colors"
                >
                  {copiedIndex === idx ? (
                    <>
                      <Check className="w-3 h-3 text-emerald-400" />
                      <span className="text-[10px] text-emerald-400">Copied!</span>
                    </>
                  ) : (
                    <>
                      <Copy className="w-3 h-3" />
                      <span className="text-[10px]">Copy</span>
                    </>
                  )}
                </button>
              </div>

              {/* Code content */}
              <pre className="p-3.5 text-xs font-mono leading-relaxed overflow-x-auto text-emerald-300">
                <code>{block.content}</code>
              </pre>
            </div>
          );
        }

        if (block.type === 'table') {
          const rows: string[][] = block.content.map((rowStr: string) =>
            rowStr
              .split('|')
              .slice(1, -1)
              .map(c => c.trim())
          );

          if (rows.length < 2) return null;
          const headers = rows[0];
          const dataRows = rows.slice(2); // Skip separator row

          return (
            <div key={idx} className="overflow-x-auto my-2 rounded-xl border border-[#e2e8f0]">
              <table className="w-full text-left text-xs border-collapse">
                <thead>
                  <tr className="bg-[#f8fafc] border-b border-[#e2e8f0]">
                    {headers.map((h, hIdx) => (
                      <th key={hIdx} className="p-2.5 font-bold text-[#0f172a]">
                        {renderInlineFormatted(h)}
                      </th>
                    ))}
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#f1f5f9]">
                  {dataRows.map((row, rIdx) => (
                    <tr key={rIdx} className="hover:bg-[#fafcff]">
                      {row.map((cell, cIdx) => (
                        <td key={cIdx} className="p-2.5 text-[#334155]">
                          {renderInlineFormatted(cell)}
                        </td>
                      ))}
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          );
        }

        return (
          <p key={idx} className="leading-relaxed">
            {renderInlineFormatted(block.content)}
          </p>
        );
      })}
    </div>
  );
};
