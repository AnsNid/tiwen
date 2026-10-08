<?php

declare(strict_types=1);

namespace App\crontab\Middleware;

use App\user\Middleware\UserAuthMiddleware as UserAuthMiddlewareUser;

/**
 * 跨域请求处理中间件
 * 用于处理文件上传接口的跨域请求
 */
class UserCorsMiddleware extends UserAuthMiddlewareUser {
    /**
     * 设置 permissions 内部
     */
    protected function getPermissions() {
        return ['crontab:admin'];
    }
}
