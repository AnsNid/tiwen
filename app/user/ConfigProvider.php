<?php

declare(strict_types=1);

namespace App\user;

use App\user\Process\TraceDelayRecordProcess;

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
            'listeners' => [],
            'processes' => [
                TraceDelayRecordProcess::class,
            ],
        ];
    }
}
