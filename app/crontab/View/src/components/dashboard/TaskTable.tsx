import type { AnnotationTask, CrontabTask, TaskLike } from "../../types";
import type { RowAction } from "../../hooks/useTasks";
import { cn } from "../../lib/utils";
import {
  Activity,
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Code2,
  FileText,
  Import,
  Inbox,
  Plus,
  RefreshCw,
  Search,
  ServerCrash,
  Settings,
  XCircle,
} from "lucide-react";

interface TaskTableProps {
  tasks: CrontabTask[];
  annotations: AnnotationTask[];
  loading: boolean;
  error: string | null;
  keyword: string;
  statusFilter: number | null;
  page: number;
  pageSize: number;
  total: number;
  busyRows: Record<number, RowAction | undefined>;
  busyAnnotations: Record<string, boolean>;
  onKeywordChange: (keyword: string) => void;
  onStatusFilterChange: (status: number | null) => void;
  onPageChange: (page: number) => void;
  onRefresh: () => void;
  onRowClick: (task: CrontabTask) => void;
  onRun: (task: CrontabTask) => void;
  onToggle: (task: CrontabTask) => void;
  onViewLogs: (task: TaskLike) => void;
  onEdit: (task: CrontabTask) => void;
  onCreate: () => void;
  onRunAnnotation: (task: AnnotationTask) => void;
  onTakeover: (task: AnnotationTask) => void;
}

const STATUS_TABS: Array<{ label: string; value: number | null }> = [
  { label: "全部", value: null },
  { label: "启用中", value: 1 },
  { label: "已暂停", value: 0 },
];

function ActionButton({
  title,
  busy,
  onClick,
  className,
  children,
}: {
  title: string;
  busy?: boolean;
  onClick: () => void;
  className?: string;
  children: React.ReactNode;
}) {
  return (
    <button
      type="button"
      title={title}
      aria-label={title}
      disabled={busy}
      onClick={(e) => {
        e.stopPropagation();
        onClick();
      }}
      className={cn(
        "flex h-8.5 w-8.5 items-center justify-center rounded-lg text-ink-faint transition-colors hover:bg-inset disabled:cursor-not-allowed disabled:opacity-50",
        className
      )}
    >
      {busy ? (
        <span className="h-3.5 w-3.5 animate-spin rounded-full border-2 border-[rgba(16,38,38,0.15)] border-t-current" />
      ) : (
        children
      )}
    </button>
  );
}

export default function TaskTable(props: TaskTableProps) {
  const {
    tasks,
    annotations,
    loading,
    error,
    keyword,
    statusFilter,
    page,
    pageSize,
    total,
    busyRows,
    busyAnnotations,
    onKeywordChange,
    onStatusFilterChange,
    onPageChange,
    onRefresh,
    onRowClick,
    onRun,
    onToggle,
    onViewLogs,
    onEdit,
    onCreate,
    onRunAnnotation,
    onTakeover,
  } = props;

  const maxPage = Math.max(1, Math.ceil(total / pageSize));
  const rangeStart = total === 0 ? 0 : (page - 1) * pageSize + 1;
  const rangeEnd = Math.min(page * pageSize, total);
  const filtered = keyword.trim() !== "" || statusFilter !== null;
  const hasRows = tasks.length > 0 || annotations.length > 0;

  return (
    <section className="flex flex-1 flex-col overflow-hidden rounded-2xl border border-line bg-surface shadow-whisper">
      {/* 工具栏: 搜索 + 状态筛选 + 新建 */}
      <div className="flex flex-col gap-2.5 sm:gap-3 border-b border-line p-3 sm:p-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex flex-1 flex-wrap items-center gap-2 sm:gap-3">
          <div className="relative w-full sm:w-64">
            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-ink-faint" />
            <input
              type="text"
              placeholder="搜索任务名称..."
              value={keyword}
              onChange={(e) => onKeywordChange(e.target.value)}
              className="h-9.5 sm:h-10 w-full rounded-xl border border-line bg-inset pl-9 pr-3 text-xs sm:text-sm text-ink placeholder:text-ink-faint/70 transition-colors focus:border-accent focus:bg-surface focus:outline-none"
            />
          </div>
          <div className="flex rounded-xl border border-line bg-inset p-0.5" role="tablist" aria-label="状态筛选">
            {STATUS_TABS.map((tab) => (
              <button
                key={tab.label}
                type="button"
                role="tab"
                aria-selected={statusFilter === tab.value}
                onClick={() => onStatusFilterChange(tab.value)}
                className={cn(
                  "rounded-[10px] px-2.5 sm:px-3 py-1 sm:py-1.5 text-xs font-medium transition-colors",
                  statusFilter === tab.value
                    ? "bg-surface text-accent shadow-whisper"
                    : "text-ink-faint hover:text-ink-soft"
                )}
              >
                {tab.label}
              </button>
            ))}
          </div>
        </div>
        <button
          type="button"
          onClick={onCreate}
          className="flex h-9.5 sm:h-10 w-full sm:w-auto shrink-0 items-center justify-center gap-1.5 sm:gap-2 rounded-xl bg-accent px-4 text-xs sm:text-sm font-semibold text-white shadow-glow transition-colors hover:bg-accent-strong"
        >
          <Plus className="h-4 w-4" />
          新建任务
        </button>
      </div>

      {/* 表格容器 (搭载首尾列冻结与细滚动条) */}
      <div className="custom-scrollbar flex-1 overflow-auto">
        <table className="w-full min-w-[940px] text-left text-sm border-separate border-spacing-0">
          <thead>
            <tr className="bg-surface text-xs text-ink-faint">
              {/* Sticky Left: ID 列 */}
              <th className="sticky left-0 z-20 bg-surface px-4 py-3 font-medium whitespace-nowrap shadow-[1px_0_0_0_var(--color-line)] border-b border-line">
                ID
              </th>
              <th className="whitespace-nowrap px-4 py-3 font-medium border-b border-line">
                任务名称
              </th>
              <th className="whitespace-nowrap px-4 py-3 font-medium border-b border-line">
                Cron 规则
              </th>
              <th className="whitespace-nowrap px-4 py-3 font-medium border-b border-line">
                类型
              </th>
              <th className="whitespace-nowrap px-4 py-3 font-medium border-b border-line">
                执行目标
              </th>
              <th className="whitespace-nowrap px-4 py-3 font-medium border-b border-line">
                备注说明
              </th>
              <th className="whitespace-nowrap px-4 py-3 font-medium border-b border-line">
                状态
              </th>
              {/* Sticky Right: 操作列 */}
              <th className="sticky right-0 z-20 bg-surface px-4 py-3 text-right font-medium whitespace-nowrap shadow-[-1px_0_0_0_var(--color-line)] border-b border-line">
                操作
              </th>
            </tr>
          </thead>
          <tbody>
            {loading &&
              Array.from({ length: 6 }, (_, i) => (
                <tr key={`skeleton-${i}`} className="h-[52px]">
                  <td colSpan={8} className="px-4 py-3 border-b border-line/50">
                    <div className="h-5 w-full animate-pulse rounded bg-inset" />
                  </td>
                </tr>
              ))}

            {/* 1. 数据库配置的定时任务列表 */}
            {!loading &&
              tasks.map((task) => {
                const busy = busyRows[task.id];
                return (
                  <tr
                    key={task.id}
                    onClick={() => onRowClick(task)}
                    className="group h-[52px] cursor-pointer transition-colors hover:bg-inset/60"
                  >
                    {/* Sticky Left: ID Cell */}
                    <td className="sticky left-0 z-10 bg-surface group-hover:bg-inset/70 tnum whitespace-nowrap px-4 font-mono text-xs text-ink-faint shadow-[1px_0_0_0_var(--color-line)] border-b border-line/60 transition-colors">
                      {task.id}
                    </td>

                    {/* 任务名称 */}
                    <td className="max-w-[200px] truncate whitespace-nowrap px-4 font-medium text-ink border-b border-line/60" title={task.name}>
                      <span className="flex items-center gap-1.5">
                        <span className="truncate">{task.name}</span>
                        {task.is_annotation === 1 && (
                          <span className="inline-flex shrink-0 items-center gap-1 rounded-md border border-accent/20 bg-accent-soft px-1.5 py-0.5 text-[10px] font-medium text-accent">
                            <Code2 className="h-3 w-3" />
                            注解·已接管
                          </span>
                        )}
                      </span>
                    </td>

                    {/* Cron 规则 */}
                    <td className="whitespace-nowrap px-4 border-b border-line/60">
                      <span className="rounded-md border border-line bg-inset px-2 py-0.5 font-mono text-xs text-ink-soft">
                        {task.rule}
                      </span>
                    </td>

                    {/* 类型 */}
                    <td className="whitespace-nowrap px-4 border-b border-line/60">
                      {task.type === "callback" ? (
                        <span className="rounded-md bg-clay-soft px-2 py-0.5 text-xs font-medium text-clay">类回调</span>
                      ) : (
                        <span className="rounded-md bg-inset px-2 py-0.5 text-xs font-medium text-ink-soft">指令</span>
                      )}
                    </td>

                    {/* 执行目标 */}
                    <td
                      className="max-w-[180px] sm:max-w-[220px] truncate whitespace-nowrap px-4 font-mono text-xs text-ink-faint border-b border-line/60"
                      title={task.callback}
                    >
                      {task.callback}
                    </td>

                    {/* 备注字段 (超出省略显示) */}
                    <td
                      className="max-w-[150px] sm:max-w-[200px] truncate whitespace-nowrap px-4 text-xs text-ink-faint border-b border-line/60"
                      title={task.memo || "—"}
                    >
                      {task.memo || "—"}
                    </td>

                    {/* 状态 */}
                    <td className="whitespace-nowrap px-4 border-b border-line/60">
                      {task.status === 1 ? (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-up-soft px-2.5 py-1 text-xs font-medium text-up">
                          <span className="h-1.5 w-1.5 rounded-full bg-up" />
                          启用中
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-inset px-2.5 py-1 text-xs font-medium text-ink-faint">
                          <span className="h-1.5 w-1.5 rounded-full bg-ink-faint/50" />
                          已暂停
                        </span>
                      )}
                    </td>

                    {/* Sticky Right: Action Cell */}
                    <td className="sticky right-0 z-10 bg-surface group-hover:bg-inset/70 whitespace-nowrap px-4 text-right shadow-[-1px_0_0_0_var(--color-line)] border-b border-line/60 transition-colors">
                      <div className="flex items-center justify-end gap-0.5 opacity-85 transition-opacity group-hover:opacity-100">
                        <ActionButton title="立即执行" busy={busy === "run"} onClick={() => onRun(task)} className="hover:bg-accent-soft hover:text-accent">
                          <Activity className="h-4 w-4" />
                        </ActionButton>
                        <ActionButton title="查看日志" onClick={() => onViewLogs(task)} className="hover:bg-accent-soft hover:text-accent">
                          <FileText className="h-4 w-4" />
                        </ActionButton>
                        <ActionButton
                          title={task.status === 1 ? "暂停" : "启用"}
                          busy={busy === "toggle"}
                          onClick={() => onToggle(task)}
                          className={task.status === 1 ? "hover:bg-down-soft hover:text-down" : "hover:bg-up-soft hover:text-up"}
                        >
                          {task.status === 1 ? <XCircle className="h-4 w-4" /> : <CheckCircle2 className="h-4 w-4" />}
                        </ActionButton>
                        <ActionButton title="编辑配置" onClick={() => onEdit(task)} className="hover:bg-accent-soft hover:text-accent">
                          <Settings className="h-4 w-4" />
                        </ActionButton>
                      </div>
                    </td>
                  </tr>
                );
              })}

            {/* 2. 注解发现的任务列表 */}
            {!loading &&
              annotations.map((task) => {
                const busy = busyAnnotations[task.name];
                const runnable = task.type === "callback" || task.type === "command";
                return (
                  <tr key={`annotation-${task.name}`} className="group h-[52px] transition-colors hover:bg-inset/60">
                    {/* Sticky Left: ID Cell */}
                    <td className="sticky left-0 z-10 bg-surface group-hover:bg-inset/70 whitespace-nowrap px-4 shadow-[1px_0_0_0_var(--color-line)] border-b border-line/60 transition-colors">
                      <Code2 className="h-3.5 w-3.5 text-warn" aria-label="注解任务" />
                    </td>

                    {/* 任务名称 */}
                    <td className="max-w-[200px] truncate whitespace-nowrap px-4 font-medium text-ink border-b border-line/60" title={task.origin || task.name}>
                      <span className="flex items-center gap-1.5">
                        <span className="truncate">{task.name}</span>
                        <span className="inline-flex shrink-0 items-center rounded-md border border-warn/20 bg-warn-soft px-1.5 py-0.5 text-[10px] font-medium text-warn">
                          注解
                        </span>
                      </span>
                    </td>

                    {/* Cron 规则 */}
                    <td className="whitespace-nowrap px-4 border-b border-line/60">
                      <span className="rounded-md border border-line bg-inset px-2 py-0.5 font-mono text-xs text-ink-soft">
                        {task.rule}
                      </span>
                    </td>

                    {/* 类型 */}
                    <td className="whitespace-nowrap px-4 border-b border-line/60">
                      {task.type === "callback" ? (
                        <span className="rounded-md bg-clay-soft px-2 py-0.5 text-xs font-medium text-clay">类回调</span>
                      ) : (
                        <span className="rounded-md bg-inset px-2 py-0.5 text-xs font-medium text-ink-soft">指令</span>
                      )}
                    </td>

                    {/* 执行目标 */}
                    <td
                      className="max-w-[180px] sm:max-w-[220px] truncate whitespace-nowrap px-4 font-mono text-xs text-ink-faint border-b border-line/60"
                      title={task.origin || task.callback}
                    >
                      {task.callback}
                    </td>

                    {/* 备注字段 (超出省略显示) */}
                    <td
                      className="max-w-[150px] sm:max-w-[200px] truncate whitespace-nowrap px-4 text-xs text-ink-faint border-b border-line/60"
                      title={task.memo || (task.origin ? `源码: ${task.origin}` : "—")}
                    >
                      {task.memo || (task.origin ? `${task.origin}` : "—")}
                    </td>

                    {/* 状态 */}
                    <td className="whitespace-nowrap px-4 border-b border-line/60">
                      {task.status === 1 ? (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-up-soft px-2.5 py-1 text-xs font-medium text-up">
                          <span className="h-1.5 w-1.5 rounded-full bg-up" />
                          随代码运行
                        </span>
                      ) : (
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-inset px-2.5 py-1 text-xs font-medium text-ink-faint">
                          <span className="h-1.5 w-1.5 rounded-full bg-ink-faint/50" />
                          代码中禁用
                        </span>
                      )}
                    </td>

                    {/* Sticky Right: Action Cell */}
                    <td className="sticky right-0 z-10 bg-surface group-hover:bg-inset/70 whitespace-nowrap px-4 text-right shadow-[-1px_0_0_0_var(--color-line)] border-b border-line/60 transition-colors">
                      <div className="flex items-center justify-end gap-0.5 opacity-85 transition-opacity group-hover:opacity-100">
                        {runnable && (
                          <ActionButton
                            title="立即执行"
                            busy={busy}
                            onClick={() => onRunAnnotation(task)}
                            className="hover:bg-accent-soft hover:text-accent"
                          >
                            <Activity className="h-4 w-4" />
                          </ActionButton>
                        )}
                        <ActionButton title="查看日志" onClick={() => onViewLogs(task)} className="hover:bg-accent-soft hover:text-accent">
                          <FileText className="h-4 w-4" />
                        </ActionButton>
                        <ActionButton
                          title="接管后可启停与修改表达式, 删除接管记录即恢复代码默认"
                          busy={busy}
                          onClick={() => onTakeover(task)}
                          className="hover:bg-warn-soft hover:text-warn"
                        >
                          <Import className="h-4 w-4" />
                        </ActionButton>
                      </div>
                    </td>
                  </tr>
                );
              })}
          </tbody>
        </table>

        {/* 错误态 */}
        {!loading && error && (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
            <ServerCrash className="h-10 w-10 text-ink-faint/50" />
            <p className="text-sm text-ink-soft">任务列表加载失败</p>
            <p className="max-w-sm text-xs text-ink-faint">{error}</p>
            <button
              type="button"
              onClick={onRefresh}
              className="mt-1 rounded-xl border border-line bg-surface px-4 py-2 text-xs font-medium text-ink-soft transition-colors hover:border-accent hover:text-accent"
            >
              重试
            </button>
          </div>
        )}

        {/* 空态 */}
        {!loading && !error && !hasRows && (
          <div className="flex flex-col items-center justify-center gap-3 py-16 text-center">
            <Inbox className="h-10 w-10 text-ink-faint/50" />
            {filtered ? (
              <>
                <p className="text-sm text-ink-soft">没有匹配的任务</p>
                <p className="text-xs text-ink-faint">换个关键词或清除筛选条件再试</p>
              </>
            ) : (
              <>
                <p className="text-sm text-ink-soft">还没有定时任务</p>
                <p className="text-xs text-ink-faint">创建第一个任务,让调度器接管重复工作</p>
                <button
                  type="button"
                  onClick={onCreate}
                  className="mt-1 flex items-center gap-2 rounded-xl bg-accent px-4 py-2 text-xs font-semibold text-white transition-colors hover:bg-accent-strong"
                >
                  <Plus className="h-3.5 w-3.5" />
                  新建任务
                </button>
              </>
            )}
          </div>
        )}
      </div>

      {/* 分页 */}
      <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-4 py-3">
        <p className="tnum text-xs text-ink-faint">
          共 {total} 条 · 第 {rangeStart}–{rangeEnd} 条
        </p>
        <div className="flex items-center gap-1">
          <button
            type="button"
            onClick={onRefresh}
            title="刷新列表"
            aria-label="刷新列表"
            className="mr-1 flex h-8 w-8 items-center justify-center rounded-lg text-ink-faint transition-colors hover:bg-inset hover:text-accent"
          >
            <RefreshCw className={cn("h-3.5 w-3.5", loading && "animate-spin")} />
          </button>
          <button
            type="button"
            disabled={page <= 1}
            onClick={() => onPageChange(page - 1)}
            aria-label="上一页"
            className="flex h-8 w-8 items-center justify-center rounded-lg border border-line text-ink-faint transition-colors hover:text-accent disabled:cursor-not-allowed disabled:opacity-40"
          >
            <ChevronLeft className="h-4 w-4" />
          </button>
          <span className="tnum px-2 text-xs text-ink-soft">
            {page} / {maxPage}
          </span>
          <button
            type="button"
            disabled={page >= maxPage}
            onClick={() => onPageChange(page + 1)}
            aria-label="下一页"
            className="flex h-8 w-8 items-center justify-center rounded-lg border border-line text-ink-faint transition-colors hover:text-accent disabled:cursor-not-allowed disabled:opacity-40"
          >
            <ChevronRight className="h-4 w-4" />
          </button>
        </div>
      </div>
    </section>
  );
}
