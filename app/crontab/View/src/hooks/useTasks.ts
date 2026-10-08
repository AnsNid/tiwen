import { useCallback, useEffect, useState } from "react";
import toast from "react-hot-toast";
import { apiGet, apiPost } from "../lib/api";
import type { AnnotationTask, CrontabTask, TaskListResult, TaskStats } from "../types";

const PAGE_SIZE = 20;
const EMPTY_STATS: TaskStats = { total: 0, active: 0, paused: 0 };

/** 行级进行中的操作,用于按钮防连点与局部 loading */
export type RowAction = "toggle" | "run";

export interface UseTasksResult {
  tasks: CrontabTask[];
  annotations: AnnotationTask[];
  stats: TaskStats;
  total: number;
  page: number;
  pageSize: number;
  loading: boolean;
  error: string | null;
  statusFilter: number | null;
  keyword: string;
  busyRows: Record<number, RowAction | undefined>;
  busyAnnotations: Record<string, boolean>;
  setStatusFilter: (status: number | null) => void;
  setKeyword: (keyword: string) => void;
  goToPage: (page: number) => void;
  refresh: () => void;
  toggleStatus: (task: CrontabTask) => Promise<void>;
  runTask: (task: CrontabTask) => Promise<void>;
  deleteTask: (task: CrontabTask) => Promise<void>;
  runAnnotationTask: (task: AnnotationTask) => Promise<void>;
  takeoverTask: (task: AnnotationTask) => Promise<void>;
}

export function useTasks(): UseTasksResult {
  const [tasks, setTasks] = useState<CrontabTask[]>([]);
  const [annotations, setAnnotations] = useState<AnnotationTask[]>([]);
  const [stats, setStats] = useState<TaskStats>(EMPTY_STATS);
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [statusFilter, setStatusFilterState] = useState<number | null>(null);
  const [keyword, setKeywordState] = useState("");
  const [debouncedKeyword, setDebouncedKeyword] = useState("");
  const [busyRows, setBusyRows] = useState<Record<number, RowAction | undefined>>({});
  const [busyAnnotations, setBusyAnnotations] = useState<Record<string, boolean>>({});
  const [reloadFlag, setReloadFlag] = useState(0);

  // 搜索关键词防抖:输入停顿 400ms 后才发起请求,并回到第一页
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedKeyword(keyword);
      setPage(1);
    }, 400);
    return () => clearTimeout(timer);
  }, [keyword]);

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError(null);
    apiGet<TaskListResult>("/getTaskList", {
      page,
      limit: PAGE_SIZE,
      ...(statusFilter !== null ? { status: statusFilter } : {}),
      ...(debouncedKeyword ? { keyword: debouncedKeyword } : {}),
    })
      .then((result) => {
        if (cancelled) return;
        setTasks(result.list ?? []);
        setAnnotations(result.annotations ?? []);
        setTotal(result.total ?? 0);
        if (result.stats) setStats(result.stats);
      })
      .catch((err) => {
        if (!cancelled) setError(err instanceof Error ? err.message : "任务列表加载失败");
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => {
      cancelled = true;
    };
  }, [page, statusFilter, debouncedKeyword, reloadFlag]);

  const markRow = (id: number, action: RowAction | undefined, prev: Record<number, RowAction | undefined>) => {
    const next = { ...prev };
    if (action) {
      next[id] = action;
    } else {
      delete next[id];
    }
    return next;
  };

  const toggleStatus = useCallback(async (task: CrontabTask) => {
    const nextStatus = task.status === 1 ? 0 : 1;
    setBusyRows((prev) => markRow(task.id, "toggle", prev));
    try {
      await apiPost("/toggleStatus", { id: task.id, status: nextStatus });
      setTasks((prev) => prev.map((t) => (t.id === task.id ? { ...t, status: nextStatus } : t)));
      setStats((prev) => ({
        total: prev.total,
        active: prev.active + (nextStatus === 1 ? 1 : -1),
        paused: prev.paused + (nextStatus === 0 ? 1 : -1),
      }));
      toast.success(nextStatus === 1 ? "任务已启用" : "任务已暂停");
    } catch {
      // 拦截器已提示
    } finally {
      setBusyRows((prev) => markRow(task.id, undefined, prev));
    }
  }, []);

  const runTask = useCallback(async (task: CrontabTask) => {
    setBusyRows((prev) => markRow(task.id, "run", prev));
    try {
      await apiPost("/runTask", { id: task.id });
      toast.success("执行指令已下发");
    } catch {
      // 拦截器已提示
    } finally {
      setBusyRows((prev) => markRow(task.id, undefined, prev));
    }
  }, []);

  const deleteTask = useCallback(async (task: CrontabTask) => {
    await apiPost("/deleteTask", { id: task.id });
    toast.success("任务已删除");
    setReloadFlag((n) => n + 1);
  }, []);

  const markAnnotation = (name: string, busy: boolean, prev: Record<string, boolean>) => {
    const next = { ...prev };
    if (busy) {
      next[name] = true;
    } else {
      delete next[name];
    }
    return next;
  };

  const runAnnotationTask = useCallback(async (task: AnnotationTask) => {
    setBusyAnnotations((prev) => markAnnotation(task.name, true, prev));
    try {
      await apiPost("/runTask", { source: "annotation", name: task.name });
      toast.success("执行指令已下发");
    } catch {
      // 拦截器已提示
    } finally {
      setBusyAnnotations((prev) => markAnnotation(task.name, false, prev));
    }
  }, []);

  const takeoverTask = useCallback(async (task: AnnotationTask) => {
    setBusyAnnotations((prev) => markAnnotation(task.name, true, prev));
    try {
      await apiPost("/takeoverTask", { name: task.name });
      toast.success("已接管, 可启停或修改表达式");
      setReloadFlag((n) => n + 1);
    } catch {
      // 拦截器已提示
    } finally {
      setBusyAnnotations((prev) => markAnnotation(task.name, false, prev));
    }
  }, []);

  const setStatusFilter = useCallback((status: number | null) => {
    setStatusFilterState(status);
    setPage(1);
  }, []);

  const goToPage = useCallback(
    (next: number) => {
      const maxPage = Math.max(1, Math.ceil(total / PAGE_SIZE));
      setPage(Math.min(Math.max(next, 1), maxPage));
    },
    [total]
  );

  return {
    tasks,
    annotations,
    stats,
    total,
    page,
    pageSize: PAGE_SIZE,
    loading,
    error,
    statusFilter,
    keyword,
    busyRows,
    busyAnnotations,
    setStatusFilter,
    setKeyword: setKeywordState,
    goToPage,
    refresh: useCallback(() => setReloadFlag((n) => n + 1), []),
    toggleStatus,
    runTask,
    deleteTask,
    runAnnotationTask,
    takeoverTask,
  };
}
