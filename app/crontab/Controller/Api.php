<?php

declare(strict_types=1);

namespace App\crontab\Controller;

use xphp\HttpServer\Annotation\AutoController;
// 中间件
use xphp\HttpServer\Annotation\Middleware;
use App\crontab\Middleware\CorsMiddleware;

/**
 * 应用控制器
 */
#[AutoController('crontab/api')]
#[Middleware(CorsMiddleware::class, 99)]
class Api extends Authorization {
    // 公共
    use Api\PublicTrait;
    // 用户
    use Api\User;
    // 定时任务
    use Api\Crontab;
    // 登录鉴权
    use \App\user\Traits\Passport;
    // 登录本应用所需权限, 命中其中任一即可; 为空表示登录即可, 不校验权限
    protected function passportPermissions(): array {
        return ['crontab:admin'];
    }
}
