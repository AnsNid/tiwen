import { useCallback, useEffect, useRef, useState } from "react";
import { motion } from "motion/react";
import { FileText, RefreshCw, X } from "lucide-react";
import { apiGet } from "../../lib/api";
import type { CrontabTask, TaskLogResult } from "../../types";

interface LogModalProps {
  /** 数据库任务与注解任务均可用, 日志按任务名读取 */
  task: Pick<CrontabTask, "name">;
  onClose: () => void;
}

const BATCH_SIZE = 50;

function lineTone(line: string): string {
  if (line.includes(".ERROR:")) {
    return "text-down-bright font-semibold";
  }
  if (line.includes(".WARNING:")) {
    return "text-warn-bright font-semibold";
  }
  return "text-code-ink";
}

export default function LogModal({ task, onClose }: LogModalProps) {
  const [lines, setLines] = useState<string[]>([]);
  const [total, setTotal] = useState(0);
  const [loaded, setLoaded] = useState(0);
  const [hasMore, setHasMore] = useState(true);
  const [isLoading, setIsLoading] = useState(false);
  // 请求序号:防止快速刷新时旧响应覆盖新响应
  const requestSeq = useRef(0);

  const fetchLogs = useCallback(
    async (offset: number) => {
      const seq = ++requestSeq.current;
      setIsLoading(true);
      if (offset === 0) {
        setLines([]);
        setHasMore(true);
      }
      try {
        const result = await apiGet<TaskLogResult>("/getLog", {
          name: task.name,
          lines: BATCH_SIZE,
          offset,
        });
        if (seq !== requestSeq.current) return;
        const batch = result.lines ?? [];
        const count = offset + batch.length;
        setLines((prev) => (offset === 0 ? batch : [...prev, ...batch]));
        setTotal(result.total ?? 0);
        setLoaded(count);
        setHasMore(count < (result.total ?? 0));
      } catch {
        // 拦截器已提示
      } finally {
        if (seq === requestSeq.current) setIsLoading(false);
      }
    },
    [task.name]
  );

  useEffect(() => {
    fetchLogs(0);
    return () => {
      requestSeq.current++;
    };
  }, [fetchLogs]);

  return (
    <>
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        onClick={onClose}
        className="fixed inset-0 z-[60] bg-ink/25"
      />
      <motion.div
        initial={{ opacity: 0, scale: 0.97, y: 8 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        exit={{ opacity: 0, scale: 0.97, y: 8 }}
        transition={{ duration: 0.16 }}
        role="dialog"
        aria-label="执行日志"
        className="fixed left-1/2 top-1/2 z-[70] flex max-h-[88vh] w-[calc(100%-1.5rem)] max-w-4xl -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-float"
      >
        <header className="flex items-center justify-between border-b border-line px-5 py-4">
          <div className="flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-soft text-accent">
              <FileText className="h-5 w-5" />
            </span>
            <div>
              <h2 className="text-base font-semibold text-ink">执行日志</h2>
              <p className="font-mono text-xs text-ink-faint">{task.name}</p>
            </div>
          </div>
          <div className="flex items-center gap-1">
            <button
              type="button"
              onClick={() => fetchLogs(0)}
              disabled={isLoading}
              title="刷新日志"
              aria-label="刷新日志"
              className="flex h-9 w-9 items-center justify-center rounded-lg text-ink-faint transition-colors hover:bg-inset hover:text-accent disabled:opacity-50"
            >
              <RefreshCw className={`h-4 w-4 ${isLoading ? "animate-spin" : ""}`} />
            </button>
            <button
              type="button"
              onClick={onClose}
              aria-label="关闭"
              className="flex h-9 w-9 items-center justify-center rounded-lg text-ink-faint transition-colors hover:bg-inset hover:text-ink"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        </header>

        <div className="custom-scrollbar flex-1 overflow-auto bg-page/60 p-4">
          {lines.length === 0 && !isLoading ? (
            <div className="flex flex-col items-center justify-center gap-2 py-16 text-center">
              <FileText className="h-10 w-10 text-ink-faint/40" />
              <p className="text-sm text-ink-soft">暂无日志记录</p>
              <p className="text-xs text-ink-faint">任务执行后将在此输出运行日志</p>
            </div>
          ) : (
            <div className="overflow-hidden rounded-xl border border-line bg-code-bg">
              <div className="custom-scrollbar overflow-x-auto">
                <div className="min-w-max px-3 py-2 font-mono text-xs leading-6">
                  {lines.map((line, idx) => (
                    <div key={idx} className="flex items-start gap-3 rounded px-2 hover:bg-white/5">
                      <span className="tnum w-8 shrink-0 select-none text-right text-ink-faint/40">
                        {idx + 1}
                      </span>
                      <span className={`whitespace-pre-wrap break-all ${lineTone(line)}`}>
                        {line === "" ? "\u00a0" : line}
                      </span>
                    </div>
                  ))}
                </div>
              </div>
            </div>
          )}

          {isLoading && (
            <div className="flex items-center justify-center gap-2 p-4 text-xs text-accent">
              <span className="h-4 w-4 animate-spin rounded-full border-2 border-accent/25 border-t-accent" />
              正在加载日志…
            </div>
          )}

          {!isLoading && hasMore && lines.length > 0 && (
            <div className="flex justify-center p-4">
              <button
                type="button"
                onClick={() => fetchLogs(loaded)}
                className="rounded-xl border border-line bg-surface px-5 py-2.5 text-xs font-medium text-ink-soft transition-colors hover:border-accent hover:text-accent"
              >
                加载更早的日志
              </button>
            </div>
          )}
        </div>

        <footer className="tnum flex items-center justify-between border-t border-line px-5 py-3 text-xs text-ink-faint">
          <span>已加载 {lines.length} 条</span>
          <span>{hasMore ? `共 ${total} 条` : "已到底部"}</span>
        </footer>
      </motion.div>
    </>
  );
}
