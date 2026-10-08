<?php

declare(strict_types=1);

namespace App\admin\Listener;

use App\admin\Service\ApplicationService;
use Psr\Container\ContainerInterface;
use xphp\Contract\ConfigInterface;
use xphp\Event\Contract\ListenerInterface;
use xphp\Framework\Event\BootApplication;
use xphp\Logger\LoggerFactory;

class BootAdminListener implements ListenerInterface {
    public function __construct(private ContainerInterface $container) {
    }

    /**
     * @return string[] 返回一个关注的事件数组
     */
    public function listen(): array {
        return [
            BootApplication::class
        ];
    }

    /**
     * @param object $event 事件对象
     */
    public function process(object $event): void {

        $config = $this->container->get(ConfigInterface::class);

        $loggerConfig = $config->get('logger', []);
        // 注册一个admin日志通道
        if (! isset($loggerConfig['admin'])) {
            $config->set('logger.admin', [
                'handler' => [
                    'class' => \Monolog\Handler\StreamHandler::class,
                    'constructor' => [
                        'stream' => runtime_path('logs') . 'admin.log',
                        'level' => \Monolog\Level::Debug, // Debug < Info < Notice < Warning < Error < Critical < Alert < Emergency
                    ],
                ],
                'formatter' => [
                    'class' => \Monolog\Formatter\JsonFormatter::class, // 格式化输出
                    'constructor' => [],
                ],
            ]);
        }

        // 存量环境自动补齐 application 表的后台管理扩展字段(进程内只执行一次)
        static $columnsEnsured = false;
        if (!$columnsEnsured) {
            $columnsEnsured = true;
            try {
                $this->container->get(ApplicationService::class)->ensureAdminColumns();
            } catch (\Throwable $e) {
                $this->container->get(LoggerFactory::class)->get('admin', 'admin')->warning('ensureAdminColumns failed: ' . $e->getMessage());
            }
        }
    }
}
