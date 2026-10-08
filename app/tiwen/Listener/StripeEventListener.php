<?php

declare(strict_types=1);

namespace App\tiwen\Listener;

use App\stripe\Event\PaymentSucceeded;
use App\stripe\Event\WebhookReceived;
use App\tiwen\Service\StripeService;
use xphp\Event\Contract\ListenerInterface;

/**
 * Stripe 事件监听
 * PaymentSucceeded: 首次订阅/Checkout 完成开通权益
 * WebhookReceived: 续费、订阅状态变更、退订同步套餐状态
 */
class StripeEventListener implements ListenerInterface {

    public function listen(): array {
        return [
            PaymentSucceeded::class,
            WebhookReceived::class,
        ];
    }

    public function process(object $event): void {
        try {
            $service = new StripeService();
            if ($event instanceof PaymentSucceeded) {
                $service->onPaymentSucceeded($event->data);
            } elseif ($event instanceof WebhookReceived) {
                $service->onWebhookReceived($event->type, $event->data);
            }
        } catch (\Throwable $e) {
            logger()->error('tiwen Stripe 事件处理失败: ' . $e->getMessage());
        }
    }
}
