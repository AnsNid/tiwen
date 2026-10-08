import React, { useState, useMemo, useRef, useEffect } from 'react';
import { useSession } from '../../context/SessionContext';
import { useLanguage } from '../../context/LanguageContext';
import { Check, Search, SlidersHorizontal, X, Layers } from 'lucide-react';

const MAX_SELECTED = 6;

interface ModelSelectorProps {
  placement?: 'top' | 'bottom' | 'auto';
}

/**
 * 并发对比模型选择器
 * chips 展示已选模型,展开面板按类别勾选(最多 6 个并发)
 */
export const ModelSelector: React.FC<ModelSelectorProps> = ({ placement = 'auto' }) => {
  const { models, selectedModelIds, setSelectedModelIds, isThinking } = useSession();
  const { locale } = useLanguage();
  const [open, setOpen] = useState(false);
  const [keyword, setKeyword] = useState('');
  const [direction, setDirection] = useState<'top' | 'bottom'>('bottom');
  const boxRef = useRef<HTMLDivElement>(null);

  const zh = locale === 'zh';

  useEffect(() => {
    if (!open) return;
    if (placement === 'top' || placement === 'bottom') {
      setDirection(placement);
      return;
    }
    if (boxRef.current) {
      const rect = boxRef.current.getBoundingClientRect();
      const spaceBelow = window.innerHeight - rect.bottom;
      // 面板展开高度约 340px, 若下方空间不足 360px 则向上弹出
      if (spaceBelow < 360) {
        setDirection('top');
      } else {
        setDirection('bottom');
      }
    }
  }, [open, placement]);

  useEffect(() => {
    const onClickOutside = (e: MouseEvent) => {
      if (boxRef.current && !boxRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    if (open) {
      document.addEventListener('mousedown', onClickOutside);
    }
    return () => document.removeEventListener('mousedown', onClickOutside);
  }, [open]);

  const grouped = useMemo(() => {
    const kw = keyword.trim().toLowerCase();
    const filtered = models.filter(m =>
      !kw || m.id.toLowerCase().includes(kw) || m.name.toLowerCase().includes(kw) || (m.cat || '').toLowerCase().includes(kw)
    );
    const groups: Record<string, typeof models> = {};
    filtered.forEach(m => {
      const key = m.cat || '其他';
      (groups[key] = groups[key] || []).push(m);
    });
    return groups;
  }, [models, keyword]);

  const toggle = (id: string) => {
    if (isThinking) return;
    if (selectedModelIds.includes(id)) {
      if (selectedModelIds.length <= 1) return;
      setSelectedModelIds(selectedModelIds.filter(m => m !== id));
    } else {
      if (selectedModelIds.length >= MAX_SELECTED) return;
      setSelectedModelIds([...selectedModelIds, id]);
    }
  };

  const selectedMetas = selectedModelIds
    .map(id => models.find(m => m.id === id))
    .filter(Boolean) as typeof models;

  return (
    <div ref={boxRef} className="relative">
      {/* 已选模型 chips + 展开按钮 */}
      <div className="flex items-center gap-2 flex-wrap">
        <div className="flex items-center gap-1.5 flex-wrap">
          {selectedMetas.map(m => (
            <span
              key={m.id}
              className="group inline-flex items-center gap-1.5 pl-1.5 pr-2 py-1 rounded-xl border border-[#e2e8f0] bg-white text-[11px] font-semibold text-[#334155] shadow-2xs"
            >
              <span
                className="w-4 h-4 rounded-md text-white text-[8px] font-extrabold flex items-center justify-center shrink-0"
                style={{ backgroundColor: m.color }}
              >
                {m.avatarText}
              </span>
              <span className="max-w-[110px] truncate">{m.name}</span>
              <button
                type="button"
                onClick={() => toggle(m.id)}
                disabled={isThinking || selectedModelIds.length <= 1}
                className="text-[#94a3b8] hover:text-red-500 disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
              >
                <X className="w-3 h-3" />
              </button>
            </span>
          ))}
        </div>

        <button
          type="button"
          onClick={() => setOpen(!open)}
          disabled={isThinking}
          className={`inline-flex items-center gap-1.5 px-2.5 py-1 rounded-xl text-[11px] font-bold border transition-all shrink-0 ${
            open
              ? 'border-brand text-brand bg-brand-subtle'
              : 'border-dashed border-[#cbd5e1] text-[#65718a] hover:border-brand hover:text-brand'
          } disabled:opacity-50 disabled:cursor-not-allowed`}
        >
          <SlidersHorizontal className="w-3.5 h-3.5" />
          <span>{locale === 'zh' ? '选择模型' : locale === 'zh-TW' ? '選擇模型' : 'Models'}</span>
          <span className="font-mono">
            {selectedModelIds.length}/{models.length}
          </span>
        </button>
      </div>

      {/* 展开面板 */}
      {open && (
        <div className={`absolute left-0 right-0 ${
          direction === 'top' ? 'bottom-full mb-2 slide-in-from-bottom-2' : 'top-full mt-2 slide-in-from-top-2'
        } z-50 bg-white rounded-2xl border border-[#e2e8f0] shadow-2xl p-3.5 space-y-3 animate-in fade-in duration-150`}>
          {/* 搜索 + 计数 */}
          <div className="flex items-center gap-2">
            <div className="relative flex-1">
              <Search className="w-3.5 h-3.5 text-[#94a3b8] absolute left-2.5 top-1/2 -translate-y-1/2" />
              <input
                autoFocus
                value={keyword}
                onChange={e => setKeyword(e.target.value)}
                placeholder={
                  locale === 'zh'
                    ? '搜索模型 / 厂商 / 类别...'
                    : locale === 'zh-TW'
                    ? '搜尋模型 / 廠商 / 類別...'
                    : 'Search models...'
                }
                className="w-full pl-8 pr-3 py-1.5 rounded-xl border border-[#e2e8f0] bg-[#f8fafc] text-xs focus:outline-none focus:border-brand focus:ring-3 focus:ring-brand-subtle transition-all"
              />
            </div>
            <span className="text-[10px] font-bold text-[#8c97af] whitespace-nowrap">
              {locale === 'zh'
                ? `最多 ${MAX_SELECTED} 个并发`
                : locale === 'zh-TW'
                ? `最多 ${MAX_SELECTED} 個並發`
                : `Up to ${MAX_SELECTED}`}
            </span>
          </div>

          {/* 分组模型 */}
          <div className="max-h-64 overflow-y-auto space-y-3 pr-1">
            {Object.keys(grouped).length === 0 && (
              <div className="py-6 text-center text-xs text-[#94a3b8]">
                {locale === 'zh'
                  ? '未找到匹配的模型'
                  : locale === 'zh-TW'
                  ? '未找到相符的模型'
                  : 'No models found'}
              </div>
            )}
            {Object.entries(grouped).map(([cat, list]) => (
              <div key={cat}>
                <div className="flex items-center gap-1.5 mb-1.5">
                  <Layers className="w-3 h-3 text-[#94a3b8]" />
                  <span className="text-[10px] font-extrabold uppercase tracking-wider text-[#8c97af]">{cat}</span>
                </div>
                <div className="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
                  {list.map(m => {
                    const active = selectedModelIds.includes(m.id);
                    const disabled = !active && selectedModelIds.length >= MAX_SELECTED;
                    return (
                      <button
                        key={m.id}
                        type="button"
                        title={m.desc}
                        onClick={() => toggle(m.id)}
                        disabled={disabled || isThinking}
                        className={`flex items-center gap-2 px-2 py-1.5 rounded-xl border text-left transition-all ${
                          active
                            ? 'border-brand bg-brand-subtle ring-1 ring-brand/30'
                            : 'border-[#eef1f6] hover:border-[#cbd5e1] bg-white'
                        } ${disabled ? 'opacity-40 cursor-not-allowed' : ''}`}
                      >
                        <span
                          className="w-5 h-5 rounded-lg text-white text-[8px] font-extrabold flex items-center justify-center shrink-0"
                          style={{ backgroundColor: m.color }}
                        >
                          {m.avatarText}
                        </span>
                        <span className="flex-1 min-w-0">
                          <span className="block text-[11px] font-bold text-[#1b2230] truncate">{m.name}</span>
                          {m.badge && (
                            <span className="block text-[9px] text-[#8c97af] font-mono truncate">{m.badge}</span>
                          )}
                        </span>
                        {active && (
                          <span className="w-4 h-4 rounded-full bg-brand text-white flex items-center justify-center shrink-0">
                            <Check className="w-2.5 h-2.5 stroke-[3]" />
                          </span>
                        )}
                      </button>
                    );
                  })}
                </div>
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};
