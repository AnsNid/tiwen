<?php

namespace App\tiwen\Controller;

use xphp\HttpServer\Annotation\AutoController;
use xphp\HttpServer\Annotation\Middleware;
use xphp\Session\Middleware\SessionMiddleware;
use App\tiwen\Middleware\CorsMiddleware;

/**
 * 提问控制器 —— 问得更准，比对所有顶尖 AI 模型
 *
 * 接口分组(均通过 trait 组合):
 *  - PublicTrait  公共(免鉴权):models / config / sendCode / register / login
 *  - UserTrait    用户(需鉴权):me / sessions / chat / synthesize / usage 等
 *  - BillingTrait 订阅(需鉴权):checkout / subscription / billingPortal
 */
#[AutoController('/tiwen')]
#[Middleware(SessionMiddleware::class, priority: 100)]
#[Middleware(CorsMiddleware::class, 99)]
class Api extends Authorization {
    // 公共接口（无需登录）
    use Api\PublicTrait;
    // 用户接口（X-Token 鉴权）
    use Api\UserTrait;
    // 订阅支付接口（X-Token 鉴权）
    use Api\BillingTrait;
}
