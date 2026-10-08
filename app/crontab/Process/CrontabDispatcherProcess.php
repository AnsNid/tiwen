<?php

declare(strict_types=1);

namespace App\crontab\Process;

use xphp\Contract\ConfigInterface;
use xphp\Contract\StdoutLoggerInterface;
use xphp\Coordinator\Constants;
use xphp\Coordinator\CoordinatorManager;
use xphp\Process\AbstractProcess;
use xphp\Process\ProcessManager;
use App\crontab\Event\CrontabDispatcherStarted;
use App\crontab\LoggerInterface;
use App\crontab\Scheduler;
use App\crontab\Strategy\StrategyInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface as PsrLoggerInterface;
use Swoole\Server;

class CrontabDispatcherProcess extends AbstractProcess {

    public string $name = 'crontab-dispatcher';

    private $server;

    private ConfigInterface $config;

    private Scheduler $scheduler;

    private StrategyInterface $strategy;

    private ?PsrLoggerInterface $logger = null;

    private int $minuteTimestamp = 0;

    public function __construct(ContainerInterface $container) {
        parent::__construct($container);
        $this->config = $container->get(ConfigInterface::class);
        $this->scheduler = $container->get(Scheduler::class);
        $this->strategy = $container->get(StrategyInterface::class);
        $this->logger = match (true) {
            $container->has(LoggerInterface::class) => $container->get(LoggerInterface::class),
            $container->has(StdoutLoggerInterface::class) => $container->get(StdoutLoggerInterface::class),
            default => null,
        };
    }

    public function bind($server): void {
        $this->server = $server;
        parent::bind($server);
    }

    public function isEnable($server): bool {
        return (bool) $this->config->get('crontab@config.enable', false);
    }

    public function handle(): void {
        $this->event?->dispatch(new CrontabDispatcherStarted());
        while (ProcessManager::isRunning()) {
            if ($this->sleep()) {
                break;
            }
            if ($this->ensureToNextMinuteTimestamp()) {
                break;
            }
            $crontabs = $this->scheduler->schedule();
            while (! $crontabs->isEmpty()) {
                $crontab = $crontabs->dequeue();
                // 单个任务派发异常只记日志,绝不能让调度进程退出导致所有定时任务停摆
                try {
                    $this->strategy->dispatch($crontab);
                } catch (\Throwable $e) {
                    $this->logger?->error(sprintf('Crontab [%s] dispatch failed: %s', $crontab->getName(), $e->getMessage()));
                }
            }
        }
    }

    /**
     * Get the interval of the current second to the next minute.
     */
    public function getInterval(int $currentSecond, float $ms): float {
        $sleep = 60 - $currentSecond - $ms;
        return round($sleep, 3);
    }

    /**
     * @return bool whether the server shutdown
     */
    private function sleep(): bool {
        [$ms, $now] = explode(' ', microtime());
        $current = date('s', (int) $now);

        $sleep = $this->getInterval((int) $current, (float) $ms);
        $this->logger?->debug('Current microtime: ' . $now . ' ' . $ms . '. Crontab dispatcher sleep ' . $sleep . 's.');

        if ($sleep > 0) {
            if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield($sleep)) {
                return true;
            }
        }

        return false;
    }

    private function ensureToNextMinuteTimestamp(): bool {
        while (true) {
            $minuteTimestamp = (int) (time() / 60);
            if ($this->minuteTimestamp === 0 || $minuteTimestamp !== $this->minuteTimestamp) {
                $this->minuteTimestamp = $minuteTimestamp;
                return false;
            }

            $this->logger?->debug('Crontab tasks will be executed at the same minute, but the framework found it, so you don\'t care it.');
            if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield(0.1)) {
                return true;
            }
        }
    }
}
