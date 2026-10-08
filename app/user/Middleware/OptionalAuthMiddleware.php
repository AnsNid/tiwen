<?php

declare(strict_types=1);

namespace App\user\Middleware;

/**
 * 可选登录中间件(游客可访问)
 * 逻辑与 UserAuthMiddleware 一致, 仍会解析登录态注入 user/uid/sessionId,
 * 区别仅在于未登录不拦截, uid 注入为 0
 */
class OptionalAuthMiddleware extends UserAuthMiddleware {
    protected bool $guest = true;
}
