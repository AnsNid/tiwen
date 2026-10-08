<?php

return [
    'router' => [
        [
            'path' => 'mail',
            'component' => 'mail/index',
            'name' => 'mail',
            'parentid' => 'system',
            'meta' => [
                'title' => '邮件服务',
                'type' => 'menu',
                'icon' => 'x-icon-mail',
            ],
        ],
    ],
];
