<?php

declare(strict_types=1);

namespace App\stripe\Event;

/**
 * Stripe 支付成功事件
 * 触发: payment_intent.succeeded 或 checkout.session.completed(payment_status=paid)
 * 监听: 在业务模块 ConfigProvider 的 listeners 中注册实现 xphp\Event\Contract\ListenerInterface 的监听类
 */
class PaymentSucceeded {

    public function __construct(
        public readonly string $objectId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $source,
        public readonly array $data,
    ) {
    }
}
