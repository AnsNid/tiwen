import { useState } from "react";
import { motion } from "motion/react";
import toast from "react-hot-toast";
import { Lock, Settings, X } from "lucide-react";
import { apiPost } from "../../lib/api";
import CronPicker from "../CronPicker";
import type { CrontabTask } from "../../types";

interface TaskFormModalProps {
  /** 待编辑任务,null 表示新建 */
  task: CrontabTask | null;
  onClose: () => void;
  onSaved: () => void;
}

interface TaskFormState {
  name: string;
  type: "command" | "callback";
  rule: string;
  command: string;
  callbackClass: string;
  callbackMethod: string;
  memo: string;
  singleton: number;
  mutex_pool: string;
  mutex_expires: number;
  on_one_server: number;
  timezone: string;
  environments: string;
}

const DEFAULT_FORM: TaskFormState = {
  name: "",
  type: "command",
  rule: "* * * * *",
  command: "",
  callbackClass: "",
  callbackMethod: "",
  memo: "",
  singleton: 0,
  mutex_pool: "default",
  mutex_expires: 300,
  on_one_server: 0,
  timezone: "Asia/Shanghai",
  environments: "",
};

function taskToForm(task: CrontabTask | null): TaskFormState {
  if (!task) {
    return { ...DEFAULT_FORM };
  }
  let callbackClass = "";
  let callbackMethod = "";
  let command = "";
  if (task.type === "callback") {
    try {
      const parsed = JSON.parse(task.callback);
      if (Array.isArray(parsed) && parsed.length >= 2) {
        callbackClass = String(parsed[0]);
        callbackMethod = String(parsed[1]);
      }
    } catch {
      // callback 不是合法 JSON 时保持空,由用户重填
    }
  } else {
    command = task.callback;
  }
  return {
    name: task.name,
    type: task.type === "callback" ? "callback" : "command",
    rule: task.rule,
    command,
    callbackClass,
    callbackMethod,
    memo: task.memo || "",
    singleton: task.singleton ? 1 : 0,
    mutex_pool: task.mutex_pool || "default",
    mutex_expires: task.mutex_expires || 300,
    on_one_server: task.on_one_server ? 1 : 0,
    timezone: task.timezone || "Asia/Shanghai",
    environments: task.environments || "",
  };
}

const inputClass =
  "h-10 w-full rounded-xl border border-line bg-inset px-3 text-sm text-ink placeholder:text-ink-faint/60 transition-colors focus:border-accent focus:bg-surface focus:outline-none focus:ring-2 focus:ring-accent/15";
const monoInputClass =
  "w-full rounded-xl border border-line bg-inset px-3 py-2.5 font-mono text-xs text-ink placeholder:text-ink-faint/60 transition-colors focus:border-accent focus:bg-surface focus:outline-none focus:ring-2 focus:ring-accent/15";

function Field({
  label,
  required,
  htmlFor,
  className = "",
  children,
}: {
  label: string;
  required?: boolean;
  htmlFor?: string;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <div className={className}>
      <label htmlFor={htmlFor} className="mb-1.5 block text-xs font-medium text-ink-faint">
        {label}
        {required && <span className="ml-0.5 text-down">*</span>}
      </label>
      {children}
    </div>
  );
}

export default function TaskFormModal({ task, onClose, onSaved }: TaskFormModalProps) {
  const [form, setForm] = useState<TaskFormState>(() => taskToForm(task));
  const [isSaving, setIsSaving] = useState(false);
  // 接管注解任务的行: 仅表达式/备注可改, 其余字段以代码 #[Crontab] 定义为准(后端强校验)
  const locked = task?.is_annotation === 1;
  const lockedClass = locked ? "disabled:cursor-not-allowed disabled:opacity-60" : "";

  const setField = <K extends keyof TaskFormState>(key: K, value: TaskFormState[K]) => {
    setForm((prev) => ({ ...prev, [key]: value }));
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsSaving(true);
    try {
      // 锁定字段必须原样回传数据库值, 避免表单默认值(如时区回显 Asia/Shanghai)触发后端锁定校验
      const payload: Record<string, unknown> = {
        id: task?.id,
        status: task ? task.status : 1,
        name: locked && task ? task.name : form.name.trim(),
        type: locked && task ? task.type : form.type,
        rule: form.rule.trim(),
        callback:
          locked && task
            ? task.callback
            : form.type === "callback"
              ? JSON.stringify([form.callbackClass.trim(), form.callbackMethod.trim()])
              : form.command.trim(),
        memo: form.memo.trim(),
        singleton: locked && task ? task.singleton : form.singleton,
        mutex_pool: locked && task ? task.mutex_pool : form.mutex_pool.trim() || "default",
        mutex_expires: locked && task ? task.mutex_expires : Number(form.mutex_expires) || 300,
        on_one_server: locked && task ? task.on_one_server : form.on_one_server,
        timezone: locked && task ? task.timezone || "" : form.timezone.trim() || "Asia/Shanghai",
        environments: locked && task ? task.environments || "" : form.environments.trim(),
      };
      await apiPost(task ? "/updateTask" : "/createTask", payload);
      toast.success(task ? "任务已更新" : "任务已创建");
      onSaved();
      onClose();
    } catch {
      // 拦截器已提示
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <>
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        onClick={() => !isSaving && onClose()}
        className="fixed inset-0 z-[60] bg-ink/25"
      />
      <motion.div
        initial={{ opacity: 0, scale: 0.97, y: 8 }}
        animate={{ opacity: 1, scale: 1, y: 0 }}
        exit={{ opacity: 0, scale: 0.97, y: 8 }}
        transition={{ duration: 0.16 }}
        role="dialog"
        aria-label={task ? "编辑任务" : "新建任务"}
        className="fixed left-1/2 top-1/2 z-[70] flex max-h-[90vh] w-[calc(100%-1.5rem)] max-w-2xl -translate-x-1/2 -translate-y-1/2 flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-float"
      >
        <header className="flex items-center justify-between border-b border-line px-5 py-4">
          <div className="flex items-center gap-3">
            <span className="flex h-10 w-10 items-center justify-center rounded-xl bg-accent-soft text-accent">
              <Settings className="h-5 w-5" />
            </span>
            <h2 className="text-base font-semibold text-ink">{task ? "编辑任务" : "新建任务"}</h2>
          </div>
          <button
            type="button"
            onClick={() => !isSaving && onClose()}
            aria-label="关闭"
            className="flex h-9 w-9 items-center justify-center rounded-lg text-ink-faint transition-colors hover:bg-inset hover:text-ink"
          >
            <X className="h-5 w-5" />
          </button>
        </header>

        <form onSubmit={handleSubmit} className="flex min-h-0 flex-col">
          {locked && (
            <div className="flex items-start gap-2.5 border-b border-warn/20 bg-warn-soft px-5 py-3">
              <Lock className="mt-0.5 h-4 w-4 shrink-0 text-warn" />
              <p className="text-xs leading-relaxed text-warn">
                此任务已接管代码注解, 仅可修改 Cron 表达式与备注; 名称、回调、并发等锁定字段以代码 #[Crontab] 定义为准, 删除该任务即恢复代码默认。
              </p>
            </div>
          )}
          <div className="custom-scrollbar flex-1 space-y-5 overflow-y-auto p-5">
            {/* 基本配置 */}
            <fieldset className="space-y-4">
              <legend className="text-xs font-semibold uppercase tracking-wide text-ink-faint">基本配置</legend>
              <Field label="任务名称" required htmlFor="task-name">
                <input
                  id="task-name"
                  type="text"
                  required
                  disabled={locked}
                  className={`${inputClass} ${lockedClass}`}
                  value={form.name}
                  onChange={(e) => setField("name", e.target.value)}
                  placeholder="例如: 每日数据汇总"
                />
              </Field>
              <Field label="Cron 表达式" required>
                <CronPicker value={form.rule} onChange={(val) => setField("rule", val)} />
              </Field>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="调度类型" htmlFor="task-type">
                  <select
                    id="task-type"
                    disabled={locked}
                    className={`${inputClass} ${lockedClass}`}
                    value={form.type}
                    onChange={(e) => setField("type", e.target.value as "command" | "callback")}
                  >
                    <option value="command">系统指令 (Command)</option>
                    <option value="callback">类回调 (Callback)</option>
                  </select>
                </Field>
                <Field label="时区" htmlFor="task-timezone">
                  <input
                    id="task-timezone"
                    type="text"
                    disabled={locked}
                    className={`${inputClass} ${lockedClass}`}
                    value={form.timezone}
                    onChange={(e) => setField("timezone", e.target.value)}
                    placeholder="Asia/Shanghai"
                  />
                </Field>
              </div>
            </fieldset>

            {/* 执行内容 */}
            <fieldset className="space-y-4">
              <legend className="text-xs font-semibold uppercase tracking-wide text-ink-faint">执行内容</legend>
              {form.type === "command" ? (
                <Field label="执行指令" required htmlFor="task-command">
                  <textarea
                    id="task-command"
                    required
                    rows={3}
                    disabled={locked}
                    className={`${monoInputClass} ${lockedClass}`}
                    value={form.command}
                    onChange={(e) => setField("command", e.target.value)}
                    placeholder="例如: crontab:run 或 python /scripts/sync.py"
                  />
                </Field>
              ) : (
                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                  <Field label="回调类名" required htmlFor="task-class">
                    <input
                      id="task-class"
                      type="text"
                      required
                      disabled={locked}
                      className={`${monoInputClass} ${lockedClass}`}
                      value={form.callbackClass}
                      onChange={(e) => setField("callbackClass", e.target.value)}
                      placeholder="\App\index\Controller\Sync"
                    />
                  </Field>
                  <Field label="回调方法" required htmlFor="task-method">
                    <input
                      id="task-method"
                      type="text"
                      required
                      disabled={locked}
                      className={`${monoInputClass} ${lockedClass}`}
                      value={form.callbackMethod}
                      onChange={(e) => setField("callbackMethod", e.target.value)}
                      placeholder="topic"
                    />
                  </Field>
                </div>
              )}
            </fieldset>

            {/* 并发与节点 */}
            <fieldset className="space-y-4">
              <legend className="text-xs font-semibold uppercase tracking-wide text-ink-faint">并发与节点</legend>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="并发策略" htmlFor="task-singleton">
                  <select
                    id="task-singleton"
                    disabled={locked}
                    className={`${inputClass} ${lockedClass}`}
                    value={form.singleton}
                    onChange={(e) => setField("singleton", Number(e.target.value))}
                  >
                    <option value={0}>允许并发</option>
                    <option value={1}>单例互斥 (防重复执行)</option>
                  </select>
                </Field>
                <Field label="节点策略" htmlFor="task-server">
                  <select
                    id="task-server"
                    disabled={locked}
                    className={`${inputClass} ${lockedClass}`}
                    value={form.on_one_server}
                    onChange={(e) => setField("on_one_server", Number(e.target.value))}
                  >
                    <option value={0}>多节点并行</option>
                    <option value={1}>仅单节点执行</option>
                  </select>
                </Field>
              </div>
              <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <Field label="互斥锁池" htmlFor="task-mutex-pool">
                  <input
                    id="task-mutex-pool"
                    type="text"
                    disabled={locked}
                    className={`${inputClass} ${lockedClass}`}
                    value={form.mutex_pool}
                    onChange={(e) => setField("mutex_pool", e.target.value)}
                    placeholder="default"
                  />
                </Field>
                <Field label="锁过期时间 (秒)" htmlFor="task-mutex-expires">
                  <input
                    id="task-mutex-expires"
                    type="number"
                    min={1}
                    disabled={locked}
                    className={`${inputClass} ${lockedClass} tnum`}
                    value={form.mutex_expires}
                    onChange={(e) => setField("mutex_expires", Number(e.target.value))}
                  />
                </Field>
              </div>
            </fieldset>

            {/* 其他 */}
            <fieldset className="space-y-4">
              <legend className="text-xs font-semibold uppercase tracking-wide text-ink-faint">其他</legend>
              <Field label="环境变量 (可选)" htmlFor="task-env">
                <textarea
                  id="task-env"
                  rows={2}
                  disabled={locked}
                  className={`${monoInputClass} ${lockedClass}`}
                  value={form.environments}
                  onChange={(e) => setField("environments", e.target.value)}
                  placeholder={"APP_ENV=prod\nSERVICE_ID=worker-01"}
                />
              </Field>
              <Field label="备注 (可选)" htmlFor="task-memo">
                <input
                  id="task-memo"
                  type="text"
                  className={inputClass}
                  value={form.memo}
                  onChange={(e) => setField("memo", e.target.value)}
                  placeholder="说明业务用途与紧急程度"
                />
              </Field>
            </fieldset>
          </div>

          <footer className="flex items-center justify-end gap-3 border-t border-line bg-inset/50 px-5 py-4">
            <button
              type="button"
              disabled={isSaving}
              onClick={onClose}
              className="h-10 rounded-xl border border-line bg-surface px-5 text-sm font-medium text-ink-soft transition-colors hover:text-ink disabled:opacity-50"
            >
              取消
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="flex h-10 min-w-28 items-center justify-center gap-2 rounded-xl bg-accent px-5 text-sm font-semibold text-white shadow-glow transition-colors hover:bg-accent-strong disabled:opacity-60"
            >
              {isSaving ? (
                <>
                  <span className="h-4 w-4 animate-spin rounded-full border-2 border-white/30 border-t-white" />
                  保存中…
                </>
              ) : (
                "保存"
              )}
            </button>
          </footer>
        </form>
      </motion.div>
    </>
  );
}
