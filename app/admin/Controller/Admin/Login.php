<?php

declare(strict_types=1);

namespace App\admin\Controller\Admin;

use App\admin\Event\Auth;
use App\user\Device;
use xphp\Contract\SessionInterface;

/**
 * 登录
 * 纯 Token 模式: 不再依赖 Cookie 会话, 返回 encode([uid, sessionId, uin]) 复合令牌
 */
class Login {
    /**
     * 账号密码登录
     * @log
     */

    public function index() {

        $token = input('.token', '', 'trim');
        $data = input('.data', '', 'trim');

        if ($token && $data) {
            $entoken = base64_encode($token . 'x-php');
            $entoken = str_replace('=', ':XPHP:', $entoken);
            list($account, $password) = array_map('base64_decode', explode($entoken, base64_decode(substr($data, strlen($entoken)))));
        } else {
            $account = input('.account', '', 'trim');
            $password = input('.password', '', 'base64_decode');
            $accountstrlen = mb_strlen(base64_encode($account));
            $password = base64_decode(substr($password, 0, 0 - $accountstrlen));
        }

        // 登录校验与在线状态由 user 应用完成(失败时内部直接输出错误)
        $userinfo = app('user')->login(['account' => $account, 'password' => $password]);
        if (!$userinfo || empty($userinfo['uid'])) {
            show_json([
                'code' => 201,
                'message' => '登录出错',
            ]);
        }

        // 后台准入校验(AuthListener 未通过时会直接拒绝), 并写入 adminUserData 到当前会话
        xphp('event')->dispatch(new Auth($userinfo));
        $fingerprint = $this->passportFingerprint();
        show_json([
            'code' => 200,
            'message' => '登录成功',
            'data' => [
                'uid' => $userinfo['uid'],
                'uniqid' => $userinfo['uniqid'],
                'groupid' => $userinfo['groupid'],
                'username' => $userinfo['username'],
                'nickname' => $userinfo['nickname'],
                'mobile' => $userinfo['mobile'],
                'token' => encode([(int) $userinfo['uid'], $fingerprint, (int) ($userinfo['uin'] ?? 0)]),
            ],
        ]);
    }

    /**
     * 登录设备指纹, 默认取 User-Agent; 中间件比对 token 指纹, 换设备访问即失效
     */
    protected function passportFingerprint(): string {
        return Device::fingerprint(xphp('request'));
    }

    /**
     * 扫码监听
     * @log
     */
    public function scanListen() {

        $sessionid = input('.token', '', 'decode');
        if (!$sessionid) {
            return showmsg('Token 参数错误');
        }

        $model = m('passport_token')->where(['sessionid' => $sessionid])->field('uid,status,lastdate,submitip');
        $info = $model->one();
        if (!$info) {
            return showmsg('Token 参数错误');
        }

        $lastdate = strtotime($info['lastdate']);

        // 超时 3 分钟
        if (time() - $lastdate > 180) {
            return showmsg('登录超时');
        }

        // 等待扫码, 返回 202
        if ($info['status'] == 0) {
            return showmsg('等待扫码', 202);
        }

        // status == 1 已扫码
        if ($info['status'] == 1) {
            return showmsg('扫码成功', 203);
        }

        // status == 2 登录成功
        if ($info['status'] == 2 && $info['uid'] > 0) {

            $userinfo = app('user')->where(['uid' => $info['uid']])->one();
            if (!$userinfo) {
                return showmsg('用户不存在');
            }

            // 绑定到轮询请求所持的会话(纯 Token, 不落 Cookie)
            $sessionid = (string) $sessionid;
            xphp(SessionInterface::class)->setId($sessionid);
            // login_cookie 内部仅写 Session 登录态与在线表; setId 后即绑定到上述会话
            app('user')->login_cookie([
                'uid' => (int) $userinfo['uid'],
                'uin' => (int) ($userinfo['uin'] ?? 0),
                'groupid' => (int) ($userinfo['groupid'] ?? 0),
                'session' => $sessionid,
            ]);

            // 记录登录信息
            app('user.data')->set((int) $userinfo['uid'], [
                'lastlogintime' => time(),
                'lastloginip' => ip(),
            ]);

            $fingerprint = $this->passportFingerprint();

            // 后台准入校验并写入 adminUserData
            xphp('event')->dispatch(new Auth(array_merge($userinfo, ['session' => $sessionid])));

            return showmsg('登录成功!', 200, [
                'uid' => $userinfo['uid'],
                'uniqid' => $userinfo['uniqid'],
                'groupid' => $userinfo['groupid'],
                'username' => $userinfo['username'],
                'nickname' => $userinfo['nickname'],
                'mobile' => $userinfo['mobile'],
                'token' => encode([(int) $userinfo['uid'], $fingerprint, (int) ($userinfo['uin'] ?? 0)]),
            ]);
        }

        showmsg('登录状态异常', 201);
    }

    /**
     * 扫码, 获取二维码
     * @log
     */

    public function getScanQrcode() {

        $show_scan_login = xphp('config')->sysget('config.show_scan_login', 0);
        if (!$show_scan_login) {
            return showmsg('扫码登录未开启');
        }

        $sessionId = app('user')->sessionId();
        // 入库
        m('passport_token')->upsert([
            'sessionid' => $sessionId,
            'uid' => 0,
            'status' => 0,
            'ip' => ip(),
            'lastdate' => date('Y-m-d H:i:s'),
        ], ['sessionid'], [
            'uid' => 0,
            'status' => 0,
            'ip' => ip(),
            'lastdate' => date('Y-m-d H:i:s'),
        ]);

        return showmsg('获取登录二维码成功', 200, [
            'qrcode' => 'https://passport.gter.net/?session=' . $sessionId,
            'token' => encode($sessionId),
        ]);
    }
}
