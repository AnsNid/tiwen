<?php

declare(strict_types=1);

namespace App\stripe\Event;

/**
 * Stripe Webhook 通用事件(每条验签通过的回调都会派发)
 * 监听: 在业务模块 ConfigProvider 的 listeners 中注册实现 xphp\Event\Contract\ListenerInterface 的监听类
 */
class WebhookReceived {

    public function __construct(
        public readonly string $type,
        public readonly array $data,
        public readonly string $objectId = '',
    ) {
    }
}
