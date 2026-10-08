<?php

declare(strict_types=1);


namespace App\queue\Listener;

use App\queue\AnnotationJob;
use App\queue\Event\AfterHandle;
use App\queue\Event\BeforeHandle;
use App\queue\Event\Event;
use App\queue\Event\FailedHandle;
use App\queue\Event\RetryHandle;
use xphp\Context\Context;
use xphp\Contract\ConfigInterface;
use xphp\Event\Contract\ListenerInterface;
use xphp\Redis\RedisFactory;
use xphp\Redis\RedisProxy;
use Psr\Container\ContainerInterface;
use Throwable;

/**
 * 队列执行链路采集。
 *
 * 每个任务生命周期结束时(成功/失败/重试)以 pipeline 写入:
 *  - {channel}:metrics          累计计数(总数/状态/Job 类)
 *  - {channel}:trace:timeline   最近执行时间线(LTRIM 封顶,内存有界)
 *  - {channel}:trace:slow       慢任务列表(耗时 >= slow_ms)
 *
 * 每任务开销为一次 pipeline(约 5 条命令、1 次 RTT),不影响任务执行本身;
 * 任何采集异常都被吞掉,绝不影响队列消费。
 */
class QueueTraceListener implements ListenerInterface {

    protected const CONTEXT_KEY = 'queue.trace.started_at';

    protected RedisProxy $redis;

    protected bool $enabled = true;

    protected int $timelineSize = 200;

    protected int $slowMs = 60000;

    protected string $defaultChannel = '{queue}';

    public function __construct(ContainerInterface $container) {
        $this->redis = $container->get(RedisFactory::class)->get('default');

        $queueConfig = $container->get(ConfigInterface::class)->get('queue@config', []);
        $trace = (array) ($queueConfig['default']['trace'] ?? []);
        $this->enabled = (bool) ($trace['enabled'] ?? true);
        $this->timelineSize = max(10, (int) ($trace['timeline_size'] ?? 200));
        $this->slowMs = max(0, (int) ($trace['slow_ms'] ?? 60000));
        $channel = (string) ($queueConfig['default']['channel'] ?? '');
        if ($channel !== '') {
            $this->defaultChannel = $channel;
        }
    }

    public function listen(): array {
        return [
            BeforeHandle::class,
            AfterHandle::class,
            FailedHandle::class,
            RetryHandle::class,
        ];
    }

    public function process(object $event): void {
        if (! $this->enabled || ! $event instanceof Event) {
            return;
        }

        if ($event instanceof BeforeHandle) {
            // 协程上下文隔离,记录本任务开始时间,零 I/O
            Context::set(self::CONTEXT_KEY, microtime(true));
            return;
        }

        $status = 'success';
        $reason = null;
        if ($event instanceof FailedHandle) {
            $status = 'failed';
            $reason = mb_substr($event->getThrowable()->getMessage(), 0, 300);
        } elseif ($event instanceof RetryHandle) {
            $status = 'retry';
            $reason = mb_substr($event->getThrowable()->getMessage(), 0, 300);
        }

        try {
            $this->record($event, $status, $reason);
        } catch (Throwable) {
            // 观测数据写入失败不影响任务本身
        }
    }

    protected function record(Event $event, string $status, ?string $reason): void {
        $startedAt = Context::get(self::CONTEXT_KEY);
        $ms = (is_float($startedAt) || is_int($startedAt))
            ? (int) round((microtime(true) - $startedAt) * 1000)
            : null;

        $message = $event->getMessage();
        $job = $message->job();
        $jobClass = get_class($job);
        if ($job instanceof AnnotationJob) {
            $jobClass = sprintf('%s@%s', $job->class, $job->method);
        }

        $entry = [
            'uid' => $message->getUid(),
            'class' => $jobClass,
            'status' => $status,
            'attempts' => $message->getAttempts(),
            'ms' => $ms,
            'time' => date('Y-m-d H:i:s'),
        ];
        if ($reason !== null) {
            $entry['reason'] = $reason;
        }

        $channel = $event->getChannel() ?: $this->defaultChannel;
        $encoded = json_encode($entry, JSON_UNESCAPED_UNICODE);

        $this->redis->pipeline(function ($pipe) use ($encoded, $entry, $status, $jobClass, $channel) {
            $metricsKey = "{$channel}:metrics";
            $timelineKey = "{$channel}:trace:timeline";

            $pipe->hIncrBy($metricsKey, 'total', 1);
            $pipe->hIncrBy($metricsKey, 'status:' . $status, 1);
            $pipe->hIncrBy($metricsKey, 'class:' . $jobClass, 1);
            $pipe->lPush($timelineKey, $encoded);
            $pipe->lTrim($timelineKey, 0, $this->timelineSize - 1);
        });

        if ($this->slowMs > 0 && $entry['ms'] !== null && $entry['ms'] >= $this->slowMs) {
            $this->redis->pipeline(function ($pipe) use ($encoded, $channel) {
                $slowKey = "{$channel}:trace:slow";
                $pipe->lPush($slowKey, $encoded);
                $pipe->lTrim($slowKey, 0, 49);
            });
        }
    }
}
