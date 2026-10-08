<?php

declare(strict_types=1);

namespace App\crontab;

use App\crontab\Command\RunCommand;
use App\crontab\Listener\CrontabExecutionLogListener;
use App\crontab\Listener\CrontabRegisterListener;
use App\crontab\Listener\CrontabReloadListener;
use App\crontab\Listener\OnPipeMessageListener;
use App\crontab\Mutex\RedisServerMutex;
use App\crontab\Mutex\RedisTaskMutex;
use App\crontab\Mutex\ServerMutex;
use App\crontab\Mutex\TaskMutex;
use App\crontab\Strategy\StrategyInterface;
use App\crontab\Strategy\WorkerStrategy;
use App\crontab\Process\CrontabDispatcherProcess;

class ConfigProvider {

    public function __invoke() {
        return [
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'commands' => [
                RunCommand::class,
            ],
            'dependencies' => [
                StrategyInterface::class => WorkerStrategy::class,
                ServerMutex::class => RedisServerMutex::class,
                TaskMutex::class => RedisTaskMutex::class,
            ],
            'listeners' => [
                CrontabRegisterListener::class,
                OnPipeMessageListener::class,
                CrontabReloadListener::class,
                CrontabExecutionLogListener::class,
            ],
            'processes' => [
                CrontabDispatcherProcess::class,
            ],
        ];
    }
}
