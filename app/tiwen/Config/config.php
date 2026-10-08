<?php

declare(strict_types=1);

/**
 * 提问模块配置
 * 套餐配额与订阅定价,config('tiwen@config', []) 读取
 */
return [
    // 站点对外地址(Stripe 支付回调跳转基准地址,留空则按请求 Host 推断)
    'site_url' => env('TIWEN_SITE_URL', ''),

    // 默认勾选的对比模型(前三个模型)
    'default_models' => ['gpt-5.6-sol', 'claude-opus-5', 'gemini-3.1-pro-preview'],

    // 综合分析使用的模型
    'synthesis_model' => 'deepseek-v4-flash',

    // 多轮上下文:携带最近轮数与单卡片截断长度
    'context_rounds' => 2,
    'context_card_max_chars' => 4000,

    // 联网搜索(Tavily):前台勾选「智能搜索」时,先检索实时网页并把结果注入各模型 prompt 上下文
    // 后台可在管控中心逐项覆盖(存 config 表 tiwen_web_search);api_key 留空则回落环境变量
    'web_search' => [
        'enabled' => true,                          // 总开关(关闭后前台勾选也不触发检索)
        'api_key' => env('TAVILY_API_KEY', ''),     // Tavily API Key(tvly- 开头)
        'max_results' => 5,                         // 返回网页条数上限(1-10)
        'search_depth' => 'basic',                  // basic 快速 / advanced 深度
        'include_answer' => true,                   // 是否附带 Tavily 生成的直接答案摘要
        'timeout' => 8,                             // 单次检索超时(秒)
        'max_content_chars' => 1200,                // 单条网页正文截断长度
    ],

    // 验证码:有效期(秒)与发送间隔(秒)
    'code_ttl' => 600,
    'code_interval' => 60,

    // 套餐定义(价格单位:美元;Stripe 金额单位:分)
    // type: subscription 订阅(月/年付) | one_time 一次性包(单笔购买,积分制)
    // name_i18n/features_i18n:多语言名称与权益(zh/zh-TW/en),缺失语言回落 name/features
    'plans' => [
        'free' => [
            'type' => 'subscription',
            'name' => 'Free 基础版',
            'price_monthly' => 0,
            'price_yearly' => 0,
            'daily_asks' => 20,
            'tokens_quota' => 0,
        ],
        'pro' => [
            'type' => 'subscription',
            'name' => 'Pro 专业版',
            'price_monthly' => 19.9,
            'price_yearly' => 199,
            'daily_asks' => 0,
            'tokens_quota' => 1000000,
        ],
        'team' => [
            'type' => 'subscription',
            'name' => 'Team 团队版',
            'price_monthly' => 49,
            'price_yearly' => 490,
            'daily_asks' => 0,
            'tokens_quota' => 5000000,
        ],
        'flagship' => [
            'type' => 'one_time',
            'name' => '旗舰包',
            'price' => 799, // 一次性售价
            'credits' => 999, // 到账基础积分(美元计)
            'bonus_percent' => 25, // 加赠比例(%)
            'validity_months' => 24, // 积分有效期(月)
            'gift_pro_months' => 12, // 赠送 Pro 会员(月)
            'gift_monthly_credits' => 1000, // 会员期内每月加赠积分
            'daily_asks' => 0,
            'tokens_quota' => 1000000, // 会员期内享 Pro 级月度算力
            'features' => [
                '到账 $999 积分 (+25% 赠送)',
                '送 12 个月 Pro 会员: 每月再领 1,000 积分池',
                '无限存档 · 导出 · 标注 · 评论 (会员期内)',
                '全部旗舰模型按量可用 (≈ 国产旗舰 15 亿+ tokens)',
                '优先队列 + 专属客服',
                '— 积分不退，可与订阅叠加使用',
            ],
            'name_i18n' => [
                'zh' => '旗舰包',
                'zh-TW' => '旗艦包',
                'en' => 'Flagship',
            ],
            'features_i18n' => [
                'zh' => [
                    '到账 $999 积分 (+25% 赠送)',
                    '送 12 个月 Pro 会员: 每月再领 1,000 积分池',
                    '无限存档 · 导出 · 标注 · 评论 (会员期内)',
                    '全部旗舰模型按量可用 (≈ 国产旗舰 15 亿+ tokens)',
                    '优先队列 + 专属客服',
                    '— 积分不退，可与订阅叠加使用',
                ],
                'zh-TW' => [
                    '到帳 $999 點數 (+25% 贈送)',
                    '送 12 個月 Pro 會員: 每月再領 1,000 點數池',
                    '無限存檔 · 導出 · 標註 · 評論 (會員期內)',
                    '全部旗艦模型按量可用 (≈ 國產旗艦 15 億+ tokens)',
                    '優先隊列 + 專屬客服',
                    '— 點數不退，可與訂閱疊加使用',
                ],
                'en' => [
                    '$999 credits on arrival (+25% bonus)',
                    '12 months of Pro included: claim 1,000 credits pool monthly',
                    'Unlimited archive · export · annotate · comments (during membership)',
                    'All flagship models available by usage (≈ 1.5B+ tokens)',
                    'Priority queue + dedicated support',
                    '— Non-refundable, stacks with subscriptions',
                ],
            ],
        ],
    ],
];
