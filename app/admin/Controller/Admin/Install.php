<?php

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use xphp\Framework\AppInstall;
use xphp\Framework\Server;

/**
 * 系统安装配置
 */
class Install extends Authorization {
    /**
     * 安装应用
     */
    public function init() {

        $apps = require BASE_PATH . '/config/apps.php';
        $apps = array_keys($apps);
        if (in_array('admin', $apps) && in_array('user', $apps)) {
            return [
                'code' => 201,
                'message' => '应用已安装',
            ];
        }

        $install = new AppInstall();
        $install->install('admin');
        $install->install('user');
        Server::restart();

        show_json([
            'code' => 200,
            'message' => '安装成功',
        ]);
    }





    /**
     * 提交配置
     */
    public function submit() {

        // 获取提交的数据
        $username = $this->request->input('username');
        $password = $this->request->input('password');
        $contact = $this->request->input('contact');
        $siteName = $this->request->input('site_name');
        $adminhostpath = $this->request->input('adminhostpath');


        if (file_exists(runtime_path('config') . 'config.php')) {
            return [
                'code' => 201,
                'message' => '系统已安装, 重复安装可能会导致数据覆盖',
            ];
        }


        // 验证数据
        if (empty($username) || empty($password)) {
            return [
                'code' => 201,
                'message' => '用户名和密码不能为空',
            ];
        }

        // 判断联系方式只能为邮箱或手机
        if (!filter_var($contact, FILTER_VALIDATE_EMAIL) && !preg_match('/^1[3-9]\d{9}$/', $contact)) {
            return [
                'code' => 201,
                'message' => '请填写正确的邮箱或手机号',
            ];
        }


        if (empty($siteName) || empty($adminhostpath)) {
            return [
                'code' => 201,
                'message' => '站点名称和后台访问路径不能为空',
            ];
        }

        // adminhostpath 路径只能为数字或英文
        if (!preg_match('/^[a-zA-Z0-9]+$/', $adminhostpath)) {
            return [
                'code' => 201,
                'message' => '后台访问路径只能为数字或英文',
            ];
        }

        // 写入配置
        db('config')->upsert([
            ['name' => 'site_name', 'value' => $siteName],
            ['name' => 'adminhostpath', 'value' => $adminhostpath],
            ['name' => 'contact', 'value' => $contact],
        ], 'name', [
            'value'
        ]);

        // username 是否存在
        $uid = app('user')->where('username', $username)->order('uid desc')->value('uid');
        if (!$uid) {
            // 写入添加管理员
            $uid = app('user')->register([
                'username' => $username,
                'password' => $password,
                'nickname' => $username,
                'email' => filter_var($contact, FILTER_VALIDATE_EMAIL) ? $contact : '',
                'mobile' => filter_var($contact, FILTER_VALIDATE_EMAIL) ? '' : $contact,
            ]);
            ;
        } else {
            $uniqid = id();
            app('user')->where('uid', $uid)->update([
                'uniqid' => $uniqid,
                'password' => app('user')->password($password, $uniqid),
            ]);
        }



        // 写入管理员角色
        m('user.authority')->insert([
            'uid' => $uid,
            'groupid' => 1,
        ]);

        // 更新配置
        cache_write('config', [
            'site_name' => $siteName,
            'adminhostpath' => $adminhostpath,
            'contact' => $contact,
        ]);

        return [
            'code' => 200,
            'message' => '配置提交成功',
        ];
    }
}
