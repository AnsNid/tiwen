<?php

declare(strict_types=1);

namespace App\tiwen\Service;

/**
 * Stripe 订阅服务
 * 封装 app('stripe'):创建订阅 Checkout、查询状态、管理订阅入口(billing portal)
 * 权益开通/续期/降级由 Listener 监听 stripe 事件落库
 */
class StripeService {

    public function __construct(private MemberService $members = new MemberService()) {
    }

    /**
     * 创建 Checkout 会话(订阅周期付款或一次性包单笔支付)
     * @return array{ok: bool, message: string, url?: string, session_id?: string}
     */
    public function createCheckout(int $uid, string $plan, string $interval, string $successUrl, string $cancelUrl): array {
        $conf = $this->members->planConfig($plan);
        if (!$conf || $plan === 'free') {
            return ['ok' => false, 'message' => '套餐不正确'];
        }
        if (empty($conf['enabled'])) {
            return ['ok' => false, 'message' => '该套餐已下架,暂不可购买'];
        }
        $isOneTime = ($conf['type'] ?? '') === 'one_time';
        if ($isOneTime) {
            $interval = 'one_time';
            $price = (float) ($conf['price'] ?? 0);
        } else {
            if (!in_array($interval, ['month', 'year'], true)) {
                return ['ok' => false, 'message' => '订阅周期不正确'];
            }
            $price = $interval === 'year' ? ($conf['price_yearly'] ?? 0) : ($conf['price_monthly'] ?? 0);
        }
        if ($price <= 0) {
            return ['ok' => false, 'message' => '套餐价格未配置'];
        }

        $user = m('user.Api')->where('uid', $uid)->one() ?: [];
        $email = (string) ($user['email'] ?? '');

        // 复用/创建 Stripe Customer,保证订阅与发票归集到同一客户
        $member = $this->members->ensureMember($uid);

        // 防重复订阅:同套餐同周期仍生效时拒绝再次下单。
        // 不拦截的话 Stripe 侧会叠加为多份订阅同时扣款(账单页"下一笔付款"随之翻倍)
        // 已在 Stripe 管理页设置了到期取消(cancel_at_period_end)的订阅不算,允许重新购买
        // 一次性包为单笔支付,可重复购买叠加积分,不做订阅去重
        if (!$isOneTime) {
            $activeSub = $this->activeSubscriptionOf($member);
            $isCanceling = $activeSub !== null && !empty($activeSub['cancel_at_period_end']);
            if ($activeSub !== null && !$isCanceling) {
                $subMeta = is_array($activeSub['metadata'] ?? null) ? $activeSub['metadata'] : [];
                if ((string) ($subMeta['plan'] ?? '') === $plan && (string) ($subMeta['interval'] ?? 'month') === $interval) {
                    return ['ok' => false, 'message' => '您已订阅该套餐且在生效期内,无需重复购买,可在个人页通过 Stripe 管理订阅'];
                }
            }
        }

        $customerId = (string) ($member['stripe_customer_id'] ?? '');
        if ($customerId === '') {
            try {
                $customer = app('stripe')->createCustomer([
                    'email' => $email,
                    'name' => (string) ($user['nickname'] ?? ''),
                    'metadata' => ['uid' => $uid, 'app' => 'tiwen'],
                ]);
                $customerId = (string) ($customer['id'] ?? '');
                if ($customerId !== '') {
                    m('tiwen_members')->where('uid', $uid)->update(['stripe_customer_id' => $customerId]);
                }
            } catch (\Throwable $e) {
                logger()->error('tiwen 创建 Stripe 客户失败: ' . $e->getMessage());
            }
        }

        $meta = ['uid' => $uid, 'plan' => $plan, 'interval' => $interval, 'app' => 'tiwen'];
        $params = [
            'mode' => $isOneTime ? 'payment' : 'subscription',
            'amount' => (int) round($price * 100),
            // 币种与后台设置保持一致,保证扣款币种与前台展示统一
            'currency' => strtolower((new SettingService())->currency()),
            'name' => 'Tiwen ' . ($conf['name'] ?? $plan) . ($isOneTime ? ' (一次性)' : ' (' . ($interval === 'year' ? '年付' : '月付') . ')'),
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $meta,
            'allow_promotion_codes' => true,
        ];
        if (!$isOneTime) {
            $params['interval'] = $interval;
            $params['subscription_data'] = ['metadata' => $meta];
        }

        try {
            $session = app('stripe')->createCheckout(
                $params + ($customerId !== '' ? ['customer' => $customerId] : (array_filter(['customer_email' => $email])))
            );
        } catch (\Throwable $e) {
            logger()->error('tiwen 创建 Checkout 失败: ' . $e->getMessage());
            return ['ok' => false, 'message' => strpos($e->getMessage(), '未配置') !== false ? $e->getMessage() : '创建支付会话失败,请稍后重试'];
        }

        $url = (string) ($session['url'] ?? '');
        if ($url === '') {
            return ['ok' => false, 'message' => '创建支付会话失败'];
        }
        return ['ok' => true, 'message' => '', 'url' => $url, 'session_id' => (string) ($session['id'] ?? '')];
    }

    /**
     * 订阅状态查询(支付回调页轮询)
     * 传入 session_id 时先主动查单(幂等触发 PaymentSucceeded,不依赖 Webhook)
     */
    public function subscriptionStatus(int $uid, string $sessionId = ''): array {
        $paid = null;
        $orderPlan = '';
        $orderInterval = '';
        if ($sessionId !== '' && str_starts_with($sessionId, 'cs_')) {
            try {
                $query = app('stripe')->queryCheckout($sessionId);
                $paid = ($query['paid'] ?? false) === true;
                $orderPlan = (string) ($query['metadata']['plan'] ?? '');
                $orderInterval = (string) ($query['metadata']['interval'] ?? '');
                // 查单确认支付成功时主动触发权益开通(单笔一次性包或订阅即时到账,不依赖 Webhook 到达延迟)
                if ($paid) {
                    $this->onPaymentSucceeded($query);
                }
            } catch (\Throwable $e) {
                logger()->warning('tiwen queryCheckout ' . $sessionId . ': ' . $e->getMessage());
            }
        }
        $profile = $this->members->profile($uid);
        $targetPlan = $orderPlan ?: $profile['plan'];
        $conf = $this->members->planConfig($targetPlan);
        $isOneTime = ($conf['type'] ?? '') === 'one_time' || $orderInterval === 'one_time' || $profile['planInterval'] === 'one_time';
        return [
            'paid' => $paid,
            'plan' => $profile['plan'],
            'orderPlan' => $orderPlan ?: $profile['plan'],
            'orderInterval' => $orderInterval ?: $profile['planInterval'],
            'isOneTime' => $isOneTime,
            'planInterval' => $profile['planInterval'],
            'subscriptionStatus' => $profile['subscriptionStatus'],
            'currentPeriodEnd' => $profile['currentPeriodEnd'],
            'tokenQuota' => $profile['tokenQuota'],
        ];
    }

    /**
     * Stripe 管理订阅入口(billing portal)
     * @return array{ok: bool, message: string, url?: string}
     */
    public function billingPortal(int $uid, string $returnUrl): array {
        $member = $this->members->ensureMember($uid);
        $customerId = (string) ($member['stripe_customer_id'] ?? '');
        if ($customerId === '') {
            return ['ok' => false, 'message' => '暂无 Stripe 订阅记录,请先订阅付费套餐'];
        }
        try {
            $session = app('stripe')->client()->billingPortal->sessions->create([
                'customer' => $customerId,
                'return_url' => $returnUrl,
            ]);
            $url = (string) ($session->url ?? '');
            if ($url === '') {
                return ['ok' => false, 'message' => '创建管理入口失败'];
            }
            return ['ok' => true, 'message' => '', 'url' => $url];
        } catch (\Throwable $e) {
            logger()->error('tiwen billing portal 失败: ' . $e->getMessage());
            return ['ok' => false, 'message' => '打开订阅管理失败: ' . mb_substr($e->getMessage(), 0, 120)];
        }
    }

    /**
     * 近期支付记录(个人页发票列表)
     */
    public function invoices(int $uid, int $limit = 10): array {
        $member = $this->members->ensureMember($uid);
        $customerId = (string) ($member['stripe_customer_id'] ?? '');
        if ($customerId === '') {
            return [];
        }
        $rows = m('stripe_payments')->where('customer', $customerId)
            ->whereIn('type', ['checkout.session', 'invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed', 'invoice'])
            ->orderBy('updated_at', 'desc')->limit(50)
            ->get(['type', 'object_id', 'status', 'amount', 'currency', 'created_at'])->toArray();
        $list = [];
        foreach ($rows as $row) {
            $type = (string) $row['type'];
            $status = (string) $row['status'];
            // 只展示真实发生的收付款:checkout 会话在打开收银台时就落库(open/expired),
            // 发票也有 open/void 等未支付状态,不过滤会造成"未付款也显示已扣缴"
            if ($type === 'checkout.session' && !in_array($status, ['complete', 'succeeded'], true)) {
                continue;
            }
            if ($type === 'invoice' && $status !== 'paid') {
                continue;
            }
            $list[] = [
                'id' => (string) $row['object_id'],
                'title' => $type === 'invoice.payment_failed' ? '订阅扣款失败' : '订阅付款',
                'status' => $status,
                'failed' => $type === 'invoice.payment_failed',
                'amount' => round(((int) $row['amount']) / 100, 2),
                'currency' => strtoupper((string) $row['currency']),
                'date' => substr((string) $row['created_at'], 0, 10),
            ];
        }
        return $list;
    }

    /**
     * 支付成功(首次订阅/Checkout 完成)开通权益
     * data 为 checkout.session 对象数组:metadata 带 uid/plan/interval,subscription 为订阅ID
     * interval=one_time 为一次性包(旗舰包),走积分到账与赠送会员开通
     */
    public function onPaymentSucceeded(array $data): void {
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $uid = (int) ($meta['uid'] ?? 0);
        $plan = (string) ($meta['plan'] ?? '');
        $interval = (string) ($meta['interval'] ?? 'month');
        if ($uid <= 0) {
            return;
        }
        $conf = $this->members->planConfig($plan);
        if (!$conf || $plan === 'free') {
            return;
        }

        // 一次性包:单笔支付直接到账,无订阅对象可查;支付单号作积分入账的幂等键
        if ($interval === 'one_time' || ($conf['type'] ?? '') === 'one_time') {
            $this->members->grantOneTime($uid, $plan, [
                'stripe_customer_id' => (string) ($data['customer'] ?? ''),
                'payment_ref' => (string) ($data['id'] ?? ''),
            ]);
            return;
        }

        if (!in_array($plan, ['pro', 'team'], true)) {
            return;
        }
        $subscription = is_array($data['subscription'] ?? null) ? (string) ($data['subscription']['id'] ?? '') : (string) ($data['subscription'] ?? '');

        // 尝试从 Stripe 订阅对象获取 current_period_end;查询失败按周期估算兜底,
        // 否则周期为 0 会导致 grantPlan 不重置配额、会员到期时间缺失
        $periodEnd = 0;
        if ($subscription !== '') {
            try {
                $sub = app('stripe')->client()->subscriptions->retrieve($subscription)->toArray();
                $periodEnd = (int) ($sub['current_period_end'] ?? 0);
            } catch (\Throwable $e) {
                logger()->warning('tiwen retrieve subscription ' . $subscription . ': ' . $e->getMessage());
            }
        }
        if ($periodEnd <= 0) {
            $periodEnd = strtotime($interval === 'year' ? '+1 year' : '+1 month');
        }

        $this->members->grantPlan($uid, $plan, $interval, [
            'stripe_customer_id' => (string) ($data['customer'] ?? ''),
            'stripe_subscription_id' => $subscription,
            'subscription_status' => 'active',
            'current_period_end' => $periodEnd,
        ]);

        // 同一客户只保留一份自动续费:其余生效订阅标记周期末取消,
        // 否则旧订阅会继续扣款,账单页"下一笔付款"出现翻倍(如 0.19 + 0.19 = 0.38)
        $this->expireOtherSubscriptions((string) ($data['customer'] ?? ''), $subscription);
    }

    /**
     * 查询会员在 Stripe 侧仍生效的订阅(本地订阅ID索引 + Stripe 实时状态双重确认)
     * @return array|null Stripe 订阅对象数组,无生效订阅返回 null
     */
    private function activeSubscriptionOf(array $member): ?array {
        $subId = (string) ($member['stripe_subscription_id'] ?? '');
        if ($subId === '' || !str_starts_with($subId, 'sub_')) {
            return null;
        }
        if (!in_array((string) ($member['subscription_status'] ?? ''), ['active', 'trialing'], true)) {
            return null;
        }
        try {
            $sub = app('stripe')->client()->subscriptions->retrieve($subId)->toArray();
        } catch (\Throwable $e) {
            logger()->warning('tiwen 查询订阅 ' . $subId . ': ' . $e->getMessage());
            return null;
        }
        return in_array((string) ($sub['status'] ?? ''), ['active', 'trialing'], true) ? $sub : null;
    }

    /**
     * 把客户名下除指定订阅外的其它生效订阅标记为周期末取消(不立即停,已付费周期保留)
     */
    private function expireOtherSubscriptions(string $customerId, string $keepSubId): void {
        if ($customerId === '' || $keepSubId === '') {
            return;
        }
        try {
            $subs = app('stripe')->client()->subscriptions->all([
                'customer' => $customerId,
                'status' => 'all',
                'limit' => 100,
            ])->toArray();
            foreach ((array) ($subs['data'] ?? []) as $sub) {
                $id = (string) ($sub['id'] ?? '');
                $status = (string) ($sub['status'] ?? '');
                if ($id === '' || $id === $keepSubId) {
                    continue;
                }
                if (!in_array($status, ['active', 'trialing', 'past_due', 'unpaid'], true)) {
                    continue;
                }
                if (!empty($sub['cancel_at_period_end'])) {
                    continue;
                }
                app('stripe')->client()->subscriptions->update($id, ['cancel_at_period_end' => true]);
                logger()->info('tiwen 订阅去重: 旧订阅 ' . $id . ' 已标记周期末取消');
            }
        } catch (\Throwable $e) {
            logger()->warning('tiwen 清理重复订阅失败: ' . $e->getMessage());
        }
    }

    /**
     * Webhook 事件处理:续费、状态变更、退订
     */
    public function onWebhookReceived(string $type, array $data): void {
        switch ($type) {
            case 'invoice.paid':
            case 'invoice.payment_succeeded':
                $this->onInvoicePaid($data);
                break;
            case 'invoice.payment_failed':
                $this->onInvoicePaymentFailed($data);
                break;
            case 'customer.subscription.updated':
                $this->onSubscriptionUpdated($data);
                break;
            case 'customer.subscription.deleted':
                $this->onSubscriptionDeleted($data);
                break;
        }
    }

    /**
     * 续费成功:重置月度 Token 配额
     */
    private function onInvoicePaid(array $data): void {
        $uid = $this->uidOfSubscriptionData($data);
        if ($uid <= 0) {
            return;
        }
        $member = $this->members->ensureMember($uid);
        $plan = (string) ($member['plan'] ?? 'free');
        if (!in_array($plan, ['pro', 'team'], true)) {
            return;
        }
        m('tiwen_members')->where('uid', $uid)->update([
            'tokens_used' => 0,
            'subscription_status' => 'active',
        ]);
    }

    /**
     * 续费扣款失败:标记订阅状态为 past_due
     */
    private function onInvoicePaymentFailed(array $data): void {
        $uid = $this->uidOfSubscriptionData($data);
        if ($uid <= 0) {
            return;
        }
        $member = $this->members->ensureMember($uid);
        if (!in_array((string) ($member['plan'] ?? 'free'), ['pro', 'team'], true)) {
            return;
        }
        m('tiwen_members')->where('uid', $uid)->update(['subscription_status' => 'past_due']);
        logger()->warning('tiwen 续费扣款失败: uid=' . $uid . ' invoice=' . ((string) ($data['id'] ?? '')));
    }

    /**
     * 订阅状态变更:同步状态与周期;失效/未付清时降级
     */
    private function onSubscriptionUpdated(array $data): void {
        $uid = $this->uidOfSubscriptionData($data);
        if ($uid <= 0) {
            return;
        }
        $status = (string) ($data['status'] ?? '');
        $periodEnd = (int) ($data['current_period_end'] ?? 0);
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];

        if (in_array($status, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
            $this->members->downgradeToFree($uid);
            return;
        }
        // past_due 保留套餐但标记状态,由 invoice.paid 恢复
        // stripe_customer_id 列为 NOT NULL,空值需写空串而非 null(严格模式写 null 会整条更新失败)
        m('tiwen_members')->where('uid', $uid)->update([
            'subscription_status' => $status ?: 'active',
            'current_period_end' => $periodEnd,
            'stripe_customer_id' => (string) ($data['customer'] ?? '') ?: '',
        ]);
    }

    /**
     * 订阅取消:降级为免费套餐
     */
    private function onSubscriptionDeleted(array $data): void {
        $uid = $this->uidOfSubscriptionData($data);
        if ($uid > 0) {
            $this->members->downgradeToFree($uid);
        }
    }

    /**
     * 从订阅/发票数据反查 uid:优先 metadata,其次本地订阅ID索引,最后 customer 索引
     */
    private function uidOfSubscriptionData(array $data): int {
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $uid = (int) ($meta['uid'] ?? 0);
        if ($uid > 0) {
            return $uid;
        }
        $subId = is_array($data['subscription'] ?? null) ? (string) ($data['subscription']['id'] ?? '') : (string) ($data['subscription'] ?? ($data['id'] ?? ''));
        if ($subId !== '') {
            $uid = (int) (m('tiwen_members')->where('stripe_subscription_id', $subId)->value('uid') ?? 0);
            if ($uid > 0) {
                return $uid;
            }
        }
        return $this->members->uidByCustomer((string) ($data['customer'] ?? ''));
    }
}
