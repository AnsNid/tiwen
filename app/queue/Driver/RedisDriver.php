<?php

declare(strict_types=1);


namespace App\queue\Driver;

use App\queue\Event\FailedHandle;
use App\queue\Exception\InvalidQueueException;
use App\queue\JobInterface;
use App\queue\JobMessage;
use App\queue\MessageInterface;
use xphp\Contract\StdoutLoggerInterface;
use xphp\Redis\RedisFactory;
use xphp\Redis\RedisProxy;
use Psr\Container\ContainerInterface;
use Throwable;

use function xphp\Support\make;

class RedisDriver extends Driver {

    protected RedisProxy $redis;

    protected ChannelConfig $channel;

    /**
     * Max polling time.
     */
    protected int $timeout;

    /**
     * Retry delay time.
     */
    protected array|int $retrySeconds;

    /**
     * Handle timeout.
     */
    protected int $handleTimeout;

    public function __construct(ContainerInterface $container, $config) {
        parent::__construct($container, $config);
        $channel = $config['channel'] ?? 'queue';
        $this->redis = $container->get(RedisFactory::class)->get($config['redis']['pool'] ?? 'default');
        $this->timeout = $config['timeout'] ?? 5;
        $this->retrySeconds = $config['retry_seconds'] ?? 10;
        $this->handleTimeout = $config['handle_timeout'] ?? 10;

        $this->channel = make(ChannelConfig::class, ['channel' => $channel]);
        $this->setQueueChannel($channel);
    }

    public function push(JobInterface $job, int $delay = 0): bool {
        $message = make(JobMessage::class, [$job]);
        $data = $this->packer->pack($message);

        if ($delay === 0) {
            return (bool) $this->redis->lPush($this->channel->getWaiting(), $data);
        }

        return (bool) $this->redis->zAdd($this->channel->getDelayed(), time() + $delay, $data);
    }

    public function pushAll(array $jobs, int $delay = 0): bool {
        $messages = array_map(fn($job) => $this->packer->pack(make(JobMessage::class, [$job])), $jobs);

        if ($delay === 0) {
            $this->redis->pipeline(function ($pipe) use ($messages) {
                foreach ($messages as $message) {
                    $pipe->lPush($this->channel->getWaiting(), $message);
                }
            });
            return true;
        }

        $this->redis->pipeline(function ($pipe) use ($messages, $delay) {
            foreach ($messages as $message) {
                $pipe->zAdd($this->channel->getDelayed(), time() + $delay, $message);
            }
        });

        return true;
    }

    public function delete(JobInterface $job): bool {
        $message = make(JobMessage::class, [$job]);
        $data = $this->packer->pack($message);

        return (bool) $this->redis->zRem($this->channel->getDelayed(), $data);
    }

    public function pop(): array {
        $this->move($this->channel->getDelayed(), $this->channel->getWaiting());
        // reserved 超时回收计入尝试次数, 超过上限转失败队列, 避免长任务被无限重复执行
        $this->move($this->channel->getReserved(), $this->channel->getWaiting(), true);

        $res = $this->redis->brPop($this->channel->getWaiting(), $this->timeout);
        if (! isset($res[1])) {
            return [false, null];
        }

        $data = $res[1];

        // 损坏或 Job 类已不存在的消息直接转入失败队列,否则会在等待队列无限循环
        try {
            $message = $this->packer->unpack($data);
        } catch (Throwable $exception) {
            $this->failDirect($data, sprintf('Message unpack failed: %s', $exception->getMessage()));
            return [false, null];
        }
        if (! $message) {
            return [false, null];
        }

        return [$data, $message];
    }

    public function ack(mixed $data): bool {
        return $this->remove($data);
    }

    public function fail(mixed $data, ?string $reason = null): bool {
        if ($this->remove($data)) {
            $this->redis->lPush($this->channel->getFailed(), (string) $data);
            // 使用 Hash 单独存储失败原因，不改变原始数据格式
            if ($reason !== null) {
                $this->redis->hSet($this->channel->getFailed() . ':meta', md5($data), json_encode([
                    'failed_reason' => $reason,
                    'failed_time' => date('Y-m-d H:i:s'),
                ], JSON_UNESCAPED_UNICODE));
            }
            return true;
        }
        return false;
    }

    public function reload(?string $queue = null): int {
        $channel = $this->channel->getFailed();
        if ($queue) {
            if (! in_array($queue, ['timeout', 'failed', 'reserved'])) {
                throw new InvalidQueueException(sprintf('Queue %s is not supported.', $queue));
            }
            $channel = $this->channel->get($queue);
        }

        // reserved 是 ZSet,不能 rPop:整体取出后原样重入队(不改变 attempts,
        // 与按钮"全部重载到 Waiting"语义一致;运行中的原副本完成时 ack 只会 zRem 落空,无副作用)
        if ($queue === 'reserved') {
            $num = 0;
            $members = $this->redis->zRange($channel, 0, -1);
            foreach ((is_array($members) ? $members : []) as $job) {
                if (! $this->redis->zRem($channel, (string) $job)) {
                    continue;
                }
                $this->redis->lPush($this->channel->getWaiting(), (string) $job);
                ++$num;
            }
            return $num;
        }

        $cleanMeta = $channel === $this->channel->getFailed();

        $num = 0;
        while (($data = $this->redis->rPop($channel)) !== false) {
            // 损坏或 Job 类已不存在的消息(僵尸)反序列化会抛 TypeError,
            // 直接放弃重载,否则重入队后调用方法抛错且永远留在 reserved,形成无限重入队。
            try {
                $message = $this->packer->unpack($data);
            } catch (Throwable) {
                $orphan = $this->orphanJobInfo($data);
                $this->container->get(StdoutLoggerInterface::class)->warning(
                    sprintf('[queue] Dropped unreadable message (job class hint: %s).', $orphan['job_class'])
                );
                if ($cleanMeta) {
                    $this->redis->hDel($channel . ':meta', md5($data));
                }
                continue;
            }

            if (! $message instanceof MessageInterface) {
                if ($cleanMeta) {
                    $this->redis->hDel($channel . ':meta', md5($data));
                }
                continue;
            }

            $this->redis->lPush($this->channel->getWaiting(), $data);
            // 如果是从失败队列重试，删除对应的元数据
            if ($cleanMeta) {
                $this->redis->hDel($channel . ':meta', md5($data));
            }
            ++$num;
        }
        return $num;
    }

    /**
     * Flush the queue.
     */
    public function flush(?string $queue = null): bool {
        $channel = $this->channel->getFailed();
        if ($queue) {
            $channel = $this->channel->get($queue);
        }
        $result = $this->redis->del($channel);
        // 如果清空的是失败队列，同时清空元数据
        if ($channel === $this->channel->getFailed()) {
            $this->redis->del($channel . ':meta');
        }
        return (bool) $result;
    }

    public function info(): array {
        return [
            'waiting' => $this->redis->lLen($this->channel->getWaiting()),
            'delayed' => $this->redis->zCard($this->channel->getDelayed()),
            'failed' => $this->redis->lLen($this->channel->getFailed()),
            'timeout' => $this->redis->lLen($this->channel->getTimeout()),
        ];
    }

    /**
     * 获取队列中的详细任务信息
     * @param string $queueType 队列类型: failed, timeout, waiting, delayed, reserved
     * @param int $start 起始位置
     * @param int $limit 获取数量限制
     * @return array
     */
    public function getQueueDetails(string $queueType, int $start = 0, int $limit = 10): array {
        $channel = $this->getQueueChannelKey($queueType);

        $jobs = [];

        if (in_array($queueType, ['delayed', 'reserved'])) {
            // 对于有序集合，使用 zrange
            $data = $this->redis->zRange($channel, $start, $start + $limit - 1, true);
            foreach ($data as $jobData => $score) {
                $message = $this->unpackSafely($jobData);
                if ($message instanceof MessageInterface) {
                    $job = $message->job();
                    $jobs[] = [
                        'job_class' => get_class($job),
                        'attempts' => $message->getAttempts(),
                        'max_attempts' => $job->getMaxAttempts(),
                        'score' => $score, // 对于delayed是执行时间，对于reserved是超时时间
                        'data' => $this->getJobData($job),
                        'orphan' => false,
                        'raw_data' => $jobData
                    ];
                } else {
                    $jobs[] = $this->orphanJobInfo($jobData);
                }
            }
        } else {
            // 对于列表，使用 lrange
            $data = $this->redis->lRange($channel, $start, $start + $limit - 1);

            // 如果是失败队列，批量获取失败元数据
            $failedMeta = [];
            $metaChannel = $this->channel->getFailed() . ':meta';
            if ($queueType === 'failed' && !empty($data)) {
                $metaKeys = array_map(fn($d) => md5($d), $data);

                // 使用 hMGet 批量获取
                $metaValues = $this->redis->hMGet($metaChannel, $metaKeys);

                // 兼容不同返回格式：索引数组或关联数组
                $indexedValues = is_array($metaValues) ? array_values($metaValues) : [];

                foreach ($metaKeys as $i => $key) {
                    $value = $indexedValues[$i] ?? null;
                    if (!empty($value)) {
                        $failedMeta[$key] = json_decode($value, true);
                    }
                }
            }

            foreach ($data as $jobData) {
                $message = $this->unpackSafely($jobData);
                if ($message instanceof MessageInterface) {
                    $job = $message->job();
                    $jobInfo = [
                        'job_class' => get_class($job),
                        'attempts' => $message->getAttempts(),
                        'max_attempts' => $job->getMaxAttempts(),
                        'data' => $this->getJobData($job),
                        'orphan' => false,
                        'raw_data' => $jobData,
                    ];
                } else {
                    $jobInfo = $this->orphanJobInfo($jobData);
                }

                // 如果是失败队列且有元数据，添加失败原因和时间
                if ($queueType === 'failed') {
                    $meta = $failedMeta[md5($jobData)] ?? [];
                    $jobInfo['failed_reason'] = $meta['failed_reason'] ?? $jobInfo['failed_reason'] ?? null;
                    $jobInfo['failed_time'] = $meta['failed_time'] ?? null;
                }
                $jobs[] = $jobInfo;
            }
        }

        return [
            'queue_type' => $queueType,
            'total_count' => $this->getQueueCount($queueType),
            'jobs' => $jobs
        ];
    }

    /**
     * Resolve the redis key for a queue type.
     */
    private function getQueueChannelKey(string $queueType): string {
        return match ($queueType) {
            'failed' => $this->channel->getFailed(),
            'timeout' => $this->channel->getTimeout(),
            'waiting' => $this->channel->getWaiting(),
            'delayed' => $this->channel->getDelayed(),
            'reserved' => $this->channel->getReserved(),
            default => throw new InvalidQueueException(sprintf('Queue type %s is not supported.', $queueType))
        };
    }

    /**
     * 反序列化消息:损坏或 Job 类已不存在的消息(僵尸)会抛 TypeError/Error,统一吞掉返回 null。
     */
    private function unpackSafely(string $data): ?MessageInterface {
        try {
            $message = $this->packer->unpack($data);
        } catch (Throwable) {
            return null;
        }

        return $message instanceof MessageInterface ? $message : null;
    }

    /**
     * 僵尸/损坏消息的展示信息:从原始 payload 里尽力还原 Job 类名。
     */
    private function orphanJobInfo(string $jobData): array {
        $class = 'unknown';
        if (preg_match_all('/O:\d+:"([^"]+)"/', $jobData, $matches)) {
            foreach ($matches[1] as $candidate) {
                if ($candidate !== JobMessage::class) {
                    $class = $candidate;
                    break;
                }
            }
        }

        return [
            'job_class' => $class,
            'attempts' => null,
            'max_attempts' => null,
            'data' => [],
            'orphan' => true,
            'failed_reason' => '消息反序列化失败,Job 类可能已不存在或数据损坏',
            'raw_data' => $jobData,
        ];
    }

    /**
     * Resolve the redis key for a queue type.
     */
    public function getQueueCount(string $queueType): int {
        $channel = $this->getQueueChannelKey($queueType);

        if (in_array($queueType, ['delayed', 'reserved'])) {
            return $this->redis->zCard($channel);
        } else {
            return $this->redis->lLen($channel);
        }
    }

    /**
     * 提取Job的关键数据
     */
    private function getJobData($job): array {
        $data = [];
        $reflection = new \ReflectionClass($job);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PRIVATE);
        foreach ($properties as $property) {
            $name = $property->getName();
            $value = $property->getValue($job);
            if (is_string($value) && strlen($value) > 500) {
                $value = substr($value, 0, 500) . '...';
            }
            if (is_object($value)) {
                $value = 'object:' . get_class($value);
            } elseif (is_resource($value)) {
                $value = 'resource';
            }
            $data[$name] = $value;
        }
        return $data;
    }

    /**
     * 重试任务
     */
    protected function retry(MessageInterface $message): bool {
        $data = $this->packer->pack($message);

        $delay = time() + $this->getRetrySeconds($message->getAttempts());

        return (bool) $this->redis->zAdd($this->channel->getDelayed(), $delay, $data);
    }

    /**
     * 获取重试延迟时间
     */
    protected function getRetrySeconds(int $attempts): int {
        if (! is_array($this->retrySeconds)) {
            return $this->retrySeconds;
        }
        if (empty($this->retrySeconds)) {
            return 10;
        }
        return $this->retrySeconds[$attempts - 1] ?? end($this->retrySeconds);
    }

    /**
     * Remove data from reserved queue.
     */
    protected function remove(mixed $data): bool {
        return (bool) $this->redis->zRem($this->channel->getReserved(), (string) $data);
    }

    protected function setReservedTimeout(mixed $data, ?MessageInterface $message = null): void {
        $timeout = $this->handleTimeout;

        // Job 可覆盖执行超时预算(长耗时任务如文件同步)
        $job = $message?->job();
        if ($job !== null && method_exists($job, 'getHandleTimeout')) {
            $jobTimeout = $job->getHandleTimeout();
            if (is_int($jobTimeout) && $jobTimeout > 0) {
                $timeout = $jobTimeout;
            }
        }

        $this->redis->zadd($this->channel->getReserved(), time() + $timeout, $data);
    }

    /**
     * Move message to the waiting queue.
     *
     * @param bool $countAttempt 是否计入尝试次数(reserved 超时回收时为 true):
     *                           attempts 递增后重入队, 超过 maxAttempts 直接转失败队列,
     *                           防止长耗时任务被无限重复执行且后台不可见.
     */
    protected function move(string $from, string $to, bool $countAttempt = false): void {
        $now = time();
        $options = ['LIMIT' => [0, 100]];
        if ($expired = $this->redis->zrevrangebyscore($from, (string) $now, '-inf', $options)) {
            foreach ($expired as $job) {
                if (! $this->redis->zRem($from, $job)) {
                    continue;
                }

                if ($countAttempt) {
                    $this->requeueExpired($job, $to);
                    continue;
                }

                $this->redis->lPush($to, $job);
            }
        }
    }

    /**
     * reserved 超时的任务: 计入尝试次数, 仍在预算内则重入队, 否则转失败队列.
     */
    protected function requeueExpired(string $job, string $to): void {
        try {
            $message = $this->packer->unpack($job);
        } catch (Throwable) {
            $message = null;
        }

        if (! $message instanceof MessageInterface) {
            $this->failDirect($job, 'Handle timeout: message unreadable after reserved timeout.');
            return;
        }

        $attempts = $message->getAttempts();
        $jobClass = get_class($message->job());

        if ($message->attempts()) {
            // 未超上限: 带新的尝试次数重入队(消息串变化, ack/fail 不会误删他人 reserved)
            $this->redis->lPush($to, $this->packer->pack($message));
            $this->container->get(StdoutLoggerInterface::class)->warning(sprintf(
                '[queue] %s (uid %s) exceeded handle timeout, requeued as attempt %d.',
                $jobClass,
                $message->getUid(),
                $attempts + 1
            ));
            return;
        }

        $budget = $this->handleTimeout;
        $jobObj = $message->job();
        if (method_exists($jobObj, 'getHandleTimeout')) {
            $jobTimeout = $jobObj->getHandleTimeout();
            if (is_int($jobTimeout) && $jobTimeout > 0) {
                $budget = $jobTimeout;
            }
        }

        $this->failDirect(
            $this->packer->pack($message),
            sprintf('Handle timeout: exceeded %ds per attempt after %d attempts.', $budget, $attempts + 1)
        );
    }

    /**
     * 直接将原始消息写入失败队列(不依赖 reserved zRem 前置条件).
     */
    protected function failDirect(string $data, string $reason): void {
        $this->redis->lPush($this->channel->getFailed(), $data);
        $this->redis->hSet($this->channel->getFailed() . ':meta', md5($data), json_encode([
            'failed_reason' => $reason,
            'failed_time' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE));
    }
}
