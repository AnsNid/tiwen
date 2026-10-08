<?php

namespace App\user;

use Exception;
use xphp\DbConnection\Model\Model;
use xphp\Contract\SessionInterface;
use xphp\Context\ApplicationContext;
use xphp\Context\Context;

/**
 * 会员模型
 */
class Api extends Model {
    protected ?string $table = 'members';

    protected string $primaryKey = 'uid';

    protected array $user = [
        'uid' => 0,
        'uin' => 0,
        'groupid' => 0,
    ];

    protected $sessionName = ':usersession:';

    /**
     * 密码加密
     * 算法不可更改;否则无法登录
     * @param  string  $string
     */
    public function password(?string $password = null, ?string $key = 'xphp.com') {
        return md5(md5($key) . $key . md5(trim($password)));
    }

    /**
     * 获取 sessionId
     */
    public function sessionId() {
        // Session 中间件未加载时无可用的会话, 返回空串
        // (否则 SessionProxy::getSession() 返回 null 会直接 TypeError)
        if (!$this->sessionAvailable()) {
            return '';
        }
        return trim(xphp(SessionInterface::class)->getId());
    }

    /**
     * 当前请求是否有可用会话(session 中间件启动会话后才会写入协程 Context)
     */
    protected function sessionAvailable(): bool {
        return Context::has(SessionInterface::class);
    }

    /**
     * 获取用户会员组
     */
    public function getGroup() {
        return $this->hasOne(\App\user\Model\Group::class, 'id', 'groupid');
    }

    /**
     * 从online获取用户信息
     */
    public function getOnlineUser($sessionId = '') {
        if (!app('?online')) {
            return [];
        }
        $sessionId = $sessionId ?: $this->sessionId();
        return app('online')->get($sessionId, function ($data, $model) use ($sessionId) {
            $updateUser = [
                'uid' => (int) ($data['uid'] ?? 0),
                'uin' => (int) ($data['uin'] ?? 0),
                'groupid' => (int) ($data['groupid'] ?? 0),
                'sessionId' => $sessionId,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            // 更新该用户在线情况
            if ($data && ($updateUser['uid'] > 0 || $updateUser['uin'] > 0)) {
                // 今日登录奖励, 最近更新时间为昨天
                $lastupdate = strtotime($data['updated_at'] ?: '2020-01-01 00:00:00');
                if (app('?consume') && strtotime(date('Y-m-d 00:00:00')) >= $lastupdate) {
                    app('consume')->rule('login', $updateUser['uid'], ['uin' => $updateUser['uin']], date('Y-m-d'));
                }

                // 距离上次更新大于 3 分钟了, 更新数据库
                if (strtotime('-3 minutes') >= $lastupdate) {
                    $model->update([
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                if ($this->sessionAvailable()) {
                    xphp(SessionInterface::class)->set($this->sessionName, $updateUser);
                }
            }
            return $updateUser;
        });
    }

    /**
     *  初始化
     *  @param  $callback
     *  @param  bool  $force 强制刷新
     *  @return array
     */
    public function init($callback = null, $force = false) {

        // Session 中间件未加载: 无会话可用, 按未登录返回默认值
        // (继续往下走 SessionProxy::getSession() 返回 null 会直接 TypeError)
        if (!$this->sessionAvailable()) {
            $user = [];
            if ($callback) {
                if (is_object($callback)) {
                    return call_user_func_array($callback, [$user]);
                } elseif (is_string($callback)) {
                    return null;
                } elseif (is_array($callback)) {
                    return array_fill_keys($callback, '');
                }
            }
            return $this->user;
        }

        $user = xphp(SessionInterface::class)->get($this->sessionName, []);
        $sessionId = $this->sessionId();

        if (empty($user) || ((int) $user['uid'] === 0 && (int) $user['uin'] === 0) || $force) {
            if (app('?lock')) {
                // 尝试获取锁
                $lock = app('lock')->make('online:' . $sessionId);
                try {
                    $user = $lock->get(function () use ($sessionId) {
                        return $this->getOnlineUser($sessionId);
                    });
                } catch (\App\lock\Exception\LockTimeoutException $e) {
                    // 锁超时，返回空数组
                    return [];
                }
            } else {
                // 没有锁，直接查询
                $user = $this->getOnlineUser($sessionId);
            }
        }

        if ($callback) {
            if (is_object($callback)) {
                return call_user_func_array($callback, [$user]);
            } elseif (is_string($callback)) {
                return $user[$callback] ?? null;
            } elseif (is_array($callback)) {
                $data = [];
                foreach ($callback as $value) {
                    $data[$value] = $user[$value] ?? '';
                }
                return $data;
            }
        }

        if (empty($user) || ((int) $user['uid'] === 0 && (int) $user['uin'] === 0)) {
            return $this->user;
        }

        // 默认处理
        $model = $user['uid'] > 0 ? $this->query()->where(['uid' => $user['uid']]) : $this->query()->where(['uin' => $user['uin']]);

        $data = $model->orderBy('uid', 'desc')
            ->select([
                'uid',
                'uin',
                'groupid',
                'username',
                'nickname',
                'email',
                'mobile',
                'status'
            ])
            ->one();

        if (empty($data) || (int) $data['uid'] === 0) {
            return $this->loginquit($sessionId);
        }
        return array_merge($this->user, $user, $data);
    }



    /**
     * 多会员 数据查询
     * $uids Array
     * $fields 需要查询的字段
     */

    public function get_uids($uids = [], $fields = '') {
        $uids = array_values(array_filter(array_unique(array_map('intval', (array) $uids))));
        if (empty($uids)) {
            return [];
        }
        if ($fields) {
            // fields 转换为数组
            $fields = is_array($fields) ? $fields : explode(',', $fields);
            $fields = array_values(array_unique(array_filter($fields)));
            // $fields 必须要有 uid
            if (in_array('uid', $fields) == false) {
                $fields[] = 'uid';
            }
        }
        return $this->whereIn('uid', $uids)->select($fields ?: '*')->get()->keyBy('uid')->toArray();
    }

    /**
     *  用户注册
     * */
    public function register($info = [], $callback = null) {

        // 验证用户名手机号邮箱是否重复放在外部, 进入此函数默认已唯一

        if (empty($info) || is_array($info) == false) {
            return false;
        }

        $uniqid = isset($info['uniqid']) && $info['uniqid'] ? $info['uniqid'] : id();
        // 重复 ID
        $uniqiduser = $this->query()->where('uniqid', $uniqid)->value('uid');
        if ($uniqiduser) {
            return false;
        }

        $data = array_merge($info, [
            'groupid' => isset($info['groupid']) && $info['groupid'] ? $info['groupid'] : 0,
            'regdate' => isset($info['regdate']) && $info['regdate'] && is_numeric($info['regdate']) ? $info['regdate'] : time(),
            'regip' => isset($info['regip']) && $info['regip'] ? $info['regip'] : ip(),
            'uniqid' => $uniqid,
            'password' => $info['password'] ? $this->password($info['password'], $uniqid) : null,
            'status' => isset($info['status']) ? $info['status'] : 1,
            'regtype' => isset($info['regtype']) ? $info['regtype'] : 'email',
        ]);

        // 注册成功前事件
        xphp('event')->dispatch('user.register@before', $data);

        $checkAllowFields = [
            'uin',
            'groupid',
            'username',
            'nickname',
            'password',
            'email',
            'mobile',
            'regdate',
            'regip',
            'uniqid',
            'status',
            'regtype',
        ];

        if (!($uid = $this->insertGetId(xphp('array')->only($data, $checkAllowFields), 'uid'))) {
            return false;
        }

        // 保存数据,过滤掉会员表字段
        $userData = xphp('array')->diff($data, $checkAllowFields);
        $userData && app('user.data')->set($uid, $userData);

        // 记录今日注册数
        app('?stat') && app('stat')->register($data['regtype']);

        // 记录用户行为
        app('user.trace')->register($uid, $data['regtype'], $data['uin']);
        // 注册成功后钩子
        xphp('event')->dispatch('user.register@after', array_merge($data, ['uid' => $uid]));

        // 注册成功后, 积分奖励
        if (app('?consume')) {
            app('consume')->rule('register', $uid, ['uin' => intval($data['uin'] ?? 0)], $uid);
        }

        // 回调
        if ($callback && is_object($callback)) {
            return call_user_func_array($callback, [array_merge($data, ['uid' => $uid])]);
        }
        return $uid;
    }

    /**
     * 登录
     * $data 登录数据包
     * $type (手机号, 邮箱 , UID, 用户名) + 密码 登录 password ; 手机号+验证码登录 mobile; 微信登录 weixin, 第三方登录(qq, weibo, baidu, alipay...) shortcut
     * $callback 回调
     */
    public function login($data = null, $type = 'password', $callback = null) {

        // 密码登录为系统自带, 其它项需安装其应用
        if (in_array($type, ['password', 'mobile', 'weixin', 'verify']) == false) {
            return false;
        }

        $user = [];
        try {
            switch ($type) {
                case 'verify':
                    $user = app('verify.user')->login($data);
                    break;
                case 'shortcut':
                    $user = app('shortcut.user')->login($data);
                    break;
                case 'weixin':
                    $user = m('weixin.user')->login($data);
                    break;
                default:
                    $user = $this->login_authentication($data);
                    break;
            }
        } catch (Exception $e) {
            isJsonException($e);
            // 记录日志
            // xphp(\Psr\Log\LoggerInterface::class)->error($e->getMessage(), $data);
            show_json([
                'code' => 201,
                'message' => $e->getMessage(),
            ]);
        }

        if (empty($user)) {
            return false;
        }

        // 登录前的一些判断
        xphp('event')->dispatch('user.login@before', $user);

        // 会员等级更新
        $groupdata = app('user.group')->update($user);

        if ($groupdata && is_array($groupdata)) {
            $user = array_merge($user, $groupdata);
        }

        // 保存登录信息到 cookie session
        $this->login_cookie($user, function ($data) use (&$user) {
            $user = array_merge($user, $data);
        });

        $ips = ips();
        // 记录登录信息
        app('user.data')->set(['uid' => $user['uid'], 'uin' => $user['uin']], [
            'lastlogintime' => time(),
            'lastloginip' => array_pop($ips),
            'browserfingerprint' => getBrowserFingerprint(), // 浏览器指纹
        ]);

        // 如还有 ips 则记录
        if ($ips) {
            foreach ($ips as $value) {
                app('user.data')->set(['uid' => $user['uid'], 'uin' => $user['uin']], ['lastloginip' => $value]);
            }
        }

        // 登录成功后的钩子操作, 更新用户身份与登录信息
        xphp('event')->dispatch('user.login@after', $user);

        // 统计
        app('?stat') && app('stat')->login(join('_', array_filter([$user['type'] ?? '', $type])));

        // 回调
        if ($callback && is_object($callback)) {
            return call_user_func_array($callback, [$user]);
        }

        return $user;
    }

    /**
     * 用户登录验证
     * $data 需要登录的用户信息
     * $callback 回调
     */
    public function login_authentication($data = [], $callback = null) {

        $validator = ApplicationContext::getContainer()->get('validation')->make($data, ['account' => 'required', 'password' => 'required'], [
            'account.required' => '用户名或密码不能为空',
            'password.required' => '用户名或密码不能为空',
        ]);

        if ($validator->fails()) {
            exception($validator->errors()->first());
        }

        if (app('?loginerror')) {
            $loginerrorModel = app('loginerror');
            $loginerror = $loginerrorModel->verification([
                'account' => $data['account'],
                'password' => $data['password'],
                'ip' => ip()
            ]);
        }

        // 尝试不同登录方式
        $userList = $this->query()
            ->select([
                'uid',
                'uin',
                'uniqid',
                'password',
                'groupid',
                'username',
                'nickname',
                'email',
                'mobile',
                'status'
            ])
            ->where(function ($query) use ($data) {

                $query->where('username', '=', $data['account']);

                // 是否为邮箱
                if ($data['account'] && filter_var($data['account'], FILTER_VALIDATE_EMAIL)) {
                    $query->orWhere('email', '=', $data['account']);
                }

                // 验证是否为手机号
                if ($data['account'] && preg_match('/^1[3456789]\d{9}$/', $data['account'])) {
                    $query->orWhere('mobile', '=', $data['account']);
                }
            })
            ->orderBy('uid', 'desc')
            ->limit(10)
            ->get()
            ->toArray();

        $user = [];
        if ($userList) {
            foreach ($userList as $value) {
                if ($value['password'] === $this->password($data['password'], $value['uniqid'])) {
                    $user = $value;
                    break;
                }
            }
        }

        // 更新
        if (isset($loginerror) && is_object($loginerror) && $loginerror instanceof $loginerrorModel) {
            $user ? $loginerror->success() : $loginerror->error();
        }


        if (empty($user) || !isset($user['uid'])) {
            exception('用户名或密码输入不正确');
        }

        // 用户状态
        if (in_array((int) $user['status'], [1, 2]) == false) {
            exception('当前用户状态被禁止登录');
        }

        // 验证成功 回调
        if ($callback && is_object($callback)) {
            return call_user_func_array($callback, [$user]);
        }
        return $user;
    }

    /**
     * 登录COOKIE
     * $info Array 需要登录的用户信息
     * $cktime cookie 有效期
     * $callback 登录成功后的回调
     */
    public function login_cookie($value = null, $callback = null) {

        $info = is_array($value) ? $value : ['uid' => (int) $value];

        $uid = intval($info['uid'] ?? 0);
        $uin = intval($info['uin'] ?? 0);

        if (empty($uid) && empty($uin)) {
            return false;
        }

        $updateUser = [
            'uid' => $uid,
            'uin' => $uin,
            'groupid' => $info['groupid'] ?? 0,
            'session' => $this->sessionId(),
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        if ($this->sessionAvailable()) {
            xphp(SessionInterface::class)->set($this->sessionName, $updateUser);
        }
        // 创建一个在线
        app('?online') && app('online')->create($updateUser);
        // 事件
        xphp('event')->dispatch('user.login@create', $updateUser);
        // 回调
        if ($callback && is_object($callback)) {
            return call_user_func_array($callback, [$updateUser]);
        }
        return $updateUser['session'];
    }

    /**
     * 退出登录
     * $session 退出登录的 session
     */
    public function loginquit(string $session = '') {

        $session = $session ?: $this->sessionId();
        // 清除在线记录
        if ($this->sessionAvailable()) {
            xphp(SessionInterface::class)->remove($this->sessionName);
        }

        app('?online') && app('online')->delete($session);
        $user = ['uid' => 0, 'session' => null, 'groupid' => 0, 'username' => null, 'uin' => 0];
        // 事件
        xphp('event')->dispatch('user.login@quit', $session);
        return $user;
    }

    /**
     * 删除会员
     * $uid
     */
    public function delete(int $uid = 0) {

        // 跳过超级管理员
        $founders = array_values(array_unique(array_filter(array_map('intval', (array) xphp('config')->get('founder')))));
        if (in_array($uid, $founders)) {
            return false;
        }

        $info = $this->query()->where('uid', $uid)->first();
        if (empty($info) || (int) $info->uid === 0) {
            return false;
        }

        $this->query()->where('uid', $uid)->delete();

        // 删除绑定相关权限
        m('user.authority')->where('uid', $uid)->delete();
        // 删除会员数据
        m('user.data')->query()->where('uid', $uid)->delete();
        // 钩子处理删除
        xphp('event')->dispatch('user@delete', $info);
        return true;
    }

    /**
     * 头像输出
     * $uid = int 会员 UID
     * $size 头像尺寸类型
     * $realpath === true 时 直接输出头像服务器绝对地址,否则为带上随机参数
     */
    public function avatar(...$vars) {
        if (app('?avatar') == false) {
            return 'data:image/png;base64,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';
        }
        return app('avatar.UrlGenerator')->toUrl(...$vars);
    }
}
