# Stripe 支付组件

基于 [stripe/stripe-php](https://github.com/stripe/stripe-php) 封装的支付模块，提供 Checkout 托管收银台、周期订阅、退款、客户管理与 Webhook 事件分发能力，任意模块可通过 `app('stripe')` 直接调用。

- 模块名：`stripe`
- 依赖：`stripe/stripe-php ^21.0`（已在 `Install/config.php` 声明，安装模块时框架自动 composer require）
- 数据表：`#@_stripe_payments`（支付流水）
- Webhook 回调：`/stripe/webhook`

---

## 目录结构

```
app/stripe/
├── ConfigProvider.php            # 模块注册（注解扫描）
├── Api.php                       # 核心服务类 App\stripe\Api
├── Config/
│   └── admin.php                 # 后台设置项（密钥/环境/货币）
├── Controller/
│   └── Index.php                 # 路由: /stripe/webhook、/stripe/success、/stripe/cancel
├── Event/
│   ├── PaymentSucceeded.php      # 支付成功事件（含订阅续费）
│   └── WebhookReceived.php       # 通用事件（每条验签回调都派发）
├── Install/
│   ├── config.php                # 安装元信息 + composer 依赖声明
│   ├── install.sql               # 建表 #@_stripe_payments
│   └── schema.json               # 表结构描述
└── Middleware/
    └── CorsMiddleware.php        # 跨域处理
```

---

## 安装与配置

### 1. 安装模块

```bash
xphp app stripe          # 等价于 php index.php app stripe
```

命令会自动完成：安装 composer 依赖（`stripe/stripe-php`，来自 `Install/config.php` 声明）→ 导入 `Install/install.sql` 建表 → 写入 `application` 表注册 → 重新生成 `config/apps.php` 缓存。

> `config/apps.php` 是由 `application` 数据库表生成的缓存文件，**不要手动编辑**，手改会在下次安装/卸载时被覆盖。卸载用 `xphp app stripe u`，更新用 `xphp app stripe up`，加 `-r` 安装后自动重启服务。

### 2. 后台配置密钥

后台「系统设置 → Stripe支付」：

| 配置项 | 说明 |
|--------|------|
| 运行环境 | `live` 生产 / `test` 测试，决定使用哪把 Secret Key |
| 生产 Secret Key | `sk_live_` 开头，Stripe 后台「开发者 → API 密钥」 |
| 测试 Secret Key | `sk_test_` 开头 |
| Webhook 签名密钥 | `whsec_` 开头，Webhook 端点的签名密钥 |
| 默认货币 | 调用未指定货币时使用，默认 `usd` |

> Stripe 不支持人民币（CNY）收款，亚太常用 `hkd`、`sgd`、`jpy`，欧美 `usd`、`eur`。

### 3. 配置 Webhook 端点

Stripe 后台「开发者 → Webhook」添加端点：

```
https://你的域名/stripe/webhook
```

勾选事件：`checkout.session.completed`、`payment_intent.succeeded`、`invoice.paid`、`invoice.payment_failed`、`customer.subscription.updated`、`customer.subscription.deleted`。

### 4. 重启服务

```bash
rm -rf runtime/container   # 改代码后必清 DI 缓存
php index.php restart
```

---

## 使用方法

所有能力通过 `app('stripe')` 调用。金额单位均为**最小货币单位**（如 `999` = $9.99，`15900` = ¥159.00 HKD）。

### 一次性支付

快捷模式（金额 + 商品名即可）：

```php
$session = app('stripe')->createCheckout([
    'amount' => 1999,                       // $19.99
    'name' => '商品名称',
    'currency' => 'usd',                    // 不传则用后台默认货币
    'success_url' => 'https://xx.com/stripe/success?session_id={CHECKOUT_SESSION_ID}',
    'cancel_url' => 'https://xx.com/stripe/cancel',
    'metadata' => ['uid' => 123, 'order_no' => 'NO20260814001'],   // 业务数据, 回调原样返回
]);
redirect($session['url']);                  // 跳转 Stripe 托管收银台
```

`success_url` 中的 `{CHECKOUT_SESSION_ID}` 是 Stripe 官方占位符，支付完成后自动替换为会话 ID，回跳页据此查询支付结果。

完整模式（多商品、自定义参数，直接传 Stripe 标准的 `line_items`）：

```php
$session = app('stripe')->createCheckout([
    'line_items' => [
        ['price' => 'price_xxx', 'quantity' => 1],      // 后台预建的价格ID
        // 或 price_data 形式
    ],
    'customer_email' => 'user@example.com',
    'success_url' => '...',
    'cancel_url' => '...',
]);
```

### 月度 / 年度订阅

快捷模式传 `interval` 即可，自动切换 `subscription` 模式：

```php
// 月度订阅 $9.99/月
$session = app('stripe')->createCheckout([
    'amount' => 999,
    'name' => '专业版会员',
    'interval' => 'month',                  // 月度; 年度传 'year'
    'success_url' => '...',
    'cancel_url' => '...',
    'subscription_data' => [
        'metadata' => ['uid' => 123, 'plan' => 'pro_monthly'],
        // 'trial_period_days' => 14,       // 可选: 14天免费试用
    ],
]);
redirect($session['url']);
```

生产环境推荐在 Stripe 后台预建 Product/Price（月度 `price_xxx`、年度 `price_yyy`），改价不用发代码：

```php
$session = app('stripe')->createCheckout([
    'mode' => 'subscription',
    'line_items' => [['price' => 'price_monthly_id', 'quantity' => 1]],
    'success_url' => '...',
    'cancel_url' => '...',
]);
```

### 查询支付

```php
$session = app('stripe')->retrieveCheckout('cs_xxx');     // Checkout 会话
$intent = app('stripe')->retrieveIntent('pi_xxx');        // PaymentIntent
// 常用字段: $session['payment_status'] === 'paid' 表示已支付
```

### 主动查单（不依赖 Webhook 实时知道对方付了钱）

Webhook 丢失/未配置时，用**主动查询**确认支付，三种方式配合：

**1. 前端轮询接口**（实时，推荐支付等待页使用）

```js
// 用户在 Stripe 收银台/回跳页等待时, 每 3 秒查一次, 付完立即感知
const timer = setInterval(async () => {
    const res = await fetch(`/stripe/query?session_id=${sessionId}`).then(r => r.json());
    if (res.code === 200 && res.data.paid) {
        clearInterval(timer);
        alert('支付成功');
    }
}, 3000);
```

`/stripe/query` 返回 `{paid, status, payment_status, amount, currency}`；查到已支付会自动落库并派发 `PaymentSucceeded` 事件，**与 Webhook 走同一套业务逻辑且幂等**（先到先派发，后到的不重复触发）。

**2. 业务代码直接查询**

```php
$result = app('stripe')->queryCheckout('cs_xxx');   // ['paid' => true, ...]
if ($result['paid']) { /* 开通权益 */ }
```

**3. 定时对账兜底**（用户付完直接关页面、Webhook 也丢了的情况）

`Crontab/Reconcile.php` 已注册定时任务（`StripeReconcile`，每 5 分钟）：扫描创建超 15 分钟仍是 `open` 状态的会话，逐个向 Stripe 查证，已支付的补派发 `PaymentSucceeded`，已过期的标记 `expired`。重启服务后生效。手动执行：`app('stripe')->reconcile()`。

> 无论轮询还是 Webhook 先发现支付，`PaymentSucceeded` 只会派发一次；业务监听方仍建议按 `metadata.order_no` 做幂等（极端并发下有小概率竞态窗口）。

### 退款

```php
app('stripe')->refund('pi_xxx');               // 全额退款
app('stripe')->refund('pi_xxx', 500);          // 部分退款 $5.00
```

### 客户管理

```php
$customer = app('stripe')->createCustomer(['email' => 'user@example.com', 'name' => '张三']);
app('stripe')->retrieveCustomer('cus_xxx');
```

### 原生 SDK（订阅管理等）

未封装的能力直接用 `client()`（[StripeClient 完整 API](https://docs.stripe.com/api?lang=php)）：

```php
$app->stripe->client()->subscriptions->cancel('sub_xxx');          // 取消订阅
$app->stripe->client()->invoices->all(['customer' => 'cus_xxx']);  // 账单列表
```

---

## Webhook 与事件

回调经 `whsec_` 签名验证后自动落库 `#@_stripe_payments`（同一 Stripe 对象重复回调覆盖为最新状态），并派发两类事件：

| 事件类 | 触发时机 | 携带属性 |
|--------|---------|---------|
| `App\stripe\Event\PaymentSucceeded` | `payment_intent.succeeded` 或 `checkout.session.completed`（已支付），**订阅每次续费也会触发** | `$objectId` `$amount` `$currency` `$source` `$data`（完整对象） |
| `App\stripe\Event\WebhookReceived` | 每条验签通过的回调 | `$type`（事件名） `$data`（完整对象） `$objectId` |

### 其他模块监听示例

业务模块（如 `order`）中新建监听器 `app/order/Listener/StripePaidListener.php`：

```php
<?php

declare(strict_types=1);

namespace App\order\Listener;

use App\stripe\Event\PaymentSucceeded;
use xphp\Event\Contract\ListenerInterface;

class StripePaidListener implements ListenerInterface {

    public function listen(): array {
        return [PaymentSucceeded::class];
    }

    public function process(object $event): void {
        if (!$event instanceof PaymentSucceeded) {
            return;
        }
        // $event->data['metadata'] 里拿到 createCheckout 时传的业务数据
        // $event->amount / $event->currency 为本次支付金额
        // 在这里完成发货、开通会员等后续业务
    }
}
```

在业务模块 `ConfigProvider.php` 注册：

```php
public function __invoke(): array {
    return [
        'annotations' => ['scan' => ['paths' => [__DIR__]]],
        'listeners' => [
            \App\order\Listener\StripePaidListener::class,
        ],
    ];
}
```

订阅生命周期事件（取消、扣款失败等）监听 `WebhookReceived` 并按 `$event->type` 分支处理：

| 事件 type | 含义 | 建议处理 |
|-----------|------|---------|
| `invoice.paid` | 订阅续费成功 | 延长会员有效期 |
| `invoice.payment_failed` | 扣款失败 | 通知用户更新卡 |
| `customer.subscription.updated` | 升级/降级/换Plan | 调整权益 |
| `customer.subscription.deleted` | 订阅取消/到期 | 回收会员权益 |

---

## API 方法参考

| 方法 | 说明 |
|------|------|
| `app('stripe')->client()` | 原生 `StripeClient` 单例（懒加载，自动带密钥） |
| `app('stripe')->config($key, $default)` | 读后台配置（`config.stripe_*`） |
| `app('stripe')->createCheckout($params)` | 创建 Checkout 会话，返回 `['url' => ..., 'id' => ...]` |
| `app('stripe')->retrieveCheckout($sessionId)` | 查询 Checkout 会话 |
| `app('stripe')->queryCheckout($sessionId)` | 主动查单: 查询状态并同步/派发支付成功事件(幂等) |
| `app('stripe')->reconcile($minutes = 15, $limit = 50)` | 对账: 兜底确认超时未回调的支付(Crontab 每 5 分钟) |
| `app('stripe')->createIntent($params)` | 创建 PaymentIntent（自定义支付流程） |
| `app('stripe')->retrieveIntent($intentId)` | 查询 PaymentIntent |
| `app('stripe')->refund($intentId, $amount = null)` | 全额/部分退款 |
| `app('stripe')->createCustomer($params)` | 创建客户 |
| `app('stripe')->retrieveCustomer($customerId)` | 查询客户 |
| `app('stripe')->handleWebhook($payload, $sigHeader)` | 验签并处理回调（落库+派发事件） |
| `app('stripe')->record($type, $objectId, $data)` | 手动记录支付流水 |
| `app('stripe')->secretKey()` | 当前环境的 Secret Key |

---

## 数据表 `#@_stripe_payments`

| 字段 | 类型 | 说明 |
|------|------|------|
| `id` | int unsigned | 主键 |
| `type` | varchar(64) | 事件/操作类型，如 `checkout.session.completed` |
| `object_id` | varchar(64) | Stripe 对象 ID（`cs_`/`pi_`/`sub_`...），唯一索引 |
| `status` | varchar(32) | 对象状态 |
| `amount` | bigint | 金额（最小货币单位） |
| `currency` | varchar(8) | 货币 |
| `customer` | varchar(64) | 客户 ID |
| `metadata` | text | 业务元数据（JSON） |
| `raw` | text | 原始数据（JSON） |
| `created_at` / `updated_at` | timestamp | 创建/更新时间 |

查询流水：`m('stripe_payments')->where(['object_id' => 'cs_xxx'])->first()`。

---

## 常见问题

- **金额怎么算？** Stripe 全部用最小货币单位。美元/欧元/港币乘 100，日元等零小数货币直接传面值。
- **测试环境怎么用？** 后台切「运行环境」为 test，用 [Stripe 测试卡](https://docs.stripe.com/testing) `4242 4242 4242 4242`，测试 Webhook 用 `stripe listen --forward-to localhost:9551/stripe/webhook`。
- **回跳页能判断支付结果吗？** `success_url` 只代表用户到了成功页，**最终结果以 Webhook 为准**（回跳页数据可被伪造，落库+事件才是可信来源）。
- **改了代码不生效？** `rm -rf runtime/container` 后重启。
- **报「Secret Key 未配置」？** 检查后台运行环境与对应环境的密钥是否都已填写。
