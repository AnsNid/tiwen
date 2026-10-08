<?php

namespace App\user\Traits;

use App\user\Device;

/**
 * 全局登录 / 注册 trait
 * 各应用复用时只需覆写 passport 开头的配置方法即可区分权限, 内部方法均带 passport 前缀避免与控制器其它 trait 冲突:
 *   use App\user\Traits\Passport;
 *
 *   protected function passportPermissions(): array {
 *       return ['wuyou:admin', 'wuyou:business'];
 *   }
 */
trait Passport {
    /**
     * 登录本应用所需权限, 命中其中任一即可; 为空表示登录即可, 不校验权限
     */
    protected function passportPermissions(): array {
        return [];
    }

    /**
     * 是否开放注册路由: 默认关闭(注册端点存在但返回 403), 需要注册的应用覆写为 false
     */
    protected function passportRegisterEnabled(): bool {
        return false;
    }

    /**
     * 注册成功后自动授予的权限(写入 user.Data authority), 为空表示注册不授予权限
     */
    protected function passportRegisterPermissions(): array {
        return [];
    }

    /**
     * 识别账号类型: email / mobile / username (与登录侧 login_authentication 的识别规则一致)
     */
    protected function passportAccountType(string $account): string {

        if (filter_var($account, FILTER_VALIDATE_EMAIL)) {
            return 'email';
        }
        if (preg_match('/^1[3456789]\d{9}$/', $account)) {
            return 'mobile';
        }
        return 'username';
    }

    /**
     * 邮箱/手机号注册时的脱敏用户名(同时用作昵称), 完整账号仅存对应登录字段
     */
    protected function passportMaskUsername(string $account, string $type): string {

        if ($type == 'email') {
            [$local, $domain] = array_pad(explode('@', $account, 2), 2, '');
            return substr($local, 0, 2) . '***@' . $domain;
        }
        if ($type == 'mobile') {
            return substr($account, 0, 3) . '****' . substr($account, -4);
        }
        return $account;
    }

    /**
     * token 有效期(秒), 0 为永不过期; 默认 30 天
     */
    protected function passportTokenExpiry(): int {
        return 0;
    }


    /**
     * 登录设备指纹, 默认取 User-Agent; 中间件比对 token 指纹, 换设备访问即失效
     */
    protected function passportFingerprint(): string {
        return Device::fingerprint($this->request ?? null);
    }

    /**
     * 登录,返回用户信息
     * @param string $account 账号(兼容 username 参数名)
     * @param string $password 密码
     */
    public function login() {

        $account = trim((string) (input('account', '') ?: input('username', '')));
        $password = (string) input('password', '');
        if ($account === '' || $password === '') {
            showmsg('账号或密码不能为空');
        }

        $user = app('user')->login(['account' => $account, 'password' => $password]);
        if (!$user) {
            showmsg('登录失败, 请检查账号或密码');
        }

        // 命中任一所需权限即可登录
        $permissions = $this->passportCheckPermissions($user);
        if ($this->passportPermissions() && !$permissions) {
            $this->passportForbidden($user);
        }


        // 更新用户登录信息
        app('user.data')->set((int) $user['uid'], [ 'lastlogintime' => time(), 'lastloginip' => ip() ]);

        $this->passportTokenResponse($user, $permissions);
    }

    /**
     * 注册并自动登录, 注册即授予 passportRegisterPermissions 配置的权限
     * @param string $account 账号(兼容 username 参数名)
     * @param string $password 密码
     */
    public function register() {

        if (!$this->passportRegisterEnabled()) {
            showmsg('当前应用未开放注册', 403);
        }

        $account = trim((string) (input('account', '') ?: input('username', '')));
        $password = (string) input('password', '');
        if ($account === '' || $password === '') {
            showmsg('账号或密码不能为空');
        }
        if (mb_strlen($account) < 3 || mb_strlen($account) > 32) {
            showmsg('账号长度需为 3-32 个字符');
        }
        if (strlen($password) < 6) {
            showmsg('密码长度不能少于 6 位');
        }

        // 识别账号类型, 按类型查对应字段是否已注册
        $type = $this->passportAccountType($account);
        $exists = app('user')->query()->where($type, $account)->value('uid');
        if ($exists) {
            showmsg('该账号已被注册, 请更换或直接登录');
        }

        // 邮箱/手机号注册: username/nickname 存脱敏值, 完整账号只写对应登录字段
        $username = $this->passportMaskUsername($account, $type);
        $uid = app('user')->register(array_merge([
            'username' => $username,
            'password' => $password,
            'nickname' => $username,
            'regtype' => $type,
            'status' => 1,
        ], $type == 'username' ? [] : [$type => $account]));
        if (!$uid) {
            showmsg('注册失败, 请稍后重试');
        }

        // 注册即授予应用权限, 否则新账号无法通过带权限校验的中间件
        $registerPermissions = $this->passportRegisterPermissions();
        if ($registerPermissions) {
            app('user.Data')->set(['uid' => $uid, 'uin' => 0], [
                'authority' => $registerPermissions,
            ]);
        }

        // 注册完成直接登录, 前端无需再调 login
        $user = app('user')->login(['account' => $account, 'password' => $password]);
        if (!$user) {
            showmsg('注册成功, 请重新登录');
        }
        // 更新用户登录信息
        app('user.data')->set((int) $user['uid'], [ 'lastlogintime' => time(), 'lastloginip' => ip() ]);

        $this->passportTokenResponse($user, $this->passportCheckPermissions($user) ?: $registerPermissions);
    }

    /**
     * 校验用户权限, 返回命中的权限列表(未配置权限或未命中返回空数组)
     */
    protected function passportCheckPermissions(array $user): array {

        $permissions = $this->passportPermissions();
        if (empty($permissions)) {
            return [];
        }

        return (array) app('user.Permissions')->checkUserPermission((int) $user['uid'], $permissions, (int) ($user['uin'] ?? 0));
    }

    /**
     * 无权限统一响应
     */
    protected function passportForbidden(array $user = []) {
        showmsg('没有权限访问,请联系管理员', 403, [
            'uid' => $user['uid'] ?? 0,
        ]);
    }

    /**
     * 登录凭证统一返回结构
     */
    protected function passportTokenResponse(array $user, array $permissions = []) {
        show_json([
            'code' => 200,
            'data' => [
                'token' => encode([$user['uid'], $this->passportFingerprint(), (int) $user['uin']], '', $this->passportTokenExpiry()),
                'user' => [
                    'uid' => $user['uid'],
                    'username' => $user['username'],
                    'nickname' => $user['nickname'],
                    'mobile' => $user['mobile'],
                    'email' => $user['email'],
                    'groupid' => $user['groupid'],
                    'avatar' => avatar($user['uid']),
                    'permissions' => $permissions,
                ],
            ],
        ]);
    }
}
