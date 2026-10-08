<?php

return [
    'setting' => [
        [
            'label' => '金额允许为负',
            'name' => 'consumeallow_negative',
            'parent' => 'consume',
            'component' => 'switch',
            'value' => 0,
            'bind' => [
                'activeValue' => 1,
                'inactiveValue' => 0,
                'inactiveText' => '不允许',
                'activeText' => '允许',
                'inline-prompt' => true,
            ],
            'message' => '金额允许为负, 如: 提现, 退款等',
        ],
        [
            'name' => 'consume_currency',
            'label' => '货币类型',
            'parent' => 'consume',
            'tips' => '货币类型, 如: 金币, 钻石, 积分',
            // 'message' => '货币类型, 如: 金币, 钻石, 积分, 此处设置后, 前台会显示此类型; 但不影响用户的操作;可以选择或自己添加',
            'component' => 'formtable',
            'value' => [],
            'bind' => [
                'column' => [
                    ['label' => '标识', 'name' => 'value', 'component' => 'autocomplete', 'bind' => [
                        'placeholder' => '必填, 如: coin, diamond, score',
                        'api' => '/consume/autocomplete/currency',
                        'clearable' => true,
                        'select-when-unmatched' => true,
                    ]],
                    ['label' => '名称', 'name' => 'label',  'component' => 'input', 'bind' => [
                        'placeholder' => '必填, 如: 金币, 钻石, 积分',
                    ]],
                    [
                        'label' => '类型',
                        'name' => 'type',
                        'component' => 'select',
                        'width' => '180',
                        'options' => [
                            ['label' => 'success', 'value' => 'success'],
                            ['label' => 'info', 'value' => 'info'],
                            ['label' => 'warning', 'value' => 'warning'],
                            ['label' => 'danger', 'value' => 'danger'],
                            ['label' => 'primary', 'value' => 'primary'],
                        ],
                    ]
                ],
                'reference' => [
                    'before' => '货币类型, 如: ',
                    'data' => [
                        [
                            'value' => [
                                'value' => 'coin',
                                'label' => '金币',
                                'type' => 'success',
                            ],
                            'label' => '金币',
                        ],
                        [

                            'value' => [
                                'value' => 'diamond',
                                'label' => '钻石',
                                'type' => 'info',
                            ],
                            'label' => '钻石',
                        ],
                        [
                            'value' => [
                                'value' => 'score',
                                'label' => '积分',
                                'type' => 'danger',
                            ],
                            'label' => '积分',
                        ],
                        [

                            'value' => [
                                'value' => 'money',
                                'label' => '金额',
                                'type' => 'warning',
                            ],
                            'label' => '金额',
                        ]
                    ],
                    'after' => '等,此处设置后, 前台会显示此类型;',
                ]
            ],
        ],
        [
            'name' => 'consume_types',
            'label' => '操作类型',
            'parent' => 'consume',
            'component' => 'formtable',
            'value' => [],
            'bind' => [
                'column' => [
                    ['label' => '标识', 'name' => 'value', 'component' => 'autocomplete', 'bind' => [
                        'placeholder' => '必填, 如: recharge, withdraw, refund',
                        'api' => '/consume/autocomplete/types',
                        'clearable' => true,
                    ]],
                    ['label' => '名称', 'name' => 'label', 'component' => 'input', 'bind' => [
                        'placeholder' => '必填, 如: 充值, 提现, 退款等',
                    ]],
                    [
                        'label' => '类型',
                        'name' => 'type',
                        'component' => 'select',
                        'width' => '180',
                        'options' => [
                            ['label' => 'success', 'value' => 'success'],
                            ['label' => 'info', 'value' => 'info'],
                            ['label' => 'warning', 'value' => 'warning'],
                            ['label' => 'danger', 'value' => 'danger'],
                            ['label' => 'primary', 'value' => 'primary'],
                        ],
                    ]
                ],
                'reference' => [
                    'before' => '操作类型, 如: ',
                    'data' => [
                        [
                            'value' => [
                                'value' => 'withdraw',
                                'label' => '提现',
                                'type' => 'warning',
                            ],
                            'label' => '提现',
                        ],
                        [
                            'value' => [
                                'value' => 'recharge',
                                'label' => '充值',
                                'type' => 'success',
                            ],
                            'label' => '充值',
                        ],
                        [
                            'value' => [
                                'value' => 'refund',
                                'label' => '退款',
                                'type' => 'danger',
                            ],
                            'label' => '退款',
                        ],
                        [
                            'value' => [
                                'value' => 'system',
                                'label' => '系统操作',
                                'type' => 'primary',
                            ],
                            'label' => '系统操作',
                        ],
                        [
                            'value' => [
                                'value' => 'expire',
                                'label' => '到期作废',
                                'type' => 'info',
                            ],
                            'label' => '到期作废',
                        ]
                    ],
                    'after' => '等,此处设置后, 前台会显示此类型;',
                ]
            ],
        ]
    ],
    'router' => [
        [
            'parentid' => 'user',
            'component' => 'consume/index',
            'name' => 'consume',
            'path' => 'consume',
            'meta' => [
                'title' => '积分管理',
                'icon' => 'x-icon-consume',
                'tablename' => 'consume.table@init',
            ],
        ],
    ],
];
