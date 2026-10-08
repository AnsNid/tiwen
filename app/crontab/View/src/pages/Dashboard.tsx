import { useState } from "react";
import toast from "react-hot-toast";
import { AnimatePresence, motion } from "motion/react";
import { Activity, LogOut, Trash2 } from "lucide-react";
import { useAuth } from "../contexts/AuthContext";
import { useConfig } from "../contexts/ConfigContext";
import { isInIframe } from "../lib/utils";
import { useTasks } from "../hooks/useTasks";
import StatsCards from "../components/dashboard/StatsCards";
import TaskTable from "../components/dashboard/TaskTable";
import TaskDrawer from "../components/dashboard/TaskDrawer";
import TaskFormModal from "../components/dashboard/TaskFormModal";
import LogModal from "../components/dashboard/LogModal";
import type { CrontabTask, TaskLike } from "../types";

function DeleteConfirm({
  task,
  busy,
  onCancel,
  onConfirm,
}: {
  task: CrontabTask;
  busy: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <>
      <motion.div
        initial={{ opacity: 0 }}
        animate={{ opacity: 1 }}
        exit={{ opacity: 0 }}
        onClick={onCancel}
        className="fixed inset-0 z-[80] bg-ink/25"
      />
      <motion.div
        initial={{ opacity: 0, scale: 0.96 }}
        animate={{ opacity: 1, scale: 1 }}
        exit={{ opacity: 0, scale: 0.96 }}
        transition={{ duration: 0.14 }}
        role="alertdialog"
        aria-label="确认删除"
        className="fixed left-1/2 top-1/2 z-[90] w-[calc(100%-2rem)] max-w-sm -translate-x-1/2 -translate-y-1/2 rounded-2xl border border-line bg-surface p-6 text-center shadow-float"
      >
        <span className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-down-soft text-down">
          <Trash2 className="h-6 w-6" />
        </span>
        <h3 className="mb-2 text-base font-semibold text-ink">确认删除任务？</h3>
        <p className="mb-1 text-sm text-ink-soft">
          即将删除 <span className="font-mono font-medium text-ink">{task.name}</span>
        </p>
        <p className="mb-6 text-xs leading-relaxed text-ink-faint">
          {task.is_annotation === 1
            ? "该任务接管自代码注解, 删除后将恢复按代码 #[Crontab] 定义执行, 此操作无法撤销。"
            : "该任务配置将被永久移除,已产生的日志文件不受影响,此操作无法撤销。"}
        </p>
        <div className="flex gap-3">
          <button
            type="button"
            disabled={busy}
            onClick={onCancel}
            className="h-10 flex-1 rounded-xl border border-line bg-surface text-sm font-medium text-ink-soft transition-colors hover:text-ink disabled:opacity-50"
          >
            取消
          </button>
          <button
            type="button"
            disabled={busy}
            onClick={onConfirm}
            className="h-10 flex-1 rounded-xl bg-down text-sm font-semibold text-white transition-colors hover:bg-down/90 disabled:opacity-60"
          >
            {busy ? "删除中…" : "删除"}
          </button>
        </div>
      </motion.div>
    </>
  );
}

export default function Dashboard() {
  const { user, logout } = useAuth();
  const { config } = useConfig();
  const tasks = useTasks();
  // 被父窗口 iframe 嵌入时整个顶部导航都不渲染, 由外部提供框架与身份
  const embedded = isInIframe();

  const [drawerTask, setDrawerTask] = useState<CrontabTask | null>(null);
  const [formTask, setFormTask] = useState<CrontabTask | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [logTask, setLogTask] = useState<TaskLike | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<CrontabTask | null>(null);
  const [deleting, setDeleting] = useState(false);

  const openCreateForm = () => {
    setFormTask(null);
    setFormOpen(true);
  };

  const openEditForm = (task: CrontabTask) => {
    setDrawerTask(null);
    setFormTask(task);
    setFormOpen(true);
  };

  const confirmDelete = async () => {
    if (!deleteTarget) return;
    setDeleting(true);
    try {
      await tasks.deleteTask(deleteTarget);
      setDrawerTask(null);
      setDeleteTarget(null);
    } catch {
      // 拦截器已提示
    } finally {
      setDeleting(false);
    }
  };

  const handleLogout = () => {
    logout();
    toast.success("已退出登录");
  };

  return (
    <div className="flex min-h-screen flex-col gap-4 p-4">
      {/* 顶部导航; 被 admin 桌面等父窗口 iframe 嵌入时整体隐藏, 由外部提供框架与身份 */}
      {!embedded && (
        <header className="flex items-center justify-between rounded-2xl border border-line bg-surface px-3.5 sm:px-4 py-2.5 sm:py-3 shadow-whisper">
          <div className="flex items-center gap-2.5 sm:gap-3">
            <div className="flex h-9 w-9 sm:h-10 sm:w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-accent text-white shadow-glow">
              {config?.site_logo ? (
                <img src={config.site_logo} alt="Logo" className="h-full w-full object-contain" />
              ) : (
                <Activity className="h-4.5 w-4.5 sm:h-5 sm:w-5" />
              )}
            </div>
            <div>
              <h1 className="text-sm sm:text-base font-bold leading-tight text-ink">
                {config?.site_name || "Crontab 调度中心"}
              </h1>
              <p className="mt-0.5 flex items-center gap-1.5 text-[11px] sm:text-xs text-ink-faint">
                <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-up" />
                调度服务运行中
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2 sm:gap-3">
            <span className="hidden text-xs sm:text-sm font-medium text-ink-soft sm:block">
              {user?.nickname || user?.username}
            </span>
            <button
              type="button"
              onClick={handleLogout}
              className="flex h-8.5 sm:h-9.5 items-center gap-1.5 rounded-xl border border-line bg-surface px-2.5 sm:px-3 text-xs font-medium text-ink-faint transition-colors hover:border-down hover:bg-down-soft hover:text-down"
            >
              <LogOut className="h-3.5 w-3.5" />
              <span>退出</span>
            </button>
          </div>
        </header>
      )}

      <main className="flex flex-1 flex-col gap-3.5 sm:gap-4">
        <StatsCards stats={tasks.stats} />
        <TaskTable
          tasks={tasks.tasks}
          annotations={tasks.annotations}
          loading={tasks.loading}
          error={tasks.error}
          keyword={tasks.keyword}
          statusFilter={tasks.statusFilter}
          page={tasks.page}
          pageSize={tasks.pageSize}
          total={tasks.total}
          busyRows={tasks.busyRows}
          busyAnnotations={tasks.busyAnnotations}
          onKeywordChange={tasks.setKeyword}
          onStatusFilterChange={tasks.setStatusFilter}
          onPageChange={tasks.goToPage}
          onRefresh={tasks.refresh}
          onRowClick={setDrawerTask}
          onRun={tasks.runTask}
          onToggle={tasks.toggleStatus}
          onViewLogs={setLogTask}
          onEdit={openEditForm}
          onCreate={openCreateForm}
          onRunAnnotation={tasks.runAnnotationTask}
          onTakeover={tasks.takeoverTask}
        />
        <footer className="flex items-center justify-between px-1 text-xs text-ink-faint">
          <span>{config?.site_name || "Crontab 调度中心"}</span>
          <span className="tnum">共 {tasks.stats.total} 个任务</span>
        </footer>
      </main>

      <AnimatePresence>
        {drawerTask && (
          <TaskDrawer
            key="drawer"
            task={drawerTask}
            onClose={() => setDrawerTask(null)}
            onViewLogs={setLogTask}
            onRun={tasks.runTask}
            onEdit={openEditForm}
            onDelete={setDeleteTarget}
          />
        )}
      </AnimatePresence>

      <AnimatePresence>
        {logTask && <LogModal key="logs" task={logTask} onClose={() => setLogTask(null)} />}
      </AnimatePresence>

      <AnimatePresence>
        {formOpen && (
          <TaskFormModal
            key="form"
            task={formTask}
            onClose={() => setFormOpen(false)}
            onSaved={tasks.refresh}
          />
        )}
      </AnimatePresence>

      <AnimatePresence>
        {deleteTarget && (
          <DeleteConfirm
            key="delete"
            task={deleteTarget}
            busy={deleting}
            onCancel={() => setDeleteTarget(null)}
            onConfirm={confirmDelete}
          />
        )}
      </AnimatePresence>
    </div>
  );
}
