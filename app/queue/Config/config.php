<?php

declare(strict_types=1);

use App\queue\Driver\RedisDriver;

return [
    'default' => [
        'driver' => RedisDriver::class,
        'redis' => [
            'pool' => 'default',
        ],
        'channel' => '{queue}',
        'timeout' => 5,
        'retry_seconds' => 10,
        'handle_timeout' => 180,
        'processes' => 2,
        'concurrent' => [
            'limit' => 10,
        ],
        'max_messages' => 0,
        // 执行链路观测:任务结束时写入计数与最近执行时间线(Redis pipeline,内存有界)
        'trace' => [
            'enabled' => true,
            'timeline_size' => 200,
            'slow_ms' => 60000,
        ],
    ],
];
