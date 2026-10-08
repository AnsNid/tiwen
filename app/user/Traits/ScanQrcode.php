<?php

namespace App\user\Traits;

use App\user\Device;

/**
 * 扫码登录 /扫码监听 trait
 * 各应用复用时只需覆写 passport 开头的配置方法即可区分权限, 内部方法均带 passport 前缀避免与控制器其它 trait 冲突:
 */
trait ScanQrcode {
    /**
     * 扫码, 获取二维码
     */
    public function getScanQrcode() {

        $sessionId = app('user')->sessionId();
        // 入库
        m('passport_token')->upsert([
            'sessionid' => $sessionId,
            'uid' => 0,
            'status' => 0,
            'ip' => ip(),
            'lastdate' => date('Y-m-d H:i:s')
        ], ['sessionid'], [
            'uid' => 0,
            'status' => 0,
            'ip' => ip(),
            'lastdate' => date('Y-m-d H:i:s')
        ]);

        return showmsg('获取登录二维码成功', 200, [
            'qrcode' => app('qrcode')->QRCodeResult('https://passport.gter.net/?session=' . $sessionId, ['imageBase64' => true]),
            'token' => encode($sessionId),
        ]);
    }

    /**
     * 扫码监听
     */
    public function scanListen() {
        $sessionid = input('token', '', 'decode');
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

            $user = app('user')->where(['uid' => $info['uid']])->one();
            if (!$user) {
                return showmsg('用户不存在');
            }

            // 查询是否有 passportPermissions方法
            $permissions = [];
            $passportPermissions = [];
            if (method_exists($this, 'passportPermissions')) {
                $passportPermissions = $this->passportPermissions();
            }

            // 判断权限
            if ($passportPermissions) {
                $permissions = app('user.Permissions')->checkUserPermission($user['uid'], $passportPermissions, (int)$user['uin']);
                if (!$permissions) {
                    return showmsg('用户没有权限访问', 201, [
                        'uid' => $user['uid'],
                        'permissions' => $permissions,
                        'passportPermissions' => $passportPermissions
                    ]);
                }
            }

            // 更新用户登录信息
            app('user.data')->set((int) $user['uid'], [ 'lastlogintime' => time(), 'lastloginip' => ip() ]);

            // passportTokenResponse 是否存在
            if (method_exists($this, 'passportTokenResponse')) {
                return $this->passportTokenResponse($user, $permissions);
            }

            $token = encode([$user['uid'], Device::fingerprint($this->request ?? null), (int) $user['uin']]);
            show_json([
                'code' => 200,
                'data' => [
                    'token' => $token,
                    'user' => [
                        'uid' => $user['uid'],
                        'username' => $user['username'],
                        'nickname' => $user['nickname'] ?: $user['username'],
                        'mobile' => $user['mobile'] ?? '',
                        'email' => $user['email'] ?? '',
                        'groupid' => $user['groupid'] ?? -1,
                        'avatar' => avatar($user['uid']),
                        'permissions' => $permissions,
                    ]
                ]
            ]);
        }
        showmsg('登录状态异常', 201);
    }
}
