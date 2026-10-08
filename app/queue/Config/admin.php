<?php

return [
    'router' => [
        [
            'path' => 'queue',
            'parentid' => 'system',
            'component' => 'queue/index',
            'meta' => [
                'title' => '队列管理',
                'icon' => 'x-icon-queue',
                'tablename' => 'queue.admin@table',
            ],
            'name' => 'queue',
        ],
    ],
];
