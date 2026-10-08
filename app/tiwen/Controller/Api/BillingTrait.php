<?php

namespace App\tiwen\Controller\Api;

use App\tiwen\Service\StripeService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AuthTokenMiddleware;

/**
 * 订阅支付接口（需鉴权）
 * Stripe Checkout、订阅状态轮询、管理订阅入口
 */
#[Middleware(AuthTokenMiddleware::class)]
trait BillingTrait {

    use Responses;

    /**
     * 创建 Stripe 订阅 Checkout,返回跳转地址
     * @param string $plan 套餐: pro/team
     * @param string $interval 周期: month/year
     */
    public function checkout() {
        if (!app('?stripe')) {
            return $this->fail('支付组件未安装,请联系管理员');
        }
        $base = $this->siteBaseUrl();
        $result = (new StripeService())->createCheckout(
            $this->uid(),
            (string) input('plan', ''),
            (string) input('interval', 'month'),
            $base . '/payment/callback?status=pending&plan=' . urlencode((string) input('plan', '')) . '&session_id={CHECKOUT_SESSION_ID}',
            $base . '/payment/callback?status=cancel'
        );
        if (!$result['ok']) {
            return $this->fail($result['message']);
        }
        return $this->ok(['url' => $result['url'], 'sessionId' => $result['session_id']]);
    }

    /**
     * 订阅状态查询(支付回调页轮询,每 3 秒一次)
     * 传入 session_id 时主动查单确认,不依赖 Webhook
     * @param string $sessionId Stripe Checkout 会话ID(cs_开头,选填)
     */
    public function subscription() {
        if (!app('?stripe')) {
            return $this->fail('支付组件未安装,请联系管理员');
        }
        $status = (new StripeService())->subscriptionStatus($this->uid(), trim((string) input('sessionId', '')));
        return $this->ok($status);
    }

    /**
     * Stripe 管理订阅入口(billing portal)跳转地址
     */
    public function billingPortal() {
        if (!app('?stripe')) {
            return $this->fail('支付组件未安装,请联系管理员');
        }
        $result = (new StripeService())->billingPortal($this->uid(), $this->siteBaseUrl() . '/profile');
        if (!$result['ok']) {
            return $this->fail($result['message']);
        }
        return $this->ok(['url' => $result['url']]);
    }

    /**
     * 站点对外地址:优先 tiwen@config.site_url,否则按请求头推断(支持反代)
     */
    protected function siteBaseUrl(): string {
        $base = rtrim((string) config('tiwen@config.site_url', ''), '/');
        if ($base !== '') {
            return $base;
        }
        $uri = $this->request->getUri();
        $scheme = $this->request->getHeaderLine('X-Forwarded-Proto') ?: $uri->getScheme() ?: 'http';
        $host = $this->request->getHeaderLine('X-Forwarded-Host') ?: $uri->getHost();
        if ($host === '') {
            return 'http://localhost';
        }
        return $scheme . '://' . $host;
    }
}
