<?php

// +----------------------------------------------------------------------
// | 后台设置
// +----------------------------------------------------------------------

return [
    'founder' => '1,10000', // 超级管理员账号UID, 多个请用,号隔开
    // 是否在接口分发层强制校验 RBAC 权限键(app.controller.action / app.*)
    // 关闭时登录即可访问全部后台接口; 开启后未配置细分权限键的管理组仍可全量访问(向后兼容), 配置了权限键的按键匹配
    'enforce_permissions' => false,
    // 语言包加载
    'langextendList' => [
        'admin' => [
            'admin@zh-cn',

        ],
    ],
    'adminframework' => [
        'API_URL' => url('adminApi'),
        'MY_SHOW_LOGIN_OAUTH' => true, // 显示其它登录
        'SESSIONNAME' => 'ADMINTOKEN', //
    ],
];
