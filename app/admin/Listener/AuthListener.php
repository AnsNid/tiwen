<?php

declare(strict_types=1);

namespace App\admin\Listener;

use App\admin\Event\Auth;
use xphp\Event\Contract\ListenerInterface;
use xphp\Contract\SessionInterface;
use xphp\Logger\LoggerFactory;
use Psr\Container\ContainerInterface;

/**
 * 管理员认证事件监听器
 * 用于处理管理员登录认证事件
 */
class AuthListener implements ListenerInterface {

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var SessionInterface
     */
    protected $session;

    /**
     * @var LoggerFactory
     */
    protected $logger;

    /**
     * 构造函数
     */
    public function __construct(ContainerInterface $container, LoggerFactory $logger) {
        $this->container = $container;
        $this->logger = $logger;
    }

    /**
     * 返回监听的事件列表
     */
    public function listen(): array {
        return [
            Auth::class,
        ];
    }

    /**
     * 处理认证事件
     */
    public function process(object $event): void {
        if ($event instanceof Auth) {

            // 验证权限
            $this->verifyPermission($event);

            // 记录会话
            $this->recordSession($event);
        }
    }

    /**
     * 记录会话
     */
    private function recordSession(Auth $event) {
        xphp(SessionInterface::class)->set('adminUserData', [
            'uid' => $event->userInfo['uid'],
            'username' => $event->userInfo['username'],
            'groupid' => $event->userInfo['groupid'],
        ]);
    }


    /**
     * 事件处理, 身份验证
     */
    public function verifyPermission(Auth $event) {

        // 超级管理员跳过
        $founder = $this->container->get('config')->get('admin@config.founder', []);
        $founder = is_string($founder) ? explode(',', $founder) : $founder;
        if ($founder && in_array($event->userInfo['uid'], $founder)) {
            $this->logger->get('admin', 'admin')->info('founderSuccessful', [
                'userinfo' => [
                    'uid' => $event->userInfo['uid'],
                    'username' => $event->userInfo['username'],
                    'groupid' => $event->userInfo['groupid'],
                ],
            ]);
            return true;
        }

        $authorityList = m('user.authority')
            ->alias('a')
            ->join(m('user.group')->getTable() . ' as g', 'g.id', '=', 'a.groupid')
            ->order('a.id desc')
            ->where(['a.uid' => $event->userInfo['uid'], 'g.type' => 'system'])
            ->json(['authority'])
            ->field('g.id,g.title,g.type,g.authority,a.expiration')
            ->get();

        if (empty($authorityList)) {

            $this->logger->get('admin', 'admin')->warning('notAuthorityList', [
                'userinfo' => [
                    'uid' => $event->userInfo['uid'],
                    'username' => $event->userInfo['username'],
                    'groupid' => $event->userInfo['groupid'],
                ],
            ]);

            show_json([
                'code' => 201,
                'message' => '你的账号没有权限进入后台,请联系管理员; 此操作已记录.',
                'data' => [
                    'founder' => $founder,
                    'authorityList' => $authorityList,
                    'userInfo' => $event->userInfo,
                ]
            ]);
        }

        $allowedEnter = false;
        if ($authorityList) {
            foreach ($authorityList as $value) {
                // 过期
                if ($value->expiration && time() > $value->expiration) {
                    continue;
                }

                // 后台权限
                if (empty($value->authority) || isset($value->authority['immission']) == false || empty($value->authority['immission'])) {
                    continue;
                }
                $allowedEnter = true;
                break;
            }
        }

        // 无权限进入
        if (empty($allowedEnter)) {

            $this->logger->get('admin', 'admin')->warning('notAllowedEnter', [
                'userinfo' => [
                    'uid' => $event->userInfo['uid'],
                    'username' => $event->userInfo['username'],
                    'groupid' => $event->userInfo['groupid'],
                ],
            ]);

            show_json([
                'code' => 201,
                'message' => '你的账号没有权限进入后台,请联系管理员; 此操作已记录.',
                'data' => [
                    'founder' => $founder,
                    'authorityList' => $authorityList,
                    'userInfo' => $event->userInfo,
                ],
            ]);
        }

        $this->logger->get('admin', 'admin')->info('Successful', [
            'userinfo' => [
                'uid' => $event->userInfo['uid'],
                'username' => $event->userInfo['username'],
                'groupid' => $event->userInfo['groupid'],
            ],
        ]);
    }
}
