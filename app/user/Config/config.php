<?php

/**
 * 会员模块配置
 */
return [
    // COOKIE
    'cookie_key' => 'member',
    // 超级管理员, 受保护无法删除
    'founder' => [
        1,
        10000,
    ],
    'trace_direct_write' => true, // 是否直接写入数据库
    'trace_batch' => [
        'interval_ms' => 60000,
        'batch_size' => 200,
    ],
    // 禁止访问用户组
    'forbidGroup' => [7, 22],
    // 允许重复收集用户字段名称-- 允许重复次数 0-1 为不限
    'allowRepeatedField' => [
        'email' => 0,
        'mobile' => 0,
        'password' => 0,
        'tags' => 20,
        'lastlogintime' => 10,
        'lastloginip' => 10,
        'browserfingerprint' => 10,
        'nickname' => 10,
    ],
    // 跳过不记录的字段
    'filterField' => [
        'uid',
        'memberid',
        'timestamp',
    ],
    // 字段分组
    'sectionField' => [
        // 'agent' => '网点',
    ],
    // 扩展字段..
    'extendField' => [
        'authority' => [
            'name' => 'authority',
            'label' => '附属权限',
            // 'list' => true,
            // 'section' => 'agent' // 字段分组, 找不到或留空则不分组
            // 允许编辑
            'edit' => true, // list:列表, search:检索, sort:排序, export:导出, edit:编辑
            "component" => "checkboxGroup",
            'options' => [],
            'bind' => [
                'size' => 'small',
            ],
            'message' => '附属权限, 如: 查看统计, 查看会员列表等,可以在用户权限上叠加单独设置',
            'value' => [],
        ],
        'realname' => [
            'name' => 'realname',
            'label' => '实名认证',
            'edit' => true, // list:列表, search:检索, sort:排序, export:导出, edit:编辑,
            'list' => true,
            "component" => "switch",
            'options' => [],
            'bind' => [
                'active-value' => 1,
                'active-text' => '已认证',
                'inactive-value' => 0,
                'inactive-text' => '未认证',
                'inline-prompt' => true,
            ],
            'message' => '更改实名认证状态',
            'value' => 0,
        ],
        [
            'name' => 'realnickname',
            'label' => '真实昵称',
            'edit' => true, // list:列表, search:检索, sort:排序, export:导出, edit:编辑,
            'list' => true,
            'options' => [],
            'component' => 'input',
            'message' => '更改真实昵称',
            'value' => '',
        ]
    ],
    'trace_redis' => [
        'key' => 'user:trace',
        'shards' => 4,
    ],
];
