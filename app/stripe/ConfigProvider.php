<?php

declare(strict_types=1);

namespace App\stripe;

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
        ];
    }
}
