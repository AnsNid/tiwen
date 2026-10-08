<?php

declare(strict_types=1);

namespace App\admin;

class ConfigProvider {
    public function __invoke(): array {
        return [
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'dependencies' => [
                // 依赖注入配置
            ],
            'commands' => [
                // 命令行配置
                \App\admin\Command\AppInstallCommand::class,
            ],
            'listeners' => [
                // 事件监听器配置
                \App\admin\Listener\BootAdminListener::class,
                \App\admin\Listener\AuthListener::class,

            ],
        ];
    }
}
