<?php

declare(strict_types=1);


namespace App\mail;

use Composer\InstalledVersions;
use Psr\Container\ContainerInterface;

class ConfigProvider {
    public function __invoke(): array {
        return [
            'dependencies' => [
                Contract\Mailer::class => Factory\MailerFactory::class,
                Markdown::class => Factory\MarkdownFactory::class,
                Contract\Factory::class => fn(ContainerInterface $container) => $container->get(MailManager::class),
                // 视图工厂: mail 移植自 Laravel, 依赖 xphp\ViewEngine 组件
                \xphp\ViewEngine\Contract\FactoryInterface::class => \xphp\ViewEngine\Factory::class,
            ],
            'publish' => [
                [
                    'id' => 'resources',
                    'description' => 'The resources for mail.',
                    'source' => __DIR__ . '/publish/resources/views/',
                    'destination' => BASE_PATH . '/storage/view/mail/',
                ],
            ],
            'commands' => [
                // MailCommand removed due to missing dependencies
            ],
        ];
    }
}
