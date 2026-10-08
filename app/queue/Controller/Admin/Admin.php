<?php

namespace App\queue\Controller\Admin;

use App\admin\Authorization;
use App\queue\Driver\DriverFactory;
use xphp\Redis\RedisFactory;

/**
 *  队列管理, 后台数据管理
 */

class Admin extends Authorization {

    /**
     * 队列池列表 (queue 配置的键即池名)
     */
    public function pools() {

        $config = $this->config->get('queue@config', []);
        $pools = [];
        foreach ((array) $config as $name => $item) {
            if (!is_array($item)) {
                continue;
            }
            $pools[] = [
                'name' => (string) $name,
                'driver' => basename(str_replace('\\', '/', (string) ($item['driver'] ?? ''))),
                'processes' => (int) ($item['processes'] ?? 0),
                'channel' => (string) ($item['channel'] ?? ''),
            ];
        }

        show_json([
            'code' => 200,
            'data' => $pools,
        ]);
    }

    /**
     * 队列信息统计
     * 获取队列的等待任务数、延迟任务数、失败任务数、超时任务数和保留任务数。
     * 请求 /queue/admin/info  ;每 5 秒更新一次
     * @param string $pool 队列池
     * @return array 队列信息 {"code":200,"data":{"waiting":0,"delayed":0,"failed":165,"timeout":0,"reserved":0}}
     */
    public function info() {
        $pool = input('pool', 'default', 'trim');
        $driver = $this->container->get(DriverFactory::class)->get($pool);
        $base = $driver->info();
        // reserved 只取数量,避免每次轮询全量反序列化任务
        $reserved = $driver->getQueueCount('reserved');
        show_json([
            'code' => 200,
            'data' => array_merge($base, [
                'reserved' => $reserved,
            ]),
        ]);
    }

    /**
     * 队列执行链路观测
     * 返回吞吐计数、最近执行时间线与慢任务列表(均为 Redis O(1) 读取)。
     * 请求 /queue/admin/trace
     * @param string $pool 队列池
     * @param int $start 时间线起始位置(0 = 最新)
     * @param int $limit 时间线获取数量
     * @return array {metrics:{total,status:success,...,class:XxxJob}, timeline:[{uid,class,status,attempts,ms,time,reason}], slow:[...], total}
     */
    public function trace() {
        $pool = input('pool', 'default', 'trim');
        $start = max(0, (int) input('start', 0));
        $limit = min(200, max(1, (int) input('limit', 50)));

        $factory = $this->container->get(DriverFactory::class);
        $driver = $factory->get($pool);
        $driverConfig = $factory->getConfig($pool);
        $redis = $this->container->get(RedisFactory::class)->get($driverConfig['redis']['pool'] ?? 'default');
        $channel = $driver->getQueueChannel();

        $decode = static function (mixed $rows): array {
            if (!is_array($rows)) {
                return [];
            }
            $items = [];
            foreach ($rows as $row) {
                $item = json_decode((string) $row, true);
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            return $items;
        };

        $metrics = $redis->hGetAll("{$channel}:metrics");
        $timeline = $redis->lRange("{$channel}:trace:timeline", $start, $start + $limit - 1);
        $slow = $redis->lRange("{$channel}:trace:slow", 0, 49);

        show_json([
            'code' => 200,
            'data' => [
                'metrics' => is_array($metrics) ? $metrics : [],
                'timeline' => $decode($timeline),
                'slow' => $decode($slow),
                'total' => $redis->lLen("{$channel}:trace:timeline"),
            ],
        ]);
    }

    /**
     * 清空执行链路观测数据(累计计数/最近执行流水/慢任务列表)
     * 只删除观测 key,队列中的任务不受影响。
     * 请求 /queue/admin/traceClear
     * @param string $pool 队列池
     * @log
     */
    public function traceClear() {
        $pool = input('pool', 'default', 'trim');

        $factory = $this->container->get(DriverFactory::class);
        $driver = $factory->get($pool);
        $driverConfig = $factory->getConfig($pool);
        $redis = $this->container->get(RedisFactory::class)->get($driverConfig['redis']['pool'] ?? 'default');
        $channel = $driver->getQueueChannel();

        $deleted = $redis->del(
            "{$channel}:metrics",
            "{$channel}:trace:timeline",
            "{$channel}:trace:slow"
        );

        show_json([
            'code' => 200,
            'message' => '已清空执行记录',
            'data' => [
                'deleted' => (int) $deleted,
            ],
        ]);
    }

    /**
     * 队列任务列表
     * 获取队列的任务列表，包括等待任务、延迟任务、失败任务、超时任务和保留任务。
     * 请求 /queue/admin/lists
     * @param string $pool 队列池
     * @param string $type 队列类型 waiting,delayed,failed,timeout,reserved
     * @param int $start 起始位置
     * @param int $limit 每页数量
     * @return array 队列任务列表 { "code": 200, "data": { "queue_type": "failed", "total_count": 165, "jobs": [ { "job_class": "App\\api\\Jobs\\OfferAssistant", "attempts": 1, "max_attempts": 1, "data": [], } ] } }
     */
    public function lists() {

        $pool = input('pool', 'default', 'trim');
        $type = input('type', 'waiting', 'trim');
        $start = (int) input('start', 0);
        $limit = (int) input('limit', 10);
        $driver = $this->container->get(DriverFactory::class)->get($pool);
        $details = $driver->getQueueDetails($type, $start, $limit);
        show_json([
            'code' => 200,
            'data' => $details,
        ]);
    }

    /**
     * 队列任务重载
     * 重载队列中的任务到等待队列。
     * 请求 /queue/admin/reload 
     * @param string $pool 队列池
     * @param string $queue 队列类型 failed,timeout,reserved
     * @log
     */
    public function reload() {
        $pool = input('pool', 'default', 'trim');
        $queue = input('queue', 'failed', 'trim');
        $driver = $this->container->get(DriverFactory::class)->get($pool);
        $num = $driver->reload($queue);
        show_json([
            'code' => 200,
            'message' => '已重载到waiting队列',
            'data' => [
                'reloaded' => $num,
                'queue' => $queue,
            ],
        ]);
    }

    /**
     * 队列任务清空
     * 清空队列中的所有任务。
     * 请求 /queue/admin/flush
     * @param string $pool 队列池
     * @param string $queue 队列类型 failed,timeout,reserved
     * @log
     */
    public function flush() {
        $pool = input('pool', 'default', 'trim');
        $queue = input('queue', 'failed', 'trim');
        $driver = $this->container->get(DriverFactory::class)->get($pool);
        $ok = $driver->flush($queue);
        show_json([
            'code' => $ok ? 200 : 500,
            'message' => $ok ? '已清空队列' : '清空失败',
            'data' => [
                'queue' => $queue,
            ],
        ]);
    }
}
