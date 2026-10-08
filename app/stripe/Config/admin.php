<?php
defined('IN.XPHP') == false && exit('This system Access Denied');
return [
    'setting' => [
        [
            'name' => 'stripe_mode',
            'label' => '运行环境',
            'component' => 'select',
            'parent' => 'stripe',
            'bind' => [
                'options' => [
                    ['label' => '生产环境 (live)', 'value' => 'live'],
                    ['label' => '测试环境 (test)', 'value' => 'test'],
                ],
                'placeholder' => '请选择运行环境',
            ],
            'value' => 'live',
            'message' => '选择当前使用的 Stripe 密钥环境',
        ],
        [
            'name' => 'stripe_live_secret_key',
            'label' => '生产 Secret Key',
            'component' => 'input',
            'parent' => 'stripe',
            'bind' => [
                'placeholder' => 'sk_live_ 开头',
            ],
            'message' => 'Stripe 后台 开发者 -> API 密钥 中的生产环境密钥',
            'showhandle' => '$.stripe_mode == "live"',
        ],
        [
            'name' => 'stripe_test_secret_key',
            'label' => '测试 Secret Key',
            'component' => 'input',
            'parent' => 'stripe',
            'bind' => [
                'placeholder' => 'sk_test_ 开头',
            ],
            'message' => 'Stripe 后台 开发者 -> API 密钥 中的测试环境密钥',
            'showhandle' => '$.stripe_mode == "test"',
        ],
        [
            'name' => 'stripe_webhook_secret',
            'label' => 'Webhook 签名密钥',
            'component' => 'input',
            'parent' => 'stripe',
            'bind' => [
                'placeholder' => 'whsec_ 开头',
            ],
            'message' => 'Stripe 后台 Webhook 端点的签名密钥, 用于验证 /stripe/webhook 回调',
        ],
        [
            'name' => 'stripe_currency',
            'label' => '默认货币',
            'component' => 'input',
            'parent' => 'stripe',
            'bind' => [
                'placeholder' => '默认 usd',
            ],
            'value' => 'usd',
            'message' => '创建支付时未指定货币则使用该默认值, 如 usd/eur/hkd',
        ],
    ],
    'router' => [],
];
