import React, { useState } from 'react';
import { ChevronRight, ChevronDown, GitBranch, Layers, Sparkles } from 'lucide-react';

interface MindmapNode {
  id: string;
  text: string;
  level: number;
  children: MindmapNode[];
}

interface MindmapViewerProps {
  markdownText: string;
  title?: string;
}

// Parses markdown headers and bullet points into a hierarchical node tree
export function parseMarkdownToTree(md: string): MindmapNode[] {
  const lines = md.split('\n').filter(l => l.trim().length > 0);
  const rootNodes: MindmapNode[] = [];
  const stack: { node: MindmapNode; level: number }[] = [];

  let nodeCounter = 0;

  lines.forEach(line => {
    let level = 1;
    let text = line.trim();

    if (line.startsWith('# ')) {
      level = 1;
      text = line.replace('# ', '').trim();
    } else if (line.startsWith('## ')) {
      level = 2;
      text = line.replace('## ', '').trim();
    } else if (line.startsWith('### ')) {
      level = 3;
      text = line.replace('### ', '').trim();
    } else if (line.startsWith('- ') || line.startsWith('* ') || line.startsWith('· ')) {
      // Calculate indent level
      const indent = line.search(/\S/);
      level = 3 + Math.floor(indent / 2);
      text = line.trim().replace(/^[-*·]\s*/, '').trim();
    } else if (/^\d+\.\s/.test(text)) {
      level = 3;
      text = text.replace(/^\d+\.\s*/, '').trim();
    } else if (line.startsWith('**') && line.endsWith('**')) {
      level = 2;
      text = line.replace(/\*\*/g, '').trim();
    } else {
      // Regular text or paragraph
      level = 4;
      text = line.trim();
    }

    // Strip remaining bold tags from text
    text = text.replace(/\*\*(.*?)\*\*/g, '$1');

    if (!text || text.startsWith('---')) return;

    nodeCounter++;
    const newNode: MindmapNode = {
      id: `node-${nodeCounter}`,
      text: text.slice(0, 120),
      level,
      children: []
    };

    while (stack.length > 0 && stack[stack.length - 1].level >= level) {
      stack.pop();
    }

    if (stack.length === 0) {
      rootNodes.push(newNode);
    } else {
      stack[stack.length - 1].node.children.push(newNode);
    }

    stack.push({ node: newNode, level });
  });

  return rootNodes;
}

export const MindmapViewer: React.FC<MindmapViewerProps> = ({ markdownText, title }) => {
  const tree = parseMarkdownToTree(markdownText);
  const [collapsedNodes, setCollapsedNodes] = useState<Record<string, boolean>>({});

  const toggleCollapse = (id: string) => {
    setCollapsedNodes(prev => ({ ...prev, [id]: !prev[id] }));
  };

  const renderNode = (node: MindmapNode, depth = 0) => {
    const isCollapsed = !!collapsedNodes[node.id];
    const hasChildren = node.children.length > 0;

    // Color theme according to hierarchy depth
    const levelStyles = [
      'bg-brand text-white border-brand shadow-sm font-bold text-xs sm:text-sm', // Root (level 1)
      'bg-blue-50 text-blue-900 border-blue-200 font-semibold text-xs', // Section (level 2)
      'bg-emerald-50 text-emerald-900 border-emerald-200 text-xs font-medium', // Point (level 3)
      'bg-[#f8fafc] text-[#334155] border-[#e2e8f0] text-[11px]' // Detail (level 4+)
    ];

    const currentStyle = levelStyles[Math.min(depth, levelStyles.length - 1)];

    return (
      <div key={node.id} className="relative pl-6 py-1.5 transition-all">
        {/* Connecting branch curve */}
        <div className="absolute left-2 top-4 w-4 h-0.5 bg-[#cbd5e1]" />
        {depth > 0 && (
          <div className="absolute left-2 top-0 bottom-0 w-0.5 bg-[#e2e8f0]" />
        )}

        <div className="flex items-start gap-2">
          {/* Node pill */}
          <div
            className={`inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl border max-w-xl transition-all ${currentStyle}`}
          >
            {hasChildren && (
              <button
                onClick={() => toggleCollapse(node.id)}
                className="p-0.5 hover:opacity-75 focus:outline-none"
              >
                {isCollapsed ? (
                  <ChevronRight className="w-3.5 h-3.5" />
                ) : (
                  <ChevronDown className="w-3.5 h-3.5" />
                )}
              </button>
            )}
            <span className="leading-snug break-words">{node.text}</span>
          </div>
        </div>

        {/* Children nodes */}
        {hasChildren && !isCollapsed && (
          <div className="space-y-1 mt-1 border-l-2 border-[#e2e8f0] ml-3 pl-3">
            {node.children.map(child => renderNode(child, depth + 1))}
          </div>
        )}
      </div>
    );
  };

  if (tree.length === 0) {
    return (
      <div className="p-8 text-center bg-white rounded-2xl border border-[#e7ebf3] text-xs text-[#8c97af]">
        <GitBranch className="w-8 h-8 text-[#cbd5e1] mx-auto mb-2" />
        <p>暂无结构化内容以生成思维导图</p>
      </div>
    );
  }

  return (
    <div className="bg-[#fafcff] rounded-2xl p-5 border border-[#dce8ff] space-y-4 overflow-x-auto">
      <div className="flex items-center justify-between border-b border-[#e2e8f0] pb-3">
        <div className="flex items-center gap-2 text-xs font-bold text-brand">
          <GitBranch className="w-4 h-4" />
          <span>{title || 'AI 结构化推演思维导图'}</span>
        </div>
        <span className="text-[10px] text-[#8c97af] font-mono">
          自动从 Markdown 标题与层级提炼
        </span>
      </div>

      <div className="space-y-3 pt-2">
        {tree.map(root => renderNode(root, 0))}
      </div>
    </div>
  );
};
