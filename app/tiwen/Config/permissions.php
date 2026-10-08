<?php

declare(strict_types=1);

/**
 * 提问模块权限定义
 * config('tiwen@permissions', []) 读取,由 user.Permissions 汇入系统权限集
 */
return [
    [
        'value' => 'tiwen:admin',
        'label' => '提问管理',
        'icon' => 'x-icon-chat-dot-round',
    ],
];
