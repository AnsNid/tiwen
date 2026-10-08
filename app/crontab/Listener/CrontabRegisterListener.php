<?php

declare(strict_types=1);

namespace App\crontab\Listener;

use xphp\Contract\ConfigInterface;
use xphp\Contract\StdoutLoggerInterface;
use App\crontab\Crontab;
use App\crontab\CrontabManager;
use App\crontab\LoggerInterface;
use App\crontab\Schedule;
use App\crontab\Service\AnnotationTaskService;
use App\crontab\Service\CrontabTaskService;
use xphp\Event\Contract\ListenerInterface;
use xphp\Framework\Event\BootApplication;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;

class CrontabRegisterListener implements ListenerInterface {

    protected CrontabManager $crontabManager;

    protected ?PsrLoggerInterface $logger = null;

    protected ConfigInterface $config;

    public function __construct(protected ContainerInterface $container) {
        // 依赖在构造函数初始化:CrontabReloadListener 会直接从容器取本实例调 registerCrontabs(),
        // 若只在 process() 初始化,未经过 BootApplication 的实例访问未初始化的 typed property 会 Fatal
        $this->crontabManager = $container->get(CrontabManager::class);
        $this->logger = match (true) {
            $container->has(LoggerInterface::class) => $container->get(LoggerInterface::class),
            $container->has(StdoutLoggerInterface::class) => $container->get(StdoutLoggerInterface::class),
            default => null,
        };
        $this->config = $container->get(ConfigInterface::class);
    }

    /**
     * @return string[] returns the events that you want to listen
     */
    public function listen(): array {
        return [
            BootApplication::class,
        ];
    }

    /**
     * Handle the Event when the event is triggered, all listeners will
     * complete before the event is returned to the EventDispatcher.
     */
    public function process(object $event): void {

        if (! $this->config->get('crontab@config.enable', false)) {
            return;
        }

        $this->registerCrontabs();
    }

    public function registerCrontabs(): void {
        $crontabs = $this->parseCrontabs();
        $environment = (string) $this->config->get('app_env', '');

        foreach ($crontabs as $crontab) {

            if (! $crontab instanceof Crontab) {
                continue;
            }

            if (! $crontab->isEnable()) {
                $this->logger?->warning(sprintf('Crontab %s is disabled.', $crontab->getName()));
                continue;
            }

            if (! $crontab->runsInEnvironment($environment)) {
                $this->logger?->warning(sprintf('Crontab %s is disabled in %s environment.', $crontab->getName(), $environment));
                continue;
            }

            if (! $this->crontabManager->isValidCrontab($crontab)) {
                $this->logger?->warning(sprintf('Crontab %s is invalid.', $crontab->getName()));
                continue;
            }

            if ($this->crontabManager->register($crontab)) {
                $this->logger?->debug(sprintf('Crontab %s have been registered.', $crontab->getName()));
            }
        }
    }

    private function parseCrontabs(): array {

        $configCrontabs = $this->config->get('crontab@config.crontab', []);
        $annotationCrontabs = $this->container->get(AnnotationTaskService::class)->getCrontabs();
        Schedule::load();
        $pendingCrontabs = Schedule::getCrontabs();
        $databaseCrontabs = $this->parseDatabaseCrontabs();
        $databaseNames = $this->parseDatabaseCrontabNames();

        $crontabs = [];
        foreach (array_merge($configCrontabs, $annotationCrontabs, $pendingCrontabs) as $crontab) {
            if (! $crontab instanceof Crontab) {
                continue;
            }
            // 数据库存在同名任务时(无论启停),由数据库行完全接管:启用按库配置运行,暂停即彻底禁用
            if ($crontab->getName() !== null && isset($databaseNames[$crontab->getName()])) {
                continue;
            }
            $crontabs[$crontab->getName()] = $crontab;
        }
        foreach ($databaseCrontabs as $crontab) {
            $crontabs[$crontab->getName()] = $crontab;
        }

        return array_values($crontabs);
    }

    private function parseDatabaseCrontabs(): array {
        try {
            $service = $this->container->get(CrontabTaskService::class);
            return $service->getAllEnabled();
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to load crontab tasks from database: ' . $e->getMessage());
            return [];
        }
    }

    private function parseDatabaseCrontabNames(): array {
        try {
            $service = $this->container->get(CrontabTaskService::class);
            return $service->getAllNames();
        } catch (\Throwable $e) {
            $this->logger?->warning('Failed to load crontab task names from database: ' . $e->getMessage());
            return [];
        }
    }
}
