# tiwen · 多模型研报工作台

问得更准,比对所有顶尖 AI 模型 —— 一句提问,多个主流模型同时作答;支持多轮深度追问、勾选采纳、交叉综合分析与四格式研报导出。

## 功能总览

| 能力 | 说明 |
|------|------|
| 多模型并发提问 | 18 个顶尖模型(DeepSeek / GPT / Claude / Gemini / Grok / GLM / Kimi / MiniMax),最多 6 个同时并发对比,真实 SSE 流式输出 |
| 多轮上下文 | 追问自动携带会话历史(最近 2 轮,勾选卡片优先),服务端组装 |
| 分析综合 | 勾选跨轮次高价值观点,AI 交叉比对生成结构化研报,流式写入编辑区 |
| 研报编辑导出 | 编辑区草稿服务端持久化,四合一导出(MD / PDF / HTML / Word) |
| 账户体系 | 邮箱验证码注册(邮件走 `mail` 应用 SMTP)+ 密码登录,复用中央 `user` 应用 |
| 套餐配额 | Free 每日 4 次提问;Pro / Team 每月 100 万 / 500 万 Token,服务端强制,按月重置 |
| 订阅支付 | Stripe 订阅(月付/年付),Checkout 托管收银台 + 回调页轮询确认 + Webhook 事件同步,billing portal 自助管理 |
| 用量统计 | 模型用量占比、会话/轮次统计、Stripe 账单记录 |
| 管理后台 | `/sysAdmin`:消耗统计、订阅价格(含 pro/team 上下架与权益文案)、会员管理(调套餐/配额/重置用量)、提示词管理 |

## 技术栈

- **后端**:XPHP(Swoole 协程),模块 `app/tiwen/`,共享主服务端口(默认 `9521`,见 `config/autoload/server.php`)
- **前端**:React 18 + TypeScript + Tailwind CSS + Vite,SPA 位于 `View/`
- **依赖应用**:`user`(账户)、`openai`(多供应商路由)、`mail`(验证码邮件 SDK)、`stripe`(支付组件)

## 后端接口

路由前缀 `/tiwen`,响应统一 `{code, message, data}`(`code=200` 成功)。

**公共(免鉴权)**

| 接口 | 说明 |
|------|------|
| `GET /tiwen/models` | 18 个模型清单 |
| `GET /tiwen/config` | 站点配置 + 默认模型 + 套餐定义 |
| `POST /tiwen/sendCode` | 发送邮箱验证码(10 分钟有效,60 秒防刷) |
| `POST /tiwen/register` | 邮箱 + 验证码 + 密码注册,返回 token |
| `POST /tiwen/login` | 邮箱密码登录,返回 token |

**用户(请求头 `X-Token: Bearer {token}`)**

| 接口 | 说明 |
|------|------|
| `GET /tiwen/me` | 用户档案 + 套餐 + 配额 + 订阅状态 |
| `GET /tiwen/sessions` / `POST /tiwen/sessionCreate` | 会话列表 / 创建 |
| `POST /tiwen/sessionDetail` | 会话详情(轮次 + 卡片) |
| `POST /tiwen/sessionRename` / `sessionDelete` / `sessionDraft` / `sessionRound` | 重命名 / 删除 / 草稿 / 激活轮次 |
| `POST /tiwen/roundCreate` | 创建提问轮次(服务端校验配额,免费版超限返回 402) |
| `GET/POST /tiwen/chat` | SSE 流式对话(单模型单连接,自动携带多轮上下文,流完成落库卡片) |
| `POST /tiwen/cardCheck` / `cardDelete` | 勾选 / 删除卡片 |
| `POST /tiwen/synthesize` | SSE 流式分析综合 |
| `GET /tiwen/usage` | 用量统计 + 账单记录 |

**订阅(需鉴权)**

| 接口 | 说明 |
|------|------|
| `POST /tiwen/checkout` | 创建 Stripe 订阅 Checkout,返回 `{url}` 跳转 |
| `POST /tiwen/subscription` | 订阅状态查询(带 `sessionId` 时主动查单,回调页轮询用) |
| `POST /tiwen/billingPortal` | Stripe 自助管理订阅入口 |

**后台管理(路由前缀 `/tiwen/admin`,除登录外需 `tiwen:admin` 权限)**

前端入口 `/sysAdmin`(独立后台布局,不复用前台导航),登录 token 存 `localStorage.tiwen_admin_token`。

| 接口 | 说明 |
|------|------|
| `POST /tiwen/admin/login` | 后台登录(校验 `tiwen:admin` 权限,无权限返回 403) |
| `GET /tiwen/admin/info` | 当前管理员信息(鉴权心跳) |
| `GET /tiwen/admin/stats` | 消耗统计:KPI / 近 14 天趋势 / 模型消耗 / 套餐分布 / Top 用户 |
| `GET /tiwen/admin/plans` / `POST /tiwen/admin/plansSave` | 套餐价格与配额查看 / 保存(即时生效于新订单);pro/team 可下架(`enabled=false`,前台置灰不可购、Stripe 结账拦截),权益 `features` 一行一条 |
| `GET /tiwen/admin/members` / `POST /tiwen/admin/memberSave` | 会员分页检索(昵称/邮箱/UID) / 调整套餐、配额、重置用量 |
| `GET /tiwen/admin/prompts` / `POST /tiwen/admin/promptsSave` | 提示词查看 / 保存(对话 system + 综合分析 system,空串恢复默认) |

后台修改的套餐与提示词持久化在 `runtime/config/config.php`(键 `tiwen_plans` / `tiwen_prompt_chat` / `tiwen_prompt_synthesis`),读取时与 `Config/config.php` 文件默认值逐项合并——未改过的项回落文件值,升级模块不丢运营配置。权限 `tiwen:admin` 声明在 `Config/permissions.php`,在后台「会员权限」里授予。

## 数据表(前缀见 `.env`)

- `tiwen_members`:套餐/配额/Stripe 客户与订阅状态
- `tiwen_sessions` / `tiwen_rounds` / `tiwen_cards`:会话 / 轮次 / 模型回答卡片

## 前端开发与构建

```bash
cd app/tiwen/View
npm install
npm run dev      # 开发: http://localhost:5175,/tiwen 与 /stripe 代理到 127.0.0.1:9521
npm run build    # 产物输出 View/dist/
```

## 部署(nginx 样例)

SPA 部署在站点根路径,API 反代到 XPHP 主服务:

```nginx
server {
    listen 80;
    server_name tiwen.example.com;
    root /path/to/X-PHP-Hyperf/app/tiwen/View/dist;
    index index.html;

    # SPA 路由回退
    location / {
        try_files $uri $uri/ /index.html;
    }

    # 后端 API(tiwen 业务 + stripe 支付回调)
    location /tiwen/ {
        proxy_pass http://127.0.0.1:9521;
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host $host;
        proxy_buffering off;          # SSE 流式必需
        proxy_read_timeout 600s;
    }
    location /stripe/ {
        proxy_pass http://127.0.0.1:9521;
        proxy_set_header Host $host;
    }
}
```

Stripe 支付回调跳转的对外地址取 `app/tiwen/Config/config.php` 的 `site_url`(支持 `TIWEN_SITE_URL` 环境变量),留空时按请求头推断。

## Stripe 配置清单

1. 安装应用:`php index.php app tiwen`、`php index.php app stripe`(官方安装命令,勿手改 `config/apps.php`)
2. 后台「系统设置 → Stripe支付」填入:`stripe_mode`(live/test)、对应 Secret Key、`stripe_webhook_secret`
3. Stripe 控制台配置 Webhook 指向 `https://<域名>/stripe/webhook`,订阅事件:`checkout.session.completed`、`invoice.paid`、`invoice.payment_failed`、`customer.subscription.updated`、`customer.subscription.deleted`
4. 权益同步:支付成功(Webhook 或回调页主动查单,双通道幂等)自动开通;续费重置配额;退订/失效自动降级 Free

## 开发注意事项

- 后端代码改动后:`rm -rf runtime/container` 清 DI 缓存并重启服务
- 前端改动后需重新 `npm run build` 才能在 dist 生效(开发用 `npm run dev`)
- 模型对话与配额核算在 `app/tiwen/Service/ChatService.php`;套餐/价格/默认模型在 `app/tiwen/Config/config.php`
