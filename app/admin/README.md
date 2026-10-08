# Admin 全局后台管理应用

X-PHP 的全局管理控制台: 统一接管所有已安装应用的后台接口访问, 提供应用生命周期管理(安装/卸载/更新)、每个应用的"后台接入"开关与配置(路径/菜单/表格)、系统设置中心、通用数据表格、缓存与进程维护。

## 架构总览

```
app/admin/
├── Service/
│   ├── AuthService.php         # 身份解析 / 创始人判定 / 权限装载(user.authority × user.group)
│   ├── ApplicationService.php  # 应用生命周期统一实现(HTTP 与 CLI 共用)
│   ├── RegistryService.php     # 后台接入注册中心(静态声明 × application 表持久化配置)
│   └── TableService.php        # 声明式通用表格引擎(列/搜索/编辑白名单)
├── Middleware/
│   ├── AdminAuthMiddleware.php # 会话认证(仅认 session.adminUserData), 注入 admin 属性
│   ├── CorsMiddleware.php      # 跨域 + 首次安装引导
│   └── CorsHeaders.php         # CORS 头统一实现
├── Controller/
│   ├── Api.php                 # 中央分发器 → App\{app}\Controller\Admin\{controller}
│   ├── Index.php               # SPA 入口(config.js 动态注入 + 本地 dist 静态资源)
│   └── Admin/                  # Login/Application/Setting/Table/System/Cache/Signal/Upgrade...
├── Listener/
│   ├── BootAdminListener.php   # BootApplication 时注册全部路由 + 存量库自动迁移(admin_enable 等列)
│   └── AuthListener.php        # 登录事件: 后台准入校验 + 写入 session
├── Command/AppInstallCommand.php # php index.php app {name} [i|u|up]
└── View/                       # 新版前端(React19+Vite+TS+Tailwind4, dist 已入库)
```

### 认证授权链路

1. 登录走 `user` 应用(`app('user')->login`), 登录成功派发 `App\admin\Event\Auth`;
2. `AuthListener` 校验后台准入(创始人 UID 直通, 否则要求 system 组授权且未过期)并写 session `adminUserData`;
3. 每个请求由 `AdminAuthMiddleware` 经 `AuthService::resolve()` 解析身份(uid/username/groupid/founder/role/permissions)注入 `admin` 属性; **不再做浏览器指纹/IP 数据库补偿**, 会话与当前登录用户不一致即失效;
4. 分发器 `Api::handleRequest` 按 `admin@config.enforce_permissions` 决定是否强制 RBAC:
   - founder 或 `authority.role=super`(permissions 含 `*`)直通;
   - 未配置任何权限键的组视为向后兼容, 放行;
   - 配置了权限键的按 `app.controller.action` / `app.action` / `app.*` 严格匹配, 未命中返回 403。

### 数据表

- `#@_application`: 应用注册(status/admin_enable/admin_config 等)。存量环境启动时由 `BootAdminListener` 幂等补齐新列;
- `#@_config`: 核心设置 KV(设置中心数据源, 运行时缓存到 runtime/config/config.php, 读法 `sysget('config.xxx')`)。

## CLI 命令

```bash
php index.php app {name}              # 安装
php index.php app {name} u            # 卸载(默认保留数据表)
php index.php app {name} u -t         # 卸载并删除该应用声明的数据表(--with-tables)
php index.php app {name} up           # 更新(刷新文件+幂等补建缺失表+重写注册信息)
php index.php app {name} i -d db2     # 指定目标数据库连接
php index.php app {name} up -r        # 操作后重启服务
```

---

## 全局会员画像与编辑模态系统

控制台内置了统一的会员画像抽屉 (`UserProfileModal`) 与会员全能编辑弹窗 (`UserEditModal`)，支持跨页面、跨组件及内嵌 `iframe` 全局统一调度。

### 1. 全局会员画像抽屉 (`UserProfileModal.tsx`)

#### 核心特性
- **双层抽屉栈架构**：支持同时打开 2 个画像（主画像 `Level 1` + 稍小尺寸的二级对比画像 `Level 2`），当从二级画像中再打开新用户时，自动平滑替换二级抽屉；
- **智能防叠加遮罩 (`backdropManager`)**：多层抽屉或弹窗打开时，底层的遮罩保持柔和，上层自动转为透明遮罩，彻底杜绝背景发黑；
- **异步前置预检拦截 (Fail-Fast)**：打开前静默预拉取 `/admin/api/user/index/get`，若用户不存在（返回 `201`）直接 Toast 报错且绝不闪现弹窗；
- **1:1 Canvas 头像裁剪与上传**：内置正方形/圆形辅助线 Canvas 视口裁剪弹窗（支持缩放滑块与鼠标拖拽定位），自动生成 Blob 并安全上传到 `avatarupload` 接口；
- **动态菜单驱动 (`data.menu`)**：
  - `account`（账号信息）：加载 `/admin/api/user/data/get`（使用该用户专项 `token`），动态渲染 `basicInfo` 基础资料、`section` 自适应卡片分区与 `medals` 荣誉勋章；
  - `trace`（行为轨迹）：分页查询 `/admin/api/user/Trace/lists`，展示用户全量操作日志；
  - `may`（可能认识）：加载 `/admin/api/user/may/lists`，展示同 IP / 关联协同会员，可一键打开二级对比画像；
  - `xtable`（通用数据表格）：两阶段 Schema 引擎（先查 `detail` 取得 `columns` 配置，再查 `lists` 获取行数据并支持一键查看复杂数据 JSON 详情弹窗）。

---

### 2. 全局会员编辑弹窗 (`UserEditModal.tsx`)

#### 核心特性
- **Schema 动态表单引擎**：直接解析 `/admin/api/user/index/updateTable` 返回的 `config.column` 规则，动态生成表单；
- **支持的全量表单控件**：
  - `text`（只读文本带一键复制）
  - `input`（文本输入框，支持密码修改联动）
  - `select` / `selectgroup`（单选与分组下拉框）
  - `date` / `datetime`（日期时间选择器）
  - `switch`（布尔开关）
  - `checkboxGroup`（多选权限组，支持全选/清空/交互卡片）
  - `xuserselect`（系统组角色选择器，高度自适应 `min-h-[88px] max-h-[220px]`，支持过期时间设置）
- **动态条件联动 (`showhandle`)**：支持如 `$.groupid != -1`、`$.editpassword` 等表达式实时显示/隐藏表单项；
- **独立顶层高层级 (`z-[10050]`)**：浮动在所有抽屉最上方，保存后即时触发回调刷新底层画像。

---

### 3. 全局统计看板弹窗 (`StatModal.tsx`)

#### 核心特性
- **数据源多路驱动**：直接对接 `/admin/api/stat/admin/genericInfo`，无缝支持 `#@_stat` 数据库自动查询与事件总线 (`stat.{$type}@getData`, `stat.{$type}@getConfig`) 动态注入；
- **全屏与自适应窗口**：支持居中大弹窗与一键全屏最大化展开，配备暗黑模式极致美化与柔和遮罩；
- **零抖动交互图表**：内置多系列平滑贝塞尔曲线 SVG 图表与面积渐变，采用全列透明触控热区与图表元素事件穿透 (`pointer-events: none`)，彻底消除光标悬停抖动；
- **指标动态开关与图例联动**：支持点击图例实时隐藏/显示对应指标线，图表量程与坐标自动智能重算；
- **漏斗与明细排列表**：自动渲染业务阶段转化漏斗，支持关联实体 ID 与别名搜索、分页及单值一键复制；
- **动态组件协议引擎**：原生解析 `kpi` 卡片、`progress` 进度条、`keyvalue` 键值清单、`table` 紧凑表格与 `alert` 预警横幅。

---

### 4. 跨上下文与内嵌 iframe 调用规范

无论是控制台原生页面、子组件，还是通过 `iframe` 嵌入的第三方业务系统，均可无缝调用全局画像、编辑弹窗与统计看板。

#### 方式 A：同域直接通过 `window.parent` 调用

```javascript
// 1. 打开会员画像主抽屉
window.parent.openUserProfile({ uid: 10000 });

// 2. 作为二级对比抽屉打开（尺寸更小，叠在主抽屉上方）
window.parent.openSubUserProfile({ uid: 429527 });

// 3. 关闭画像抽屉 (传 1 仅关闭二级抽屉，不传则全部关闭)
window.parent.closeUserProfile();

// 4. 打开会员编辑弹窗 (支持传入保存成功后的回调函数)
window.parent.openUserEdit(10000, () => {
    console.log('会员信息已更新，执行刷新操作');
});

// 5. 关闭会员编辑弹窗
window.parent.closeUserEdit();

// 6. 全局打开统计分析看板 - 方式一: 使用快捷天数 (day: 7 | 14 | 30 | 90)
window.parent.openStat({
    type: 'order@订单统计,pay@支付统计',
    relatedid: 10001,
    day: 14, // 默认 7 天，可选 7, 14, 30, 90
    title: '旗舰店经营走势看板',
    description: '近14天成交订单与支付流水综合走势'
});

// 7. 全局打开统计分析看板 - 方式二: 指定精确起止时间范围 (date: [开始日期, 结束日期] 或逗号字符串)
window.parent.openStat({
    type: 'order@订单统计,pay@支付统计',
    relatedid: 10001,
    date: ['2026-08-01', '2026-08-31'], // 或 '2026-08-01,2026-08-31'，优先级高于 day
    title: '8月份经营全貌统计',
    description: '2026年8月全月结算与指标达成情况'
});

// 8. 关闭统计分析看板
window.parent.closeStat();
```

#### 方式 B：跨域 / iframe 标准 `postMessage` 通信协议

```javascript
// 1. 发送打开会员画像指令
window.parent.postMessage({
    type: 'OPEN_USER_PROFILE',
    user: { uid: 10000 }
}, '*');

// 2. 发送打开二级对比画像指令
window.parent.postMessage({
    type: 'OPEN_USER_PROFILE',
    user: { uid: 429527 },
    isSub: true
}, '*');

// 3. 发送关闭画像抽屉指令
window.parent.postMessage({
    type: 'CLOSE_USER_PROFILE'
}, '*');

// 4. 发送打开会员编辑弹窗指令
window.parent.postMessage({
    type: 'OPEN_USER_EDIT',
    uid: 10000
}, '*');

// 5. 发送打开统计分析看板指令 (支持快捷天数 day 或自定义时间范围 date)
window.parent.postMessage({
    type: 'OPEN_STAT',
    options: {
        type: 'demo',
        day: 30, // 快捷天数 (如 7, 14, 30, 90)
        // date: ['2026-09-01', '2026-09-10'], // 也可传入自定义起止日期，优先级高于 day
        title: '演示数据看板'
    }
}, '*');

// 6. 发送关闭统计分析看板指令
window.parent.postMessage({
    type: 'CLOSE_STAT'
}, '*');

// 7. 请求获取当前控制台全局鉴权 Token
window.parent.postMessage({
    type: 'GET_AUTH_TOKEN'
}, '*');

// 8. 接收控制台回复的 Token 消息
window.addEventListener('message', (event) => {
    if (event.data?.type === 'XPHP_AUTH_TOKEN_RESPONSE') {
        const { token, tokenName } = event.data;
        console.log('已成功获取当前管理员凭证:', token, 'Header 字段名:', tokenName);
    }
});
```

---

### 4. 接口协议与 Token 安全约定

| 接口路径 | 方法 | 请求参数 | 响应结构核心字段 | 说明 |
| :--- | :---: | :--- | :--- | :--- |
| `/admin/api/user/index/get` | `GET` | `uid`, `uin`, `type: 'member'` | `user`, `token`, `avatarupload`, `stat`, `menu` | 获取用户主画像与专属子接口 `token` |
| `/admin/api/user/data/get` | `POST` | `token`（必须为该用户专属 token） | `basicInfo`, `section`, `medals`, `data` | 获取账号扩展信息与勋章（切勿传管理员全局 Token） |
| `/admin/api/user/index/updateTable` | `GET` | `uid` | `config.column`, `config.url`, `data`, `token` | 获取会员编辑动态表单 Schema |
| `/admin/api/user/may/lists` | `GET` | `token` | `[ { uid, uin, username, nickname, regip, regdate, avatar } ]` | 获取可能认识的关联/同 IP 会员列表 |
| `/admin/api/user/Trace/lists` | `GET` | `uid`, `page`, `limit` | `[ { id, action, ip, url, created_at } ]` | 获取行为轨迹审计日志 |

> [!IMPORTANT]
> **Token 安全隔离原则**：
> 1. 管理员全局 Token（`x-token`）用于控制台主接口与路由鉴权；
> 2. 用户专项 Token（`userToken`）由 `/admin/api/user/index/get` 为特定会员按需生成，专门用于请求该用户的 `/admin/api/user/data/get` 等专属数据接口；
> 3. 前端各子组件严禁回退使用管理员全局 Token 查询普通会员数据，确保数据权限边界严格隔离。

---

### 5. 子级应用 iframe 获取父级 Token 规范与实现 (Token 继承)

当业务应用（如 `circle`、`stat`、`crontab`、`search`、`message`、`ads`、`weixin`、`workflow` 等）以后台内嵌 `iframe` 形式运行时，必须能够无缝继承并复用宿主 Admin 控制台的登录态，避免用户打开内嵌页时被拦截或二次弹出登录界面。

#### ① 同域直接穿透（推荐，同步即时读取）

Admin 控制台登录成功后，会将当前登录凭据统一写入以下全局位置（全局统一标准化为 `x-token`）：
* **`localStorage`**: `'x-token'`
* **`sessionStorage`**: `'x-token'`
* **`window` 全局挂载**: `window['x-token']`、`window.X_TOKEN`
* **`Cookie`**: `x-token`

子应用在初始化时，可通过以下标准表达式从 `window.parent` / `window.top` 中即时获取 Token：
```javascript
const token = window.parent.localStorage.getItem("x-token") ||
              window.parent.sessionStorage?.getItem("x-token") ||
              window.parent["x-token"] ||
              window.parent.X_TOKEN;
```

#### ② 跨域 / 沙箱隔离下的 postMessage 管道同步（异步双向保障）

当子应用与 Admin 控制台处于不同端口、子域名，或受浏览器 iframe 跨域安全策略限制导致直接读取 `window.parent.localStorage` 抛出 `SecurityError` 时，必须使用 HTML5 `postMessage` 机制进行凭证同步。

##### 1. 主动请求父级 Token（子应用 → Admin 宿主）
Admin 控制台在 `src/lib/api.ts` 中内置了全局监听器，子应用挂载初始化时可向父级窗口发送查询指令：
```javascript
// 向父级窗口广播获取 Token 指令
window.parent.postMessage({ type: 'GET_AUTH_TOKEN' }, '*');
```

##### 2. 接收宿主主动同步或响应的 Token
Admin 控制台在桌面窗口模式（`WindowManager.tsx`）下 iframe 加载完成后，以及收到上述请求时，均会向 iframe 内容窗口派发包含 `x-token` 的消息。子应用应监听并统一持久化：
```javascript
window.addEventListener('message', (event) => {
  const data = event.data;
  if (!data) return;
  const msgType = data.type || data.action;

  if (
    msgType === 'XPHP_AUTH_TOKEN_RESPONSE' ||
    msgType === 'AUTH_TOKEN_RESPONSE' ||
    msgType === 'XPHP_AUTH_TOKEN_SYNC' ||
    msgType === 'AUTH_TOKEN_SYNC'
  ) {
    const incoming = data['x-token'];
    if (incoming && typeof incoming === 'string' && incoming.trim()) {
      setSavedToken(incoming);
    }
  }
});
```

#### ③ 子应用标准 Token 解析与持久化实现

> [!IMPORTANT]
> **规范准则**：
> 1. 全局存储 key 统一为 `'x-token'`（`localStorage`、`sessionStorage`、`Cookie`）；
> 2. 登录成功时，调用 `setSavedToken(token)` 写入 `'x-token'`；
> 3. 退出登录或凭证失效（401）时，调用 `clearSavedToken()` 清除 `'x-token'`。

```typescript
function cleanToken(token: string | null | undefined): string {
  if (!token) return '';
  return String(token).replace(/^Bearer\s+/i, '').trim();
}

/**
 * 全渠道智能提取 Token (URL 参数 -> 本地存储 -> iframe 父级穿透 -> Cookie)
 */
export function resolveToken(): string {
  if (typeof window === 'undefined') return '';

  // 1. 优先读取 URL Search 或 Hash 中的 x-token
  try {
    const searchParams = new URLSearchParams(window.location.search);
    const sToken = cleanToken(searchParams.get('x-token') || searchParams.get('x_token'));
    if (sToken) return sToken;

    if (window.location.hash && window.location.hash.includes('?')) {
      const hashParams = new URLSearchParams(window.location.hash.split('?')[1]);
      const hToken = cleanToken(hashParams.get('x-token') || hashParams.get('x_token'));
      if (hToken) return hToken;
    }
  } catch {}

  // 2. 本地存储 (仅读取 x-token)
  const localToken = cleanToken(
    localStorage.getItem('x-token') ||
    sessionStorage.getItem('x-token')
  );
  if (localToken) return localToken;

  // 3. iframe 环境下直接继承父页面 (window.parent) Token
  if (window.parent && window.parent !== window) {
    try {
      const parentToken = cleanToken(
        window.parent.localStorage?.getItem('x-token') ||
        window.parent.sessionStorage?.getItem('x-token') ||
        (window.parent as any)['x-token']
      );
      if (parentToken) {
        setSavedToken(parentToken);
        return parentToken;
      }
    } catch {}
  }

  // 4. Cookie 提取
  try {
    const match = document.cookie.match(/(?:^|;\s*)x-token=([^;]+)/i);
    if (match && match[1]) {
      const cToken = cleanToken(decodeURIComponent(match[1]));
      if (cToken) return cToken;
    }
  } catch {}

  return '';
}

/**
 * 保存最新 x-token
 */
export function setSavedToken(token: string): void {
  const clean = cleanToken(token);
  if (clean) {
    localStorage.setItem('x-token', clean);
    sessionStorage.setItem('x-token', clean);
    try {
      document.cookie = `x-token=${encodeURIComponent(clean)}; path=/; max-age=604800; SameSite=Lax`;
    } catch {}
  }
}

/**
 * 清除 x-token 凭据
 */
export function clearSavedToken(): void {
  try {
    localStorage.removeItem('x-token');
    sessionStorage.removeItem('x-token');
    document.cookie = 'x-token=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
  } catch {}
}

/**
 * 跨域异步向父级窗口请求 Token (基于 HTML5 postMessage 管道)
 */
export function requestParentToken(timeoutMs = 600): Promise<string> {
  return new Promise((resolve) => {
    if (typeof window === 'undefined' || !window.parent || window.parent === window) {
      return resolve('');
    }

    const timer = setTimeout(() => {
      window.removeEventListener('message', onMsg);
      resolve('');
    }, timeoutMs);

    const onMsg = (event: MessageEvent) => {
      const data = event.data;
      if (!data) return;
      const msgType = data.type || data.action;
      if (
        msgType === 'XPHP_AUTH_TOKEN_RESPONSE' ||
        msgType === 'AUTH_TOKEN_RESPONSE' ||
        msgType === 'XPHP_AUTH_TOKEN_SYNC' ||
        msgType === 'AUTH_TOKEN_SYNC'
      ) {
        const raw = data['x-token'] || data.token || '';
        const clean = cleanToken(raw);
        if (clean) {
          clearTimeout(timer);
          window.removeEventListener('message', onMsg);
          setSavedToken(clean);
          resolve(clean);
        }
      }
    };

    window.addEventListener('message', onMsg);
    try {
      window.parent.postMessage({ type: 'GET_AUTH_TOKEN' }, '*');
    } catch {
      clearTimeout(timer);
      window.removeEventListener('message', onMsg);
      resolve('');
    }
  });
}
```

#### ④ API 请求 Header 规范

子应用向后端发起 HTTP 请求时，统一注入唯一规范的 Header 字段：

```typescript
const headers = {
  'Content-Type': 'application/json',
  'x-token': `Bearer ${token}`,
};
```

#### ⑤ 子应用 Vite 构建配置规范 (`vite.config.ts`)

子应用后台页面挂载在 `/{app}/admin` 且通常采用 Hash 路由，构建配置必须使用相对路径：
```typescript
export default defineConfig({
  // 必须使用相对路径，确保在子目录或 iframe 下静态资源定位正确 (./assets/*)
  base: './',
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
  server: {
    port: 3015, // 避免与主后台 3010 冲突
    host: '0.0.0.0',
  },
});
```

---

## 其它应用如何接入后台(Config/{app}/admin.php)

```php
return [
    // 设置中心分组 tab (可选): ['init'=>'全局', 'myapp'=>'我的应用']
    'settingList' => [...],

    // 设置字段(可选): name 全局唯一, parent 对应 settingList 的 key
    'setting' => [
        ['name' => 'myapp_domain', 'label' => '访问域名', 'component' => 'input',
         'parent' => 'myapp', 'bind' => ['placeholder' => 'stat.example.com']],
        ['name' => 'myapp_debug', 'component' => 'switch', 'parent' => 'myapp',
         'bind' => ['active-value' => 1, 'inactive-value' => 0]],
    ],

    // 菜单分组(可选): key 为顶级菜单 path 片段
    'menu' => ['myapp' => ['meta' => ['title' => '我的应用', 'icon' => 'el-icon-box']]],

    // 子页面路由(可选): component 指向旧组件或 meta.tablename 指向声明式表格
    'router' => [
        [
            'parentid' => 'myapp',
            'path' => 'myapp/orders',
            'component' => '',
            'meta' => ['title' => '订单', 'tablename' => 'myapp.orders'],
        ],
    ],

    // 声明式表格(可选): 供通用表格引擎直接渲染与管理
    'tables' => [
        'orders' => [
            'label' => '订单',
            'table' => '#@_myapp_orders',      // #@_ 前缀占位符
            'key' => 'id',
            'columns' => [                     // 白名单列; 字符串或 ['name'=>,'label'=>]
                'id', ['name' => 'sn', 'label' => '编号'], ['name' => 'amount', 'label' => '金额'],
            ],
            'searchable' => ['sn'],
            'editable' => ['sn', 'amount'],    // 空 = 只读表格
        ],
    ],
];
```

前端约定:

- 菜单路径是**展示层**可覆盖的: 全局后台在应用管理里为每个应用持久化 `admin_enable`(是否进入后台)与 `admin_config`(JSON: `path/title/icon/sort/hidden` 覆盖顶层菜单、`tables.{name}` 覆盖 label/searchable/editable); 新版前端的 URL 前缀为 `{adminhostpath}`(默认 `/admin`), hash 路由。
- 后台接口调用形态保持兼容: `/{adminhostpath}/api/{controller}/{action}`(admin 自身)与 `/{adminhostpath}/api/{app}/{controller}/{action}`(其它应用), 控制器继承 `App\admin\Authorization`(新增 `$this->admin()` 可取当前管理员)。

## 前端开发

```bash
cd app/admin/View
pnpm install
pnpm dev       # http://localhost:3010, 通过 vite 代理外请开发时自行指向 /{adminhostpath}/api
pnpm build     # 产出 dist/, 后端 Index 控制器直接服务本地 dist
```

页面清单: 登录、工作台、应用管理(市场/SSE 安装/接入配置抽屉/卸载删表确认)、设置中心(动态表单)、通用数据表格、系统维护(缓存/进程/云平台)、权限与角色(user.Group 接口)、旧组件降级占位页。

> 兼容性说明: 各应用的 `Router::menu/router 声明中指向旧云 phar 内部组件(component 如 system/stat)的菜单`, 在新版前端会渲染占位提示页; 补充 `meta.tablename` 即可立刻复用通用表格引擎。

## 版本与升级

版本号维护在 `Install/config.php`; 存量部署执行 `php index.php app admin up` 即完成文件刷新与新列迁移。
