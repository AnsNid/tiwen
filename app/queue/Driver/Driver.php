<?php

declare(strict_types=1);


namespace App\queue\Driver;

use App\queue\Event\AfterHandle;
use App\queue\Event\BeforeHandle;
use App\queue\Event\Event;
use App\queue\Event\FailedHandle;
use App\queue\Event\QueueLength;
use App\queue\Event\RetryHandle;
use App\queue\MessageInterface;
use xphp\Codec\Packer\PhpSerializerPacker;
use xphp\Collection\Arr;
use xphp\Contract\PackerInterface;
use xphp\Contract\StdoutLoggerInterface;
use xphp\Coroutine\Concurrent;
use xphp\Process\ProcessManager;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function xphp\Coroutine\parallel;

abstract class Driver implements DriverInterface {

    protected PackerInterface $packer;

    protected ?EventDispatcherInterface $event = null;

    protected ?Concurrent $concurrent = null;

    protected int $lengthCheckCount = 500;

    /**
     * Channel name for trace/observability consumers.
     */
    protected string $queueChannel = '';

    public function __construct(protected ContainerInterface $container, protected array $config) {
        $this->packer = $container->get($config['packer'] ?? PhpSerializerPacker::class);
        $this->event = $container->get(EventDispatcherInterface::class);

        $concurrentLimit = $config['concurrent']['limit'] ?? null;
        if ($concurrentLimit && is_numeric($concurrentLimit)) {
            $this->concurrent = new Concurrent((int) $concurrentLimit);
        }
    }

    public function getConfig(): array {
        return $this->config;
    }

    public function getQueueChannel(): string {
        return $this->queueChannel;
    }

    protected function setQueueChannel(string $queueChannel): void {
        $this->queueChannel = $queueChannel;
    }

    public function consume(): void {
        $messageCount = 0;
        $maxMessages = Arr::get($this->config, 'max_messages', 0);

        while (ProcessManager::isRunning()) {
            try {
                /** @var MessageInterface $message */
                [$data, $message] = $this->pop();

                if ($data === false) {
                    continue;
                }

                $callback = $this->getCallback($data, $message);

                if ($this->concurrent) {
                    $this->concurrent->create($callback);
                } else {
                    parallel([$callback]);
                }

                if ($messageCount % $this->lengthCheckCount === 0) {
                    $this->checkQueueLength();
                }

                if ($maxMessages > 0 && $messageCount >= $maxMessages) {
                    break;
                }
            } catch (Throwable $exception) {
                $logger = $this->container->get(StdoutLoggerInterface::class);
                $logger->error((string) $exception);
            } finally {
                ++$messageCount;
            }
        }
    }

    protected function checkQueueLength(): void {
        $info = $this->info();
        foreach ($info as $key => $value) {
            $this->event?->dispatch(new QueueLength($this, $key, $value));
        }
    }

    /**
     * Attach the queue channel to the event for trace consumers.
     */
    protected function tagChannel(Event $event): Event {
        if ($this->queueChannel !== '') {
            $event->setChannel($this->queueChannel);
        }

        return $event;
    }

    /**
     * @param mixed $data
     * @param MessageInterface $message
     */
    protected function getCallback($data, $message): callable {
        return function () use ($data, $message) {
            try {
                if ($message instanceof MessageInterface) {
                    // 在任务真正开始执行时设置超时时间
                    $this->setReservedTimeout($data, $message);
                    $this->event?->dispatch($this->tagChannel(new BeforeHandle($message)));
                    $message->job()->handle();
                    $this->event?->dispatch($this->tagChannel(new AfterHandle($message)));
                }

                $this->ack($data);
            } catch (Throwable $ex) {
                if (isset($message, $data)) {
                    if ($message->attempts() && $this->remove($data)) {
                        $this->event?->dispatch($this->tagChannel(new RetryHandle($message, $ex)));
                        $this->retry($message);
                    } else {
                        $this->event?->dispatch($this->tagChannel(new FailedHandle($message, $ex)));
                        $this->fail($data, sprintf('%s: %s', get_class($ex), $ex->getMessage()));
                        $message->job()->fail($ex);
                    }
                }
            }
        };
    }

    /**
     * Handle a job again some seconds later.
     */
    abstract protected function retry(MessageInterface $message): bool;

    /**
     * Set timeout for reserved job.
     */
    abstract protected function setReservedTimeout(mixed $data, ?MessageInterface $message = null): void;

    /**
     * Remove data from reserved queue.
     */
    abstract protected function remove(mixed $data): bool;
}
