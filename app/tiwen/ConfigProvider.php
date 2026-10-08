<?php

declare(strict_types=1);

namespace App\tiwen;

/**
 * 配置提供者
 * 共享主服务（无独立端口），注册注解扫描、异常处理器与 Stripe 事件监听
 */
class ConfigProvider {

    public function __invoke(): array {
        return [
            // 注册注解扫描路径
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'exceptions' => [
                'handler' => [
                    'tiwen' => [
                        \App\tiwen\Exception\TiwenExceptionHandler::class,
                    ],
                ],
            ],
            'listeners' => [
                \App\tiwen\Listener\StripeEventListener::class,
            ],
        ];
    }
}
