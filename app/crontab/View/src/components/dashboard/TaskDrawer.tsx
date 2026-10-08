import type { CrontabTask } from "../../types";
import { motion } from "motion/react";
import { Clock, FileText, RefreshCw, Settings, Trash2, X } from "lucide-react";

interface TaskDrawerProps {
  task: CrontabTask;
  onClose: () => void;
  onViewLogs: (task: CrontabTask) => void;
  onRun: (task: CrontabTask) => void;
  onEdit: (task: CrontabTask) => void;
  onDelete: (task: CrontabTask) => void;
}

function Field({ label, children, className = "" }: { label: string; children: React.ReactNode; className?: string }) {
  return (
    <div className={className}>
      <p className="mb-1.5 text-xs font-medium text-ink-faint">{label}</p>
      <div className="rounded-xl border border-line bg-inset px-3 py-2.5 text-sm text-ink-soft">{children}</div>
    </div>
  );
}

export default function TaskDrawer({ task, onClose, onViewLogs, onRun, onEdit, onDelete }: TaskDrawerProps) {
  return (
    <>
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        onClick={onClose}
        className="fixed inset-0 z-40 bg-ink/25"
      />
      <motion.aside
        initial={{ x: "100%" }}
        animate={{ x: 0 }}
        exit={{ x: "100%" }}
        transition={{ type: "spring", damping: 28, stiffness: 260 }}
        className="fixed bottom-0 right-0 top-0 z-50 flex w-full max-w-md flex-col bg-surface shadow-float"
        role="dialog"
        aria-label="任务详情"
      >
        <header className="flex items-center justify-between border-b border-line px-5 py-4">
          <div className="flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-soft text-accent">
              <Clock className="h-5 w-5" />
            </span>
            <div>
              <h2 className="text-base font-semibold text-ink">任务详情</h2>
              <p className="tnum text-xs text-ink-faint">ID {task.id}</p>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="关闭"
            className="flex h-9 w-9 items-center justify-center rounded-lg text-ink-faint transition-colors hover:bg-inset hover:text-ink"
          >
            <X className="h-5 w-5" />
          </button>
        </header>

        <div className="custom-scrollbar flex-1 space-y-3 overflow-y-auto p-5">
          <Field label="任务名称">
            <span className="font-semibold text-ink">{task.name}</span>
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Cron 规则">
              <span className="font-mono font-semibold text-accent">{task.rule}</span>
            </Field>
            <Field label="类型">
              {task.type === "callback" ? "类回调 (Callback)" : "系统指令 (Command)"}
            </Field>
          </div>
          <div>
            <p className="mb-1.5 text-xs font-medium text-ink-faint">执行目标</p>
            <div className="rounded-xl bg-code-bg px-3 py-2.5">
              <code className="block whitespace-pre-wrap break-all font-mono text-xs leading-relaxed text-code-ink">
                {task.callback}
              </code>
            </div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="并发策略">{task.singleton === 1 ? "单例执行" : "允许并发"}</Field>
            <Field label="节点策略">{task.on_one_server === 1 ? "单节点执行" : "多节点并行"}</Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="互斥锁池">{task.mutex_pool || "默认"}</Field>
            <Field label="锁过期时间">{task.mutex_expires}s</Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="时区">{task.timezone || "默认"}</Field>
            <Field label="状态">
              {task.status === 1 ? (
                <span className="font-medium text-up">启用中</span>
              ) : (
                <span className="font-medium text-ink-faint">已暂停</span>
              )}
            </Field>
          </div>
          {task.environments && (
            <Field label="环境变量">
              <span className="whitespace-pre-wrap font-mono text-xs">{task.environments}</span>
            </Field>
          )}
          <Field label="备注">
            <span className="text-ink-faint">{task.memo || "未填写"}</span>
          </Field>
          {task.updated_at && (
            <Field label="最后更新">
              <span className="tnum">{task.updated_at}</span>
            </Field>
          )}
        </div>

        <footer className="space-y-3 border-t border-line p-5">
          <div className="grid grid-cols-3 gap-3">
            <button
              type="button"
              onClick={() => onViewLogs(task)}
              className="flex h-11 items-center justify-center gap-2 rounded-xl border border-line bg-surface text-sm font-medium text-ink-soft transition-colors hover:border-accent hover:text-accent"
            >
              <FileText className="h-4 w-4" />
              日志
            </button>
            <button
              type="button"
              onClick={() => onRun(task)}
              className="flex h-11 items-center justify-center gap-2 rounded-xl border border-line bg-surface text-sm font-medium text-ink-soft transition-colors hover:border-accent hover:text-accent"
            >
              <RefreshCw className="h-4 w-4" />
              运行
            </button>
            <button
              type="button"
              onClick={() => onDelete(task)}
              className="flex h-11 items-center justify-center gap-2 rounded-xl border border-line bg-surface text-sm font-medium text-ink-soft transition-colors hover:border-down hover:bg-down-soft hover:text-down"
            >
              <Trash2 className="h-4 w-4" />
              删除
            </button>
          </div>
          <button
            type="button"
            onClick={() => onEdit(task)}
            className="flex h-11 w-full items-center justify-center gap-2 rounded-xl bg-accent text-sm font-semibold text-white shadow-glow transition-colors hover:bg-accent-strong"
          >
            <Settings className="h-4 w-4" />
            编辑配置
          </button>
        </footer>
      </motion.aside>
    </>
  );
}
