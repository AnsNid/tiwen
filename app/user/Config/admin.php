<?php

// +----------------------------------------------------------------------
// | 后台设置
// +----------------------------------------------------------------------
return [
    'setting' => [
        [
            'name' => 'open_user_uin',
            'label' => '会员 UIN',
            'component' => 'switch',
            'parent' => 'user',
            'bind' => [
                'active-value' => 1,
                'inactive-value' => 0,
                'active-text' => '启用会员 UIN卡号',
                'inactive-text' => '不需要',
                'inline-prompt' => true,
            ],
            'message' => '启用会员 UIN卡号, 如绑定论坛 UID 等。',
        ],
        [
            'name' => 'user_admin_show_mobile',
            'label' => '后台显示会员手机号',
            'component' => 'switch',
            'parent' => 'user',
            'bind' => [
                'active-value' => 1,
                'inactive-value' => 0,
                'active-text' => '显示',
                'inactive-text' => '不显示',
                'inline-prompt' => true,
            ],
            'span' => 7,
            'message' => '管理列表',
        ],
        [
            'name' => 'user_admin_show_email',
            'label' => '后台显示会员邮箱',
            'component' => 'switch',
            'parent' => 'user',
            'bind' => [
                'active-value' => 1,
                'inactive-value' => 0,
                'active-text' => '显示',
                'inactive-text' => '不显示',
                'inline-prompt' => true,
            ],
            'span' => 7,
            'message' => '管理列表',
        ],
        [
            'name' => 'user_regtypes',
            'label' => '会员注册类型',
            'parent' => 'user',
            'tips' => '用于会员注册渠道分类、数据看板分析及检索筛选。内置 mobile, email, weixin 等',
            'message' => '配置系统支持的会员注册类型，可自定义添加或修改显示名称',
            'component' => 'formtable',
            'value' => [
                ['value' => 'mobile', 'label' => '手机注册'],
                ['value' => 'email', 'label' => '邮箱注册'],
                ['value' => 'weixin', 'label' => '微信生态'],
                ['value' => 'wechat', 'label' => '微信注册'],
                ['value' => 'qq', 'label' => 'QQ登录'],
                ['value' => 'admin', 'label' => '后台录入'],
                ['value' => 'system', 'label' => '系统内置'],
                ['value' => 'import', 'label' => '批量导入'],
                ['value' => 'other', 'label' => '默认/其他'],
            ],
            'bind' => [
                'column' => [
                    [
                        'label' => '类型标识 (英文/编码)',
                        'name' => 'value',
                        'component' => 'input',
                        'bind' => [
                            'placeholder' => '必填, 如: mobile, email, weixin, app, douyin',
                        ],
                        'width' => 200,
                    ],
                    [
                        'label' => '类型名称 (显示标签)',
                        'name' => 'label',
                        'component' => 'input',
                        'bind' => [
                            'placeholder' => '必填, 如: 手机注册, 抖音渠道, 线下地推等',
                        ],
                    ],
                ],
            ],
        ],
        [
            'label' => '用户权限设置',
            'parent' => 'user',
            'name' => 'userPermissions',
            'component' => 'formtable',
            'value' => [],
            'bind' => [
                'column' => [
                    [
                        'label' => '标识',
                        'name' => 'value',
                        'component' => 'input',
                        'bind' => [
                            'placeholder' => '必填, 如: login, register, recharge, withdraw, refund',
                        ]
                    ],
                    [
                        'label' => '名称',
                        'name' => 'label',
                        'component' => 'input',
                        'bind' => [
                            'placeholder' => '必填, 如: 登录, 注册, 充值, 提现, 退款等',
                        ]
                    ],
                ],
            ],
        ],
        [
            'name' => 'user_trace_types',
            'label' => '会员行为类型',
            'parent' => 'user',
            'tips' => '此处设置后, 会显示此类型; 但不影响用户的操作;可以选择或自己添加',
            'message' => '此处设置后, 会显示此类型; 但不影响用户的操作;可以选择或自己添加',
            'component' => 'formtable',
            'value' => [],
            'bind' => [
                'column' => [
                    [
                        'label' => '标识',
                        'name' => 'value',
                        'component' => 'autocomplete',
                        'bind' => [
                            'placeholder' => '必填, 如: login, register, recharge, withdraw, refund',
                            'api' => '/user/trace/getTypes',
                            'clearable' => true,
                            'select-when-unmatched' => true,
                        ],
                        'width' => 200,
                    ],
                    [
                        'label' => '名称',
                        'name' => 'label',
                        'component' => 'autocomplete',
                        'bind' => [
                            'placeholder' => '必填, 如: 登录, 注册, 充值, 提现, 退款等',
                            'options' => []
                        ]
                    ],
                    [
                        'label' => '事件钩子',
                        'name' => 'event',
                        'component' => 'input',
                        'bind' => [
                            'placeholder' => '选填, 用于事件的调度数据获取',
                        ],
                        'width' => 200,
                    ]
                ],
            ],
        ],
    ],
    'router' => [
        [
            'path' => 'user/list',
            'component' => 'user/index',
            'name' => 'userlist',
            'parentid' => 'user',
            'sort' => 1000,
            'meta' => [
                'title' => '会员列表',
                'type' => 'menu',
                'tablename' => 'user.table@init',
                'icon' => 'x-icon-user',
            ],
        ],
        [
            'path' => 'user/info',
            'component' => 'user/index/info',
            'name' => 'userinfo',
            'parentid' => 'user',
            'meta' => [
                'title' => '账号信息',
                'type' => 'menu',
                'icon' => 'el-icon-user',
                'hidden' => true,
                'active' => 'user/list',
            ],
        ],
        [
            'path' => 'user/group',
            'component' => 'user/group/index',
            'name' => 'usergroup',
            'parentid' => 'user',
            'sort' => 999,
            'meta' => [
                'title' => '会员组',
                'type' => 'menu',
                'icon' => 'x-icon-group',
            ],
        ],
        [
            'path' => 'user/trace',
            'component' => 'user/trace',
            'name' => 'usertrace',
            'parentid' => 'user',
            'sort' => 998,
            'meta' => [
                'title' => '会员足迹',
                'type' => 'menu',
                'icon' => 'x-icon-trace',
                'tablename' => 'user.trace@table',
            ],
        ],
    ],
];
