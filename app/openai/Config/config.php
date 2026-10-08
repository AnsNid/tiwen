<?php

declare(strict_types=1);

use function xphp\Support\env;

return [
    // 默认供应商，当未明确指定 provider 且未匹配到模型映射时使用
    'default_provider' => env('OPENAI_DEFAULT_PROVIDER', 'openai'),
    // 模型到供应商的映射，支持前缀匹配（使用 * 结尾表示前缀）
    // 注意：本文件的 providers 与 model_provider_map 现在是兜底/种子配置。
    // 生效顺序为 openai_providers / openai_models 表(后台「AI 服务管理」维护) → config 表
    // 的 openai_providers 覆盖 → 本文件与 env。表里某 provider 没有启用账号时才回退到这里。
    'model_provider_map' => [
        'gpt-*' => 'openai',
    ],
    // 多供应商配置：可同时配置 openai / azure / 其他兼容服务
    'providers' => [
        'openai' => [
            'base_uri' => env('OPENAI_BASE_URI', 'api.openai.com/v1'),
            'api_key' => env('OPENAI_API_KEY', ''),
            'organization' => env('OPENAI_ORGANIZATION'),
            'request_timeout' => (int) env('OPENAI_REQUEST_TIMEOUT', 300),
            // 可选：额外头与查询参数
            'headers' => [],
            'query_params' => [],
        ],
        'deepseek' => [
            'base_uri' => env('DEEPSEEK_BASE_URI', 'https://api.deepseek.com'),
            'api_key' => env('DEEPSEEK_API_KEY', ''),
            'request_timeout' => (int) env('DEEPSEEK_REQUEST_TIMEOUT', 300),
        ]
    ],
];
