import React, { useState, useEffect } from 'react';
import { cn } from '../lib/utils';

interface CronPickerProps {
  value: string;
  onChange: (val: string) => void;
}

function getActivePreset(val: string): string | null {
  const p = val.trim().split(/\s+/);
  while (p.length < 5) p.push('*');
  const [minute, hour, day, month, week] = p;

  if (minute === '*' && hour === '*' && day === '*' && month === '*' && week === '*') return '* * * * *';
  if (hour === '*' && day === '*' && month === '*' && week === '*') return '0 * * * *';
  if (day === '*' && month === '*' && week === '*') return '0 0 * * *';
  if (month === '*' && day === '*' && week !== '*') return '0 0 * * 1';
  if (month === '*' && week === '*' && day !== '*') return '0 0 1 * *';
  return null;
}

export default function CronPicker({ value, onChange }: CronPickerProps) {
  // 五个字段的手动编辑状态
  const [parts, setParts] = useState<string[]>(() => {
    const p = (value || '* * * * *').trim().split(/\s+/);
    while (p.length < 5) p.push('*');
    return p.slice(0, 5);
  });

  // 与外部 value 保持同步
  useEffect(() => {
    if (value) {
      const p = value.trim().split(/\s+/);
      const padded = [...p];
      while (padded.length < 5) padded.push('*');
      const sliced = padded.slice(0, 5);

      if (sliced.join(' ') !== parts.join(' ')) {
        setParts(sliced);
      }
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  const handlePartChange = (idx: number, val: string) => {
    const newParts = [...parts];
    newParts[idx] = val; // 允许清空与输入中途态
    setParts(newParts);

    const emitValue = newParts.map(p => p.trim() === '' ? '*' : p.trim()).join(' ');
    onChange(emitValue);
  };

  const labels = ['分', '时', '日', '月', '周'];
  const hints = ['0-59', '0-23', '1-31', '1-12', '0-7'];

  const presets = [
    { label: '每分钟', value: '* * * * *' },
    { label: '每小时', value: '0 * * * *' },
    { label: '每天', value: '0 0 * * *' },
    { label: '每周', value: '0 0 * * 1' },
    { label: '每月', value: '0 0 1 * *' },
  ];

  return (
    <div className="flex flex-col gap-3 rounded-xl border border-line bg-surface p-3">
      <div className="flex flex-wrap gap-1.5 border-b border-dashed border-line pb-3">
        {presets.map((p) => (
          <button
            key={p.label}
            type="button"
            onClick={() => onChange(p.value)}
            className={cn(
              'rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors',
              getActivePreset(value) === p.value
                ? 'bg-accent text-white'
                : 'bg-inset text-ink-faint hover:bg-accent-soft hover:text-accent'
            )}
          >
            {p.label}
          </button>
        ))}
      </div>

      <div className="grid grid-cols-5 gap-2">
        {labels.map((label, idx) => (
          <div key={idx} className="flex flex-col items-center gap-1">
            <span className="text-xs font-medium text-ink-faint">{label}</span>
            <input
              type="text"
              value={parts[idx]}
              onChange={e => handlePartChange(idx, e.target.value)}
              className="h-10 w-full rounded-xl border border-line bg-inset text-center font-mono text-sm font-medium text-ink transition-colors focus:border-accent focus:bg-surface focus:outline-none focus:ring-2 focus:ring-accent/15"
              placeholder="*"
            />
            <span className="font-mono text-xs text-ink-faint/70">{hints[idx]}</span>
          </div>
        ))}
      </div>

      <div className="flex items-center justify-between rounded-lg bg-accent-soft px-3 py-2">
        <span className="text-xs font-medium text-accent">表达式</span>
        <span className="font-mono text-sm font-semibold text-accent">
          {parts.map(p => p.trim() === '' ? '*' : p.trim()).join(' ')}
        </span>
      </div>
    </div>
  );
}
