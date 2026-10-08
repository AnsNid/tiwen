<?php

namespace App\tiwen\Controller\Admin;

use App\tiwen\Controller\Api\Responses;
use App\tiwen\Middleware\AdminAuthMiddleware;
use xphp\HttpServer\Annotation\Middleware;

/**
 * 后台登录接口（无需鉴权）
 * 邮箱密码登录并校验 tiwen:admin 权限,签发后台 token
 */
trait LoginTrait {
    use Responses;

    /**
     * 后台登录
     * @doc(method: 'post', description: '邮箱密码登录,要求 tiwen:admin 权限,返回后台 token 与管理员信息', tag: "后台-登录")
     * @param string $email 邮箱
     * @param string $password 密码
     * @return array {code: 200, data: {token: '...', user: {uid: 1, nickname: '...'}}}
     */
    public function login() {
        $email = trim((string) input('email', ''));
        $password = (string) input('password', '');
        if ($email === '' || $password === '') {
            return $this->fail('邮箱和密码不能为空');
        }

        try {
            $user = app('user')->login(['account' => strtolower($email), 'password' => $password]);
        } catch (\Throwable $e) {
            isJsonException($e);
            logger()->error('tiwen 后台登录异常: ' . $e->getMessage());
            return $this->fail('登录失败,请稍后重试');
        }
        if (!$user) {
            return $this->fail('邮箱或密码不正确');
        }

        $uid = (int) $user['uid'];
        $permissions = app('user.Permissions')->checkUserPermission($uid, ['tiwen:admin'], (int) $user['uin']);
        if (!$permissions) {
            return $this->fail('没有提问管理权限,请联系管理员', 403);
        }

        return $this->ok([
            'token' => encode([$user['uid'], $user['session'], $user['uin']]),
            'user' => [
                'uid' => $uid,
                'username' => (string) ($user['username'] ?? ''),
                'nickname' => (string) ($user['nickname'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'avatar' => avatar($uid),
            ],
        ], '登录成功');
    }

    /**
     * 后台当前管理员信息
     * @doc(method: 'get', description: '获取当前登录管理员信息(鉴权心跳)', tag: "后台-登录")
     * @return array {code: 200, data: {uid: 1, nickname: '...'}}
     */
    #[Middleware(AdminAuthMiddleware::class)]
    public function info() {
        $uid = $this->uid();
        $user = m('user.Api')->where('uid', $uid)->one();
        if (!$user) {
            return $this->fail('用户不存在');
        }
        return $this->ok([
            'uid' => $uid,
            'username' => (string) ($user['username'] ?? ''),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'email' => (string) ($user['email'] ?? ''),
            'avatar' => avatar($uid),
        ]);
    }
}
