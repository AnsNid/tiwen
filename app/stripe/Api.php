<?php

declare(strict_types=1);

namespace App\stripe;

use App\stripe\Event\PaymentSucceeded;
use App\stripe\Event\WebhookReceived;
use Psr\EventDispatcher\EventDispatcherInterface;
use Stripe\StripeClient;
use Stripe\Webhook as StripeWebhook;
use xphp\Contract\ContainerInterface;

/**
 * Stripe 支付服务
 * 全局调用: app('stripe')->createCheckout($params) 创建支付会话
 * 主动查单: app('stripe')->queryCheckout($sessionId) 实时查询支付状态(不依赖 Webhook)
 * 定时对账: app('stripe')->reconcile() 兜底确认超时未回调的支付(Crontab 每 5 分钟)
 * 原生 SDK: app('stripe')->client() 可直接使用订阅等未封装能力
 * Webhook 回调地址: /stripe/webhook
 */
class Api {

    protected ?StripeClient $client = null;

    public function __construct(protected ContainerInterface $container) {
    }

    /**
     * 读取模块配置(后台 系统设置 -> Stripe支付)
     * @param string $key 配置名(不含 stripe_ 前缀)
     * @param mixed $default 默认值
     * @return mixed
     */
    public function config(string $key, mixed $default = ''): mixed {
        return $this->container->get('config')->sysget('config.stripe_' . $key, $default);
    }

    /**
     * 当前运行环境(live/test)对应的 Secret Key
     * @return string
     * @throws \Exception
     */
    public function secretKey(): string {
        $mode = (string) $this->config('mode', 'live');
        $key = (string) $this->config($mode . '_secret_key', '');
        if (empty($key)) {
            throw new \Exception('Stripe ' . $mode . ' Secret Key 未配置, 请在后台 系统设置 -> Stripe支付 中填写');
        }
        return $key;
    }

    /**
     * 获取 StripeClient(懒加载单例)
     * 订阅/发票等未封装的能力可直接使用, 例: app('stripe')->client()->subscriptions->create($params)
     * @return StripeClient
     */
    public function client(): StripeClient {
        if ($this->client === null) {
            $this->client = new StripeClient($this->secretKey());
        }
        return $this->client;
    }

    /**
     * 创建 Checkout 托管支付会话, 返回数组(含 url 跳转地址与 id 会话ID)
     * 快捷方式: 只传 amount(最小货币单位,如美分)与 name 即可单商品收款
     * 订阅快捷方式: 再传 interval(month=月度/year=年度)即为周期订阅, 无需手写 line_items
     * 完整方式: 直接传 line_items/metadata/customer/email 等 Stripe 标准参数
     * @param array $params success_url 与 cancel_url 必传; success_url 需带 {CHECKOUT_SESSION_ID} 占位符; interval: month/year
     * @return array
     * @throws \Exception
     */
    public function createCheckout(array $params): array {
        if (empty($params['success_url']) || empty($params['cancel_url'])) {
            throw new \Exception('success_url 与 cancel_url 必传');
        }
        // 快捷单商品模式: amount + name 自动构建 line_items
        if (isset($params['amount']) && empty($params['line_items'])) {
            $priceData = [
                'currency' => $params['currency'] ?? $this->config('currency', 'usd'),
                'unit_amount' => (int) $params['amount'],
                'product_data' => ['name' => $params['name'] ?? '订单支付'],
            ];
            // 周期订阅: 传入 interval 自动切换为 subscription 模式
            if (!empty($params['interval'])) {
                $priceData['recurring'] = ['interval' => $params['interval']];
                $params['mode'] = $params['mode'] ?? 'subscription';
            }
            $params['line_items'] = [[
                'price_data' => $priceData,
                'quantity' => (int) ($params['quantity'] ?? 1),
            ]];
        }
        // currency/amount/name/quantity/interval 是本方法的自定义参数, 不是 Checkout API 顶层参数
        unset($params['currency'], $params['amount'], $params['name'], $params['quantity'], $params['interval']);
        $params['mode'] = $params['mode'] ?? 'payment';

        $session = $this->client()->checkout->sessions->create($params);
        $this->record('checkout.session', (string) $session->id, [
            'status' => (string) ($session->status ?? ''),
            'amount' => (int) ($session->amount_total ?? 0),
            'currency' => (string) ($session->currency ?? ''),
            'customer' => (string) ($session->customer ?? ''),
            'metadata' => $session->metadata ? $session->metadata->toArray() : [],
            'raw' => $session->toArray(),
        ]);
        return $session->toArray();
    }

    /**
     * 查询 Checkout 支付会话
     * @param string $sessionId 会话ID(cs_开头)
     * @return array
     */
    public function retrieveCheckout(string $sessionId): array {
        return $this->client()->checkout->sessions->retrieve($sessionId)->toArray();
    }

    /**
     * 创建 PaymentIntent(自定义支付流程底层意图)
     * @param array $params amount(最小货币单位)与 currency 必传, 或传 application_fee_amount 等 Stripe 标准参数
     * @return array
     */
    public function createIntent(array $params): array {
        $intent = $this->client()->paymentIntents->create($params);
        $this->record('payment_intent', (string) $intent->id, [
            'status' => (string) ($intent->status ?? ''),
            'amount' => (int) ($intent->amount ?? 0),
            'currency' => (string) ($intent->currency ?? ''),
            'customer' => (string) ($intent->customer ?? ''),
            'metadata' => $intent->metadata ? $intent->metadata->toArray() : [],
            'raw' => $intent->toArray(),
        ]);
        return $intent->toArray();
    }

    /**
     * 查询 PaymentIntent
     * @param string $intentId 意图ID(pi_开头)
     * @return array
     */
    public function retrieveIntent(string $intentId): array {
        return $this->client()->paymentIntents->retrieve($intentId)->toArray();
    }

    /**
     * 退款
     * @param string $intentId PaymentIntent ID(pi_开头)
     * @param int|null $amount 退款金额(最小货币单位), 不传为全额退款
     * @return array
     */
    public function refund(string $intentId, ?int $amount = null): array {
        $refund = $this->client()->refunds->create(array_filter([
            'payment_intent' => $intentId,
            'amount' => $amount,
        ], fn($v) => $v !== null));
        return $refund->toArray();
    }

    /**
     * 创建 Customer 客户
     * @param array $params email/name/metadata 等 Stripe 标准参数
     * @return array
     */
    public function createCustomer(array $params): array {
        return $this->client()->customers->create($params)->toArray();
    }

    /**
     * 查询 Customer 客户
     * @param string $customerId 客户ID(cus_开头)
     * @return array
     */
    public function retrieveCustomer(string $customerId): array {
        return $this->client()->customers->retrieve($customerId)->toArray();
    }

    /**
     * 验签并处理 Stripe Webhook 回调
     * 验签通过后落库支付记录, 并通过事件系统分发 WebhookReceived/PaymentSucceeded 事件
     * @param string $payload 原始请求体
     * @param string $sigHeader Stripe-Signature 请求头
     * @return array
     * @throws \Exception
     */
    public function handleWebhook(string $payload, string $sigHeader): array {
        $secret = (string) $this->config('webhook_secret', '');
        if (empty($secret)) {
            throw new \Exception('Stripe Webhook Secret 未配置, 请在后台 系统设置 -> Stripe支付 中填写');
        }
        $event = StripeWebhook::constructEvent($payload, $sigHeader, $secret);
        $type = (string) $event->type;
        $object = $event->data->object;
        $data = $object->toArray();
        $objectId = (string) $object->id;

        $isSession = str_starts_with($type, 'checkout.session');

        // 幂等标记: 该对象此前已确认过支付(complete/succeeded)则不重复派发, 保证 Webhook/主动查单双通道只触发一次业务
        $succeeded = $type === 'payment_intent.succeeded'
            || ($type === 'checkout.session.completed' && ($data['payment_status'] ?? '') === 'paid');
        $prevStatus = (string) m('stripe_payments')->where(['object_id' => $objectId])->value('status');
        $alreadyPaid = in_array($prevStatus, ['complete', 'succeeded'], true);

        $this->record($type, $objectId, [
            'status' => (string) ($data['status'] ?? ''),
            'amount' => (int) ($data[$isSession ? 'amount_total' : 'amount'] ?? 0),
            'currency' => (string) ($data['currency'] ?? ''),
            'customer' => (string) ($data['customer'] ?? ''),
            'metadata' => is_array($data['metadata'] ?? null) ? $data['metadata'] : [],
            'raw' => ['event' => $type, 'object' => $data],
        ]);

        // 通用事件: 每条已验签回调都会派发, 其他模块监听 App\stripe\Event\WebhookReceived
        $this->dispatch(new WebhookReceived($type, $data, $objectId));

        // 支付成功事件: 其他模块监听 App\stripe\Event\PaymentSucceeded 做发货等后续业务
        if ($succeeded && !$alreadyPaid) {
            $this->dispatch(new PaymentSucceeded(
                $objectId,
                (int) ($data[$isSession ? 'amount_total' : 'amount'] ?? 0),
                (string) ($data['currency'] ?? ''),
                $type,
                $data
            ));
        }

        return ['received' => true, 'type' => $type, 'object_id' => $objectId];
    }

    /**
     * 主动查单: 实时查询 Checkout 会话支付状态(Webhook 未到/未配置时的确认方式)
     * 前端可在收银台等待页每 3 秒轮询 /stripe/query?session_id=cs_xxx, 付完立即感知
     * 查到已支付时自动落库并派发 PaymentSucceeded, 与 Webhook 同一套事件且幂等(已派发过不重复)
     * @param string $sessionId 会话ID(cs_开头)
     * @return array 含 paid/status/payment_status/amount/currency
     */
    public function queryCheckout(string $sessionId): array {
        $session = $this->client()->checkout->sessions->retrieve($sessionId)->toArray();
        $paid = ($session['payment_status'] ?? '') === 'paid';
        if ($paid) {
            $this->syncCheckoutPaid($session);
        }
        return [
            'session_id' => (string) ($session['id'] ?? $sessionId),
            'paid' => $paid,
            'status' => (string) ($session['status'] ?? ''),
            'payment_status' => (string) ($session['payment_status'] ?? ''),
            'amount' => (int) ($session['amount_total'] ?? 0),
            'currency' => (string) ($session['currency'] ?? ''),
        ];
    }

    /**
     * 对账: 轮询超时未完成的 Checkout 会话, 兜底处理 Webhook 丢失/用户付完未回跳的情况
     * 由 Crontab 每 5 分钟调度(见 App\stripe\Crontab\Reconcile), 也可手动调用
     * @param int $minutes 只处理创建超过 N 分钟仍未完成的会话
     * @param int $limit 单次最大处理条数
     * @return array ['paid' => 本次确认的会话ID, 'expired' => 标记过期数, 'pending' => 仍在处理中数]
     */
    public function reconcile(int $minutes = 15, int $limit = 50): array {
        $pendings = m('stripe_payments')
            ->where(['type' => 'checkout.session'])
            ->whereNotIn('status', ['complete', 'expired'])
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - $minutes * 60))
            ->limit($limit)
            ->pluck('object_id')
            ->toArray();

        $result = ['paid' => [], 'expired' => 0, 'pending' => 0];
        foreach ($pendings as $objectId) {
            try {
                $session = $this->client()->checkout->sessions->retrieve($objectId)->toArray();
                if (($session['payment_status'] ?? '') === 'paid') {
                    $this->syncCheckoutPaid($session);
                    $result['paid'][] = $objectId;
                } elseif (($session['status'] ?? '') === 'expired') {
                    $this->record('checkout.session', $objectId, ['status' => 'expired', 'raw' => $session]);
                    $result['expired']++;
                } else {
                    $result['pending']++;
                }
            } catch (\Throwable $th) {
                logger()->warning('stripe reconcile ' . $objectId . ': ' . $th->getMessage());
            }
        }
        return $result;
    }

    /**
     * 同步已支付的 Checkout 会话(首次确认才派发 PaymentSucceeded, 幂等)
     * @param array $session Stripe Checkout Session 数组
     * @return void
     */
    protected function syncCheckoutPaid(array $session): void {
        $objectId = (string) $session['id'];
        $prevStatus = (string) m('stripe_payments')->where(['object_id' => $objectId])->value('status');
        $alreadyPaid = in_array($prevStatus, ['complete', 'succeeded'], true);

        $this->record('checkout.session', $objectId, [
            'status' => (string) ($session['status'] ?? ''),
            'amount' => (int) ($session['amount_total'] ?? 0),
            'currency' => (string) ($session['currency'] ?? ''),
            'customer' => (string) ($session['customer'] ?? ''),
            'metadata' => is_array($session['metadata'] ?? null) ? $session['metadata'] : [],
            'raw' => $session,
        ]);

        if (!$alreadyPaid) {
            $this->dispatch(new PaymentSucceeded(
                $objectId,
                (int) ($session['amount_total'] ?? 0),
                (string) ($session['currency'] ?? ''),
                'checkout.session',
                $session
            ));
        }
    }

    /**
     * 派发事件到 XPHP 事件系统
     * @param object $event 事件对象
     * @return void
     */
    public function dispatch(object $event): void {
        $this->container->get(EventDispatcherInterface::class)->dispatch($event);
    }

    /**
     * 记录支付流水(同一 Stripe 对象重复回调时覆盖为最新状态)
     * @param string $type 事件/操作类型
     * @param string $objectId Stripe 对象ID
     * @param array $data status/amount/currency/customer/metadata/raw
     * @return void
     */
    public function record(string $type, string $objectId, array $data = []): void {
        m('stripe_payments')->json(['metadata', 'raw'])->upsert([
            'type' => $type,
            'object_id' => $objectId,
            'status' => (string) ($data['status'] ?? ''),
            'amount' => (int) ($data['amount'] ?? 0),
            'currency' => (string) ($data['currency'] ?? ''),
            'customer' => (string) ($data['customer'] ?? ''),
            'metadata' => $data['metadata'] ?? [],
            'raw' => $data['raw'] ?? [],
        ], 'id', ['type', 'status', 'amount', 'currency', 'customer', 'metadata', 'raw']);
    }
}
