<?php

declare(strict_types=1);

namespace App\user\Middleware;

use App\user\Device;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use xphp\Context\Context;
use xphp\Contract\SessionInterface;

/**
 * 用户权限验证中间件
 * 负责验证用户登录状态和权限
 */
class UserAuthMiddleware implements MiddlewareInterface {
    /**
     * 游客模式: true 时未登录不拦截, 以 uid=0 继续执行(供游客可访问的接口使用)
     */
    protected bool $guest = false;

    /**
     * 验证用户是否有权限访问指定资源 permissions []
     */
    protected array $permissions = [];

    /**
     * 事件触发
     */
    protected string $event = '';

    /**
     * 设置 permissions 内部
     */
    protected function getPermissions() {
        return $this->permissions;
    }

    /**
     * 常规 token 解析失败时的事件兜底, 返回 [uid, fingerprint] 或 null
     * 子类可覆写以接入自定义鉴权方式
     */
    protected function getEvent(string $token): mixed {
        return $this->event ? event($this->event, $token) : null;
    }

    /**
     * 处理请求
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {

        $uid = 0;
        $user = null;

        // 检查授权 X-Token: `Bearer ${token}`
        $token = $request->getHeaderLine('x-token');
        if ($token) {

            $online = decode(trim(str_replace('Bearer ', '', $token)));
            $online = $online ?: $this->getEvent($token);
            if (empty($online)) {
                // 授权码无效: 非游客模式直接拒绝; 游客模式视为未登录, 以 uid=0 继续
                if (!$this->guest) {
                    return $this->unauthorizedResponse('授权码已失效, 请重新获取', 401);
                }
            } else {
                $uid = (int) ($online[0] ?? 0);
                $fingerprint = (string) ($online[1] ?? '');
                $isFingerprint = (bool) preg_match('/^[0-9a-f]{16}$/', $fingerprint);
                if ($isFingerprint && $fingerprint !== Device::fingerprint($request)) {
                    return $this->unauthorizedResponse('登录设备已变更, 请重新登录', 401);
                }
            }
        } elseif (Context::has(SessionInterface::class)) {
            // 仅在 session 中间件已为本请求启动会话时才走会话鉴权,
            // 未加载时 SessionProxy::getSession() 返回 null 会直接 TypeError
            $user = $this->getUserFromSession();
            $uid = (int) ($user['uid'] ?? 0);
        }

        // 登录态判定: 非游客模式未登录直接拒绝;
        // 游客模式(OptionalAuthMiddleware)未登录不拦截, 以 uid=0 / 空 user 继续执行
        if (!$uid && !$this->guest) {
            return $this->unauthorizedResponse('请先登录后操作!', 401);
        }

        if ($uid > 0) {
            // 检查用户是否存在
            $user = app('user')
                ->where('uid', $uid)
                ->select(['uid', 'uin', 'groupid', 'username', 'nickname', 'email', 'mobile', 'status'])
                ->one();

            if (!$user && !$this->guest) {
                return $this->unauthorizedResponse('请先登录后操作!', 401);
            }

            // 检查用户状态
            if ($user && !$this->isUserActive($user)) {
                return $this->unauthorizedResponse('账户已被禁用', 402);
            }

            // 禁止访问用户组
            $forbidGroup = xphp('config')->get('user@config.forbidGroup');
            if ($user && $user['groupid'] && $forbidGroup && count($forbidGroup) > 0 && in_array($user['groupid'], $forbidGroup)) {
                return $this->unauthorizedResponse('你所在用户组被禁止访问', 403);
            }
        }

        // 检查用户权限: 声明了权限的路由必须有登录用户, 游客提示登录
        $permissions = $this->getPermissions();
        if ($permissions && count($permissions) > 0) {
            if (!$user) {
                return $this->unauthorizedResponse('请先登录后操作!', 401);
            }
            if (!app('user.Permissions')->checkUserPermission($user['uid'], $permissions, (int) $user['uin'])) {
                return $this->unauthorizedResponse('没有权限访问,请联系管理员', 403);
            }
        }

        // 将用户信息添加到请求属性中
        $request = $request->withAttribute('user', $user ?? []);
        // 写入 online
        $request = $request->withAttribute('uid', $uid);
        // 处理正常请求并添加跨域头
        $response = $handler->handle($request);
        return $response;
    }

    /**
     * 从会话中获取用户信息
     */
    private function getUserFromSession(): ?array {
        $user = app('user')->init(function ($user) {
            return $user;
        });
        if (!$user || (empty($user['uid']) && empty($user['uin']))) {
            return null;
        }
        return $user;
    }


    /**
     * 检查用户是否处于活跃状态
     */
    private function isUserActive(array $user): bool {
        return isset($user['status']) && $user['status'] == 1;
    }

    /**
     * 返回未授权响应
     */
    private function unauthorizedResponse(string $message, int $code = 401): ResponseInterface {
        return response()->json(['code' => $code, 'message' => $message, 'data' => []])->withStatus($code);
    }
}
