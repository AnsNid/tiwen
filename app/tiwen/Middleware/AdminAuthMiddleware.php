<?php

declare(strict_types=1);

namespace App\tiwen\Middleware;

use App\user\Middleware\UserAuthMiddleware as UserAuthMiddlewareUser;

/**
 * 后台管理鉴权中间件
 * 校验 X-Token 登录态(`Bearer ${token}`)并要求 tiwen:admin 权限,将 uid 注入请求属性
 */
class AdminAuthMiddleware extends UserAuthMiddlewareUser {
    /**
     * 获取后台管理权限
     */
    protected function getPermissions() {
        return ['tiwen:admin'];
    }
}
