<?php

return [
    // 积分规则
    // 每条规则可选 'expire': 数字按天计(30 即发放后 30 天到期), 字符串走 strtotime('72 hours' / '2026-12-31'),
    // 不配为永久有效; 调用方可用 rule($type, $uid, ['expire' => 7]) 覆盖。到期由 ConsumeExpire 定时任务补等额负向流水冲销。
    'rule' => [
        // 发帖
        'post' => [
            'amount' => 10,
            'currency' => 'credit',
            'remark' => '发帖奖励积分'
        ],
        // 关注
        'follow' => [
            'amount' => 1,
            'currency' => 'credit',
            'restrict' => [
                'date' => 'daily',
                'amount' => 10
            ],
            'remark' => '关注奖励积分'
        ],
        // 评论
        'comment' => [
            'amount' => 1,
            'currency' => 'credit',
            'restrict' => [
                'date' => 'daily',
                'amount' => 10
            ],
            'remark' => '评论奖励积分'
        ],
        // 浏览用户主页
        'viewspace' => [
            'amount' => 1,
            'currency' => 'credit',
            'restrict' => [
                'date' => 'daily',
                'amount' => 10
            ],
            'remark' => '浏览用户主页奖励积分'
        ],
        // 精华
        'elite' => [
            'amount' => 200,
            'currency' => 'credit',
            'remark' => '精华奖励积分'
        ],
        // 邀请
        'invitation' => [
            'amount' => 200,
            'currency' => 'credit',
            'remark' => '邀请奖励积分'
        ],
        // 登录 1币/次 1 天一次
        'login' => [
            'amount' => 1,
            'currency' => 'credit',
            'type' => 'login',
            'restrict' => [
                'date' => 'daily',
                'count' => 1
            ],
            'remark' => '登录奖励积分'
        ],
        // 注册 10币/次 1 天一次
        'register' => [
            'amount' => 10,
            'currency' => 'credit',
            'type' => 'register',
            'restrict' => [
                'count' => 1
            ],
            'remark' => '注册奖励积分'
        ],
    ]
];
