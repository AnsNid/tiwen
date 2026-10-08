# Crontab 调度管理中心 (Crontab Management Center)

本项目是一个基于 React + Vite + Tailwind CSS 开发的 Crontab 任务调度管理后台。它提供了直观的界面来创建、编辑、监控和执行定时任务。

## 项目特点

- **任务监控**：查看任务运行状态、执行日志（分页加载、按级别着色）。
- **灵活调度**：支持标准 Crontab 表达式配置，内置常用预设。
- **任务管理**：支持系统指令 (Command) 及类回调 (Callback) 两种类型。
- **列表能力**：服务端分页、状态筛选、名称关键词搜索（防抖）。
- **安全可靠**：内置角色权限验证及单例执行 (Mutex) 策略。
- **设计体系**：石墨 + 青碧双色调设计令牌（Tailwind v4 `@theme`），浅色主题。

## 技术栈

- **Frontend**: React 19, Vite, TypeScript
- **Styling**: Tailwind CSS v4 (design tokens), Lucide Icons, Motion (Animations)
- **API**: Axios with interceptors（统一拆包与错误提示）
- **Notifications**: React Hot Toast

## 快速开始

### 安装依赖

在 `View/` 目录下运行：

```bash
pnpm install   # 或 npm install
```

### 本地开发

```bash
pnpm dev
```

项目默认运行在 [http://localhost:3000](http://localhost:3000)。

### 构建产物

```bash
pnpm build
```

构建输出到 `dist/`，资源为相对路径（`./assets/...`，由 `vite.config.ts` 的 `base: './'` 保证），
可直接由后端 `GET /crontab/admin`（页面）与 `GET /crontab/assets/*`（静态资源）提供服务。

### 接口配置

API 地址在 `src/lib/api.ts` 中配置，优先读取环境变量 `VITE_API_BASE`：

- **开发环境默认**: `http://127.0.0.1:9551/crontab/api`
- **生产环境默认**: `/crontab/api`（同源相对路径）

## 功能说明

1. **登录系统**：使用具有 `crontab:admin` 权限的账号登录，支持 URL 携带 `?token=` 免登。
2. **任务列表**：服务端分页（每页 20 条）、状态筛选（全部/启用中/已暂停）、名称搜索。
3. **创建/编辑任务**：
   - 任务名称：唯一标识。
   - 类型：`command` (系统指令) 或 `callback` (类回调 `[类名, 方法名]`)。
   - 规则：标准 Crontab 表达式（5 段）。
   - 并发：单例互斥、锁池、锁过期时间、单节点执行。
   - 其他：时区、环境变量（`KEY=VALUE` 每行一个）、备注。
4. **日志查看**：查看任务运行日志，按 ERROR/WARNING 着色，支持分页加载更早记录。
5. **手动运行**：一键手动触发任务执行（保留单例/互斥锁配置）。
