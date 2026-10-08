<?php

declare(strict_types=1);

namespace App\user\Process;

use xphp\Contract\ConfigInterface;
use xphp\Contract\StdoutLoggerInterface;
use xphp\Coordinator\Constants;
use xphp\Coordinator\CoordinatorManager;
use xphp\Process\AbstractProcess;
use xphp\Process\ProcessManager;
use xphp\Redis\RedisFactory;
use Psr\Container\ContainerInterface;

class TraceDelayRecordProcess extends AbstractProcess {

    public string $name = 'user-trace-delay';

    private StdoutLoggerInterface $logger;
    private $redis;

    private array $config = [
        'batch' => [
            'interval_ms' => 60000,
            'batch_size' => 200,
        ],
        'redis' => [
            'key' => 'user:trace',
            'shards' => 4,
        ],
    ];

    public function __construct(ContainerInterface $container) {
        parent::__construct($container);
        $this->logger = $container->get(StdoutLoggerInterface::class);
        $this->redis = $container->get(RedisFactory::class)->get('default');
        if ($container->has(ConfigInterface::class)) {
            $config = $container->get(ConfigInterface::class)->get('user@config', []);
            $this->config['batch'] = array_replace($this->config['batch'], $config['trace_batch'] ?? []);
            $this->config['redis'] = array_replace($this->config['redis'], $config['trace_redis'] ?? []);
        }
    }

    public function isEnable($server): bool {
        if ($this->container->has(ConfigInterface::class)) {
            $traceDirectWrite = $this->container->get(ConfigInterface::class)->get('user@config.trace_direct_write', false);
            if ($traceDirectWrite) {
                return false;
            }
        }
        return true;
    }

    public function handle(): void {
        while (ProcessManager::isRunning()) {
            try {
                $this->flushTrace();
            } catch (\Throwable $e) {
                $this->logger->error($e->getMessage());
            }
            $intervalMs = (int) ($this->config['batch']['interval_ms'] ?? 60000);
            if ($intervalMs <= 0) {
                $intervalMs = 60000;
            }
            // 可唤醒等待：停止信号能立刻跳出，不用等完整个周期才被强杀在 Redis 调用里
            if (CoordinatorManager::until(Constants::WORKER_EXIT)->yield(max(1, intdiv($intervalMs, 1000)))) {
                break;
            }
        }
    }

    private function getShardKeys(string $baseKey, int $shards): array {
        if ($shards <= 1) {
            return [$baseKey];
        }
        $keys = [];
        for ($i = 0; $i < $shards; $i++) {
            $keys[] = $baseKey . ':sh' . $i;
        }
        return $keys;
    }

    private function popBatchFromKey(string $key, int $limit): array {
        $rows = [];
        if (empty($key) || ! $this->redis->exists($key)) {
            return $rows;
        }
        for ($i = 0; $i < $limit; $i++) {
            $data = $this->redis->lPop($key);
            if ($data === false || $data === null) {
                break;
            }
            $row = json_decode($data, true);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    private function flushTrace(): void {

        $baseKey = (string) ($this->config['redis']['key'] ?? 'user:trace');
        $shards = (int) ($this->config['redis']['shards'] ?? 4);
        $batchSize = (int) ($this->config['batch']['batch_size'] ?? 200);

        $keys = $this->getShardKeys($baseKey, $shards);
        $per = max(1, intdiv($batchSize, max(1, count($keys))));
        $events = [];
        foreach ($keys as $key) {
            $events = array_merge($events, $this->popBatchFromKey($key, $per));
        }
        if (! $events) {
            return;
        }
        $map = [];
        foreach ($events as $ev) {
            $uniqid = $ev['uniqid'] ?? null;
            if (! $uniqid) {
                // 非法数据丢弃
                continue;
            }
            $type = $ev['type'] ?? '';
            $relatedid = $ev['relatedid'] ?? '';
            $uid = (int) ($ev['uid'] ?? 0);
            $uin = (int) ($ev['uin'] ?? 0);
            $num = (int) ($ev['num'] ?? 1);
            if (! isset($map[$uniqid])) {
                $map[$uniqid] = [
                    'type' => $type,
                    'relatedid' => $relatedid,
                    'uid' => $uid,
                    'uin' => $uin,
                    'num' => $num,
                ];
            } else {
                $map[$uniqid]['num'] += $num;
            }
        }
        if (! $map) {
            return;
        }

        try {
            app('user.trace')->storage($map);
        } catch (\Throwable $e) {
            $this->logger->error($e->getMessage());
            $this->pushBackTrace($events);
        }
    }

    private function pushBackTrace(array $events): void {
        $baseKey = (string) ($this->config['redis']['key'] ?? 'user:trace');
        $shards = (int) ($this->config['redis']['shards'] ?? 4);
        foreach ($events as $ev) {
            $basis = ($ev['type'] ?? '') . ':' . ($ev['relatedid'] ?? '');
            $idx = $shards > 1 ? (crc32($basis) % $shards) : null;
            $key = $idx === null ? $baseKey : $baseKey . ':sh' . $idx;
            $this->redis->rPush($key, json_encode($ev, JSON_UNESCAPED_UNICODE));
        }
    }
}
