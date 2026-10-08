<?php

/**
 * 会员轨迹周期配置
 * 缓存的键，true 表示使用 date('Ymd'),每天为一个周期记录, null 为永久周期. 一生只记录一次 但会记录访问次数
 */
return [
    'login' => true,
];
