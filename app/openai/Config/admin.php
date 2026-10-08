<?php

return [
    'router' => [
        [
            'path' => 'openai',
            'component' => 'openai/index',
            'name' => 'openai',
            'parentid' => 'system',
            'meta' => [
                'title' => 'AI 服务管理',
                'type' => 'menu',
                'icon' => 'x-icon-openai',
            ],
        ],
    ],
];
