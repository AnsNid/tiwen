<?php

declare(strict_types=1);

namespace App\stripe\Middleware;

/**
 * 跨域请求处理中间件
 * 用于处理支付相关接口的跨域请求
 */
class CorsMiddleware extends \xphp\HttpServer\Middleware\CorsMiddleware {

    /**
     * 允许的来源沿用 filesystem 模块的 sys 配置(与历史实现保持一致)
     */
    protected function options(): array {
        return [
            'allow_origins' => (string) (xphp('config')->sysget('config.filesystem_cors_allow_origins', '*') ?: '*'),
        ];
    }
}
