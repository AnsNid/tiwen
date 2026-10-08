<?php

declare(strict_types=1);


namespace App\crontab\Strategy;

use xphp\Contract\StdoutLoggerInterface;
use xphp\Server\ServerFactory;
use App\crontab\Crontab;
use App\crontab\LoggerInterface;
use App\crontab\PipeMessage;
use Psr\Container\ContainerInterface;
use Swoole\Server;

class WorkerStrategy extends AbstractStrategy {

    protected ServerFactory $serverFactory;

    protected int $currentWorkerId = -1;

    public function __construct(ContainerInterface $container) {
        $this->serverFactory = $container->get(ServerFactory::class);

        parent::__construct($container);
    }

    public function dispatch(Crontab $crontab): void {
        $logger = match (true) {
            $this->container->has(LoggerInterface::class) => $this->container->get(LoggerInterface::class),
            $this->container->has(StdoutLoggerInterface::class) => $this->container->get(StdoutLoggerInterface::class),
            default => null,
        };
        $server = $this->serverFactory->getServer()->getServer();

        if (! $server instanceof Server) {
            $logger?->warning('Cannot dispatch crontab, use CoroutineStrategy if run in coroutine style server.');
            return;
        }
        if ($crontab->getType() === 'closure') {
            $logger?->warning('Closure type crontab is only supported in CoroutineStrategy.');
            return;
        }

        $workerId = $this->getNextWorkerId($server);
        $server->sendMessage(new PipeMessage(
            'callback',
            [Executor::class, 'execute'],
            $crontab
        ), $workerId);
    }

    protected function getNextWorkerId(Server $server): int {
        ++$this->currentWorkerId;
        $maxWorkerId = $server->setting['worker_num'] - 1;
        if ($this->currentWorkerId > $maxWorkerId) {
            $this->currentWorkerId = 0;
        }
        return $this->currentWorkerId;
    }
}
