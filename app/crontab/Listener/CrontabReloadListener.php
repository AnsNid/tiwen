<?php

declare(strict_types=1);

namespace App\crontab\Listener;

use App\crontab\CrontabManager;
use App\crontab\LoggerInterface;
use xphp\Contract\StdoutLoggerInterface;
use xphp\Event\Contract\ListenerInterface;
use xphp\Process\Event\PipeMessage as ProcessPipeMessage;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

class CrontabReloadListener implements ListenerInterface {

    protected ?PsrLoggerInterface $logger = null;

    public function __construct(protected ContainerInterface $container) {
        $this->logger = match (true) {
            $this->container->has(LoggerInterface::class) => $this->container->get(LoggerInterface::class),
            $this->container->has(StdoutLoggerInterface::class) => $this->container->get(StdoutLoggerInterface::class),
            default => null,
        };
    }

    public function listen(): array {
        return [
            ProcessPipeMessage::class,
        ];
    }

    public function process(object $event): void {
        if (!$event instanceof ProcessPipeMessage) {
            return;
        }

        $data = $event->data;
        if (!is_array($data) || ($data['action'] ?? '') !== 'reload') {
            return;
        }

        $this->logger?->info('Crontab reload signal received, refreshing tasks...');

        try {
            $crontabManager = $this->container->get(CrontabManager::class);
            $crontabManager->clear();

            $registerListener = $this->container->get(CrontabRegisterListener::class);
            $registerListener->registerCrontabs();

            $this->logger?->info(sprintf(
                'Crontab reload complete. %d tasks registered.',
                count($crontabManager->getCrontabs())
            ));
        } catch (\Throwable $e) {
            $this->logger?->error('Crontab reload failed: ' . $e->getMessage());
        }
    }
}
