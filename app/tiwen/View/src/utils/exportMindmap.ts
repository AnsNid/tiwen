/**
 * 思维导图 PNG 导出(纯客户端,无第三方依赖)
 * Markdown 树 → SVG 横向树布局 → canvas 2x 栅格化 → PNG 下载
 * 复用 MindmapViewer 的解析器,配色与页面导图保持一致
 */
import { parseMarkdownToTree } from '../components/common/MindmapViewer';
import { downloadBlob } from './exportDoc';

const FONT = "-apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', 'Noto Sans SC', 'Segoe UI', Roboto, sans-serif";
const FONT_SIZE = 13;
const LINE_HEIGHT = 18;
const PAD_X = 12;
const PAD_Y = 9;
const NODE_GAP_X = 56;
const NODE_GAP_Y = 10;
const MAX_TEXT_WIDTH = 280;
const MARGIN = 40;
const MAX_NODES = 600;

/** 深度配色:与 MindmapViewer 层级样式一致 */
const DEPTH_STYLES = [
  { fill: '#2f6bff', text: '#ffffff', stroke: '#2f6bff', bold: true },
  { fill: '#eff6ff', text: '#1e3a8a', stroke: '#bfdbfe', bold: true },
  { fill: '#ecfdf5', text: '#064e3b', stroke: '#a7f3d0', bold: false },
  { fill: '#f8fafc', text: '#334155', stroke: '#e2e8f0', bold: false },
];

function escapeXml(s: string): string {
  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/** 字符宽度估算:CJK 全角 ≈ 1em,半角 ≈ 0.6em */
function charW(ch: string): number {
  return ch.charCodeAt(0) > 0x2e80 ? FONT_SIZE : FONT_SIZE * 0.6;
}

/** 按最大宽度贪心折行,返回行数组 */
function wrapText(text: string): string[] {
  const lines: string[] = [];
  let line = '';
  let w = 0;
  for (const ch of text) {
    const cw = charW(ch);
    if (w + cw > MAX_TEXT_WIDTH && line !== '') {
      lines.push(line);
      line = ch;
      w = cw;
    } else {
      line += ch;
      w += cw;
    }
  }
  if (line !== '') lines.push(line);
  return lines.length ? lines : [''];
}

interface LaidNode {
  x: number;
  y: number; // 顶部 y
  w: number;
  h: number;
  lines: string[];
  depth: number;
  children: LaidNode[];
}

/** 粗体宽度近似加成 */
function textWidth(line: string, bold: boolean): number {
  let w = 0;
  for (const ch of line) w += charW(ch);
  return w * (bold ? 1.06 : 1);
}

export async function exportMindmapPng(markdownText: string, title: string, filename?: string): Promise<void> {
  const rawTree = parseMarkdownToTree(markdownText);
  if (rawTree.length === 0) {
    throw new Error('暂无结构化内容以生成思维导图');
  }

  // ---- 1. 度量节点并压平(限流保护) ----
  const nodes: LaidNode[] = [];
  let truncated = false;
  const measure = (node: { text: string; children: any[] }, depth: number): LaidNode => {
    const style = DEPTH_STYLES[Math.min(depth, DEPTH_STYLES.length - 1)];
    const lines = wrapText(node.text);
    const w = Math.min(MAX_TEXT_WIDTH + PAD_X * 2, Math.max(...lines.map(l => textWidth(l, style.bold))) + PAD_X * 2);
    const h = lines.length * LINE_HEIGHT + PAD_Y * 2 - 4;
    const laid: LaidNode = { x: 0, y: 0, w, h, lines, depth, children: [] };
    nodes.push(laid);
    if (nodes.length >= MAX_NODES) {
      truncated = true;
      return laid;
    }
    laid.children = (node.children || []).map(c => measure(c, depth + 1));
    return laid;
  };
  const roots = rawTree.map(r => measure(r, 0));
  if (truncated && roots.length === 0) throw new Error('内容过大,无法生成导图');

  // ---- 2. 列 x 坐标:按深度取该列最大节点宽 ----
  const colMaxW: number[] = [];
  for (const n of nodes) {
    colMaxW[n.depth] = Math.max(colMaxW[n.depth] || 0, n.w);
  }
  const colX: number[] = [];
  let cursorX = MARGIN;
  colMaxW.forEach((w, d) => {
    colX[d] = cursorX;
    cursorX += w + NODE_GAP_X;
  });
  nodes.forEach(n => { n.x = colX[n.depth]; });

  // ---- 3. y 布局:叶子依序堆叠,父节点垂直居中于子块 ----
  let leafY = MARGIN + 76; // 顶部留给标题
  const layoutY = (n: LaidNode): void => {
    if (n.children.length === 0) {
      n.y = leafY;
      leafY += n.h + NODE_GAP_Y;
      return;
    }
    n.children.forEach(layoutY);
    const first = n.children[0];
    const last = n.children[n.children.length - 1];
    n.y = (first.y + last.y + last.h - n.h) / 2;
    leafY = Math.max(leafY, last.y + last.h + NODE_GAP_Y);
  };
  roots.forEach(layoutY);

  // ---- 4. 画布尺寸 ----
  const treeW = Math.max(...nodes.map(n => n.x + n.w));
  const treeH = Math.max(...nodes.map(n => n.y + n.h));
  const W = treeW + MARGIN;
  const H = treeH + MARGIN + 34; // 底部水印

  // ---- 5. SVG 组装 ----
  const parts: string[] = [];
  for (const n of nodes) {
    const st = DEPTH_STYLES[Math.min(n.depth, DEPTH_STYLES.length - 1)];
    parts.push(`<rect x="${n.x.toFixed(1)}" y="${n.y.toFixed(1)}" width="${n.w.toFixed(1)}" height="${n.h.toFixed(1)}" rx="${n.depth === 0 ? 10 : 8}" fill="${st.fill}" stroke="${st.stroke}" stroke-width="1"/>`);
    const firstBaseline = n.y + PAD_Y + FONT_SIZE;
    const tspans = n.lines.map((l, i) =>
      `<tspan x="${(n.x + PAD_X).toFixed(1)}" y="${(firstBaseline + i * LINE_HEIGHT).toFixed(1)}">${escapeXml(l)}</tspan>`
    ).join('');
    parts.push(`<text font-family="${FONT}" font-size="${FONT_SIZE}" fill="${st.text}" ${st.bold ? 'font-weight="700"' : ''}>${tspans}</text>`);
    // 连线:父右缘 → 子左缘 贝塞尔
    for (const c of n.children) {
      const x1 = n.x + n.w;
      const y1 = n.y + n.h / 2;
      const x2 = c.x;
      const y2 = c.y + c.h / 2;
      const mid = (x1 + x2) / 2;
      parts.push(`<path d="M ${x1.toFixed(1)} ${y1.toFixed(1)} C ${mid.toFixed(1)} ${y1.toFixed(1)}, ${mid.toFixed(1)} ${y2.toFixed(1)}, ${x2.toFixed(1)} ${y2.toFixed(1)}" fill="none" stroke="#cbd5e1" stroke-width="1.5"/>`);
    }
  }

  const dateStr = new Date().toLocaleString();
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">
  <rect width="${W}" height="${H}" fill="#fafcff"/>
  <rect x="${MARGIN - 12}" y="${MARGIN - 12}" width="${(W - MARGIN * 2 + 24).toFixed(0)}" height="52" rx="12" fill="#ffffff" stroke="#dce8ff"/>
  <text font-family="${FONT}" font-size="16" font-weight="800" fill="#0f172a" x="${MARGIN}" y="${MARGIN + 16}">${escapeXml(title)}</text>
  <text font-family="${FONT}" font-size="11" fill="#8c97af" x="${MARGIN}" y="${MARGIN + 34}">${escapeXml(dateStr)} · 由 tiwen AI 研报工作台生成${truncated ? ' · 内容过多已截断' : ''}</text>
  ${parts.join('\n  ')}
  <text font-family="${FONT}" font-size="11" fill="#94a3b8" x="${(W / 2).toFixed(1)}" y="${(H - 14).toFixed(1)}" text-anchor="middle">tiwen · 多模型多轮提问工作台</text>
</svg>`;

  // ---- 6. 栅格化(2x 高清) ----
  const svgBlob = new Blob([svg], { type: 'image/svg+xml;charset=utf-8' });
  const url = URL.createObjectURL(svgBlob);
  try {
    const img = await new Promise<HTMLImageElement>((resolve, reject) => {
      const image = new Image();
      image.onload = () => resolve(image);
      image.onerror = () => reject(new Error('导图渲染失败'));
      image.src = url;
    });
    const scale = 2;
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(W * scale);
    canvas.height = Math.round(H * scale);
    const ctx = canvas.getContext('2d');
    if (!ctx) throw new Error('导图渲染失败');
    ctx.fillStyle = '#fafcff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
    const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/png'));
    if (!blob) throw new Error('导图生成失败');
    const safeName = (filename || title || 'tiwen-mindmap').replace(/[\\/:*?"<>|]/g, '_').slice(0, 60);
    downloadBlob(blob, `${safeName}.png`);
  } finally {
    URL.revokeObjectURL(url);
  }
}
