<?php

namespace App\crontab\Controller\Api;

// 中间件
use xphp\HttpServer\Annotation\Middleware;
use App\crontab\Middleware\UserCorsMiddleware;

/**
 * 用户控制器
 */
#[Middleware(UserCorsMiddleware::class)]

trait User {
    /**
     * 获取用户信息
     */
    public function getUserInfo() {
        $user = $this->request->getAttribute('user', []);
        showmsg('success', 200, [
            'uid' => $user['uid'],
            'nickname' => $user['nickname'] ?: $user['username'],
            'mobile' => $user['mobile'],
            'avatar' => avatar($user['uid']),
        ]);
    }
}
