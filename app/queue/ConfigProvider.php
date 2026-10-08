<?php

declare(strict_types=1);


namespace App\queue;

use App\queue\Aspect\AsyncQueueAspect;
use App\queue\Command\DynamicReloadMessageCommand;
use App\queue\Command\FlushFailedMessageCommand;
use App\queue\Command\InfoCommand;
use App\queue\Command\ReloadFailedMessageCommand;
use App\queue\Process\ConsumerProcess;
use App\queue\Listener\QueueHandleListener;
use App\queue\Listener\QueueLengthListener;
use App\queue\Listener\QueueTraceListener;
use App\queue\Listener\ReloadChannelListener;



class ConfigProvider {
    public function __invoke(): array {
        return [
            'annotations' => [
                'scan' => [
                    'paths' => [
                        __DIR__,
                    ],
                ],
            ],
            'aspects' => [
                AsyncQueueAspect::class,
            ],
            'commands' => [
                FlushFailedMessageCommand::class,
                InfoCommand::class,
                ReloadFailedMessageCommand::class,
                DynamicReloadMessageCommand::class,
            ],
            'processes' => [
                ConsumerProcess::class,
            ],
            'listeners' => [
                ReloadChannelListener::class,
                QueueLengthListener::class,
                QueueHandleListener::class,
                QueueTraceListener::class,
            ],
        ];
    }
}
