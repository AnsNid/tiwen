import { Activity, Code2, ListChecks, PauseCircle } from "lucide-react";
import type { TaskStats } from "../../types";

/** KPI 统计卡: 图标芯片 + 标签 + 等宽大数字 (小屏幕与大屏统一一行4列极简排版, 为下方表格留出最大空间) */
export default function StatsCards({ stats }: { stats: TaskStats }) {
  return (
    <div className="grid grid-cols-4 gap-1.5 sm:gap-3 lg:gap-4">
      {/* 全部任务 */}
      <div className="flex flex-col sm:flex-row sm:items-center gap-1.5 sm:gap-3 rounded-xl sm:rounded-2xl border border-line bg-surface p-2 sm:p-4 shadow-whisper">
        <span className="flex h-6 w-6 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-lg sm:rounded-xl bg-accent-soft text-accent">
          <Activity className="h-3.5 w-3.5 sm:h-5 sm:w-5" />
        </span>
        <div className="min-w-0">
          <p className="text-[10px] sm:text-xs font-medium text-ink-faint truncate">全部任务</p>
          <p className="tnum mt-0.5 text-sm sm:text-xl lg:text-2xl font-bold tracking-tight text-ink">
            {stats.total}
          </p>
        </div>
      </div>

      {/* 启用中 */}
      <div className="flex flex-col sm:flex-row sm:items-center gap-1.5 sm:gap-3 rounded-xl sm:rounded-2xl border border-line bg-surface p-2 sm:p-4 shadow-whisper">
        <span className="flex h-6 w-6 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-lg sm:rounded-xl bg-up-soft text-up">
          <ListChecks className="h-3.5 w-3.5 sm:h-5 sm:w-5" />
        </span>
        <div className="min-w-0">
          <p className="text-[10px] sm:text-xs font-medium text-ink-faint truncate">启用中</p>
          <p className="tnum mt-0.5 text-sm sm:text-xl lg:text-2xl font-bold tracking-tight text-ink">
            {stats.active}
          </p>
        </div>
      </div>

      {/* 已暂停 */}
      <div className="flex flex-col sm:flex-row sm:items-center gap-1.5 sm:gap-3 rounded-xl sm:rounded-2xl border border-line bg-surface p-2 sm:p-4 shadow-whisper">
        <span className="flex h-6 w-6 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-xl bg-inset text-ink-faint">
          <PauseCircle className="h-3.5 w-3.5 sm:h-5 sm:w-5" />
        </span>
        <div className="min-w-0">
          <p className="text-[10px] sm:text-xs font-medium text-ink-faint truncate">已暂停</p>
          <p className="tnum mt-0.5 text-sm sm:text-xl lg:text-2xl font-bold tracking-tight text-ink">
            {stats.paused}
          </p>
        </div>
      </div>

      {/* 注解任务 */}
      <div className="flex flex-col sm:flex-row sm:items-center gap-1.5 sm:gap-3 rounded-xl sm:rounded-2xl border border-line bg-surface p-2 sm:p-4 shadow-whisper">
        <span className="flex h-6 w-6 sm:h-10 sm:w-10 shrink-0 items-center justify-center rounded-lg sm:rounded-xl bg-warn-soft text-warn">
          <Code2 className="h-3.5 w-3.5 sm:h-5 sm:w-5" />
        </span>
        <div className="min-w-0">
          <p className="text-[10px] sm:text-xs font-medium text-ink-faint truncate">注解任务</p>
          <p className="tnum mt-0.5 text-sm sm:text-xl lg:text-2xl font-bold tracking-tight text-ink">
            {stats.annotation ?? 0}
          </p>
        </div>
      </div>
    </div>
  );
}
