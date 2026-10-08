<?php

declare(strict_types=1);


namespace App\queue\Listener;

use App\queue\AnnotationJob;
use App\queue\Event\AfterHandle;
use App\queue\Event\BeforeHandle;
use App\queue\Event\Event;
use App\queue\Event\FailedHandle;
use App\queue\Event\RetryHandle;
use xphp\Event\Contract\ListenerInterface;
use xphp\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use xphp\Contract\StdoutLoggerInterface;

class QueueHandleListener implements ListenerInterface {

    protected LoggerInterface $logger;

    public function __construct(ContainerInterface $container) {
        $this->logger = $container->get(StdoutLoggerInterface::class);
    }

    public function listen(): array {
        return [
            AfterHandle::class,
            BeforeHandle::class,
            FailedHandle::class,
            RetryHandle::class,
        ];
    }

    public function process(object $event): void {
        if ($event instanceof Event && $event->getMessage()->job()) {
            $job = $event->getMessage()->job();
            $jobClass = get_class($job);
            if ($job instanceof AnnotationJob) {
                $jobClass = sprintf('Job[%s@%s]', $job->class, $job->method);
            }
            $date = date('Y-m-d H:i:s');
            switch (true) {
                case $event instanceof BeforeHandle:
                    $this->logger->info(sprintf('[%s] Processing %s.', $date, $jobClass));
                    break;
                case $event instanceof AfterHandle:
                    $this->logger->info(sprintf('[%s] Processed %s.', $date, $jobClass));
                    break;
                case $event instanceof FailedHandle:
                    $this->logger->error(sprintf('[%s] Failed %s.', $date, $jobClass));
                    $this->logger->error((string) $event->getThrowable());
                    break;
                case $event instanceof RetryHandle:
                    $this->logger->warning(sprintf('[%s] Retried %s.', $date, $jobClass));
                    break;
            }
        }
    }
}
