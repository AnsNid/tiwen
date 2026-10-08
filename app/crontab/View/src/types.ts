// 全局共享类型定义

export interface CrontabTask {
  id: number;
  name: string;
  type: string; // 'command' | 'callback'
  rule: string;
  callback: string;
  memo: string;
  singleton: number; // 1: 单例执行
  mutex_pool: string;
  mutex_expires: number;
  on_one_server: number; // 1: 仅单节点执行
  timezone: string;
  environments: string;
  status: number; // 1: 启用, 0: 暂停
  updated_at: string;
  is_annotation?: number; // 1: 该行接管了同名注解任务
}

/** 代码 #[Crontab] 注解定义的任务, 只读展示, 接管后转为同名数据库任务 */
export interface AnnotationTask extends Omit<CrontabTask, "id" | "updated_at"> {
  id: 0;
  updated_at?: string;
  source: "annotation";
  origin: string; // 类名::方法
  overridden: boolean; // 是否已被同名数据库行接管
}

export type TaskLike = CrontabTask | AnnotationTask;

export interface User {
  uid: number;
  username: string;
  nickname: string;
  mobile: string;
  email: string;
  groupid: number;
  avatar: string;
  permissions: string[];
}

export interface SiteConfig {
  site_name: string;
  site_url: string;
  site_logo: string;
  site_favicon: string;
}

export interface TaskStats {
  total: number;
  active: number;
  paused: number;
  annotation?: number; // 未被接管的注解任务数
}

export interface TaskListResult {
  list: CrontabTask[];
  annotations?: AnnotationTask[];
  total: number;
  page: number;
  limit: number;
  stats: TaskStats;
}

export interface TaskLogResult {
  name: string;
  lines: string[];
  total: number;
}

export interface LoginResult {
  token: string;
  user: User;
}
