<?php

declare(strict_types=1);

namespace App\crontab\Middleware;

/**
 * 跨域请求处理中间件
 */
class CorsMiddleware extends \xphp\HttpServer\Middleware\CorsMiddleware {

    /**
     * 允许的来源沿用模块 sys 配置(逗号分隔域名, * 为不限制)
     */
    protected function options(): array {
        return [
            'allow_origins' => (string) (xphp('config')->sysget('config.crontab_cors_allow_origins', '*') ?: '*'),
        ];
    }
}
