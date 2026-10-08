<?php

declare(strict_types=1);

namespace App\stripe\Crontab;

use App\crontab\Annotation\Crontab;

/**
 * Stripe 支付对账
 * 每 5 分钟轮询超时未完成的 Checkout 会话, 兜底确认 Webhook 丢失/用户付完未回跳的支付
 */
#[Crontab(name: "StripeReconcile", rule: "*/5 * * * *", callback: "execute", memo: "Stripe支付对账(兜底确认未回调的支付)")]
class Reconcile {
    /**
     * 执行对账
     */
    public function execute() {
        $result = app('stripe')->reconcile();
        if (!empty($result['paid']) || $result['expired'] > 0) {
            logger()->info('stripe reconcile: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        }
        return $result;
    }
}
