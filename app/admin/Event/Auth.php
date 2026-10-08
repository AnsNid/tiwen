<?php

declare(strict_types=1);

namespace App\admin\Event;

/**
 * 账号事件
 */
class Auth {

    /**
     * @var array 用户信息
     */
    public array $userInfo;

    /**
     * 构造函数
     */
    public function __construct($userInfo) {
        $this->userInfo = $userInfo;
    }
}
