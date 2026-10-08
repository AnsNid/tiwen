<?php

declare(strict_types=1);

namespace App\tiwen\Middleware;

/**
 * 跨域请求处理中间件(公共, 无鉴权)
 */
class CorsMiddleware extends \xphp\HttpServer\Middleware\CorsMiddleware {

    /**
     * 允许的来源沿用 agent 模块的 sys 配置(与历史实现保持一致)
     */
    protected function options(): array {
        return [
            'allow_origins' => (string) (xphp('config')->sysget('config.agent_cors_allow_origins', '*') ?: '*'),
        ];
    }
}
