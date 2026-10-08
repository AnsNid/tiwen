<?php

declare(strict_types=1);

namespace App\tiwen\Middleware;

use App\user\Middleware\UserAuthMiddleware as UserAuthMiddlewareUser;

/**
 * 校验 X-Token 登录态(`Bearer ${token}`),将 uid 注入请求属性
 */
class AuthTokenMiddleware extends UserAuthMiddlewareUser {
}
