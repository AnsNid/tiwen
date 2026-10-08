<?php
defined('IN.XPHP') || exit('This system Access Denied');
/* XPHP Version 2.75.616, Create on 2026-09-08 22:44:29, instructions: There is config */
return [
  'name' => 'Stripe支付',
  'author' => 'X-PHP',
  'site' => 'http://www.x-php.com/',
  'version' => '1.06.616',
  'tables' => 'stripe_payments',
  'isphar' => true,
  'description' => 'Stripe支付组件: 创建支付会话 app(\'stripe\')->createCheckout($params), 查询 app(\'stripe\')->retrieveCheckout($sessionId), 退款 app(\'stripe\')->refund($intentId), 原生SDK app(\'stripe\')->client(), Webhook回调 /stripe/webhook, 支付成功事件 App\\stripe\\Event\\PaymentSucceeded',
  'icon' => '',
  'composer' => [
    'stripe/stripe-php' => '^21.0',
  ],
  'require' => [
    'php' => '>=8.0.0',
    'xphp' => '>=0.1.0',
    '@crontab' => '>=0.0.1',
  ],
  'created_at' => '2026-08-14 21:16:53',
  'updated_at' => '2026-09-08 22:44:29',
];