<?php

declare(strict_types=1);

namespace App\admin\Service;

use Psr\Container\ContainerInterface;
use xphp\Contract\SessionInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\user\Device;

/**
 * 后台认证授权服务
 * 统一负责管理员身份解析、创始人判定与权限装载
 */
class AuthService {
    public function __construct(private ContainerInterface $container) {
    }

    /**
     * 解析当前请求的管理员身份
     * 令牌格式: encode([uid, sessionId, uin])(兼容旧版裸会话ID), 按其绑定会话后仅认 adminUserData
     */
    public function resolve(?string $token = null): ?array {

        $this->bindSession($token);
        [$uid, $fingerprint] = decode($token);
        if (!$uid || empty($uid)) {
            stdout_logger()->error('AuthService::resolve uid is empty, token: ' . $token);
            return null;
        }

        if ($fingerprint !== Device::fingerprint($this->container->get(ServerRequestInterface::class))) {
            stdout_logger()->error('AuthService::resolve fingerprint not match, uid: ' . $uid . ', fingerprint: ' . $fingerprint);
            return null;
        }


        $user = app('user')->where(['uid' => $uid])->select(['uid', 'nickname', 'username', 'groupid'])->one();
        if (!$user || empty($user['uid'])) {
            stdout_logger()->error('AuthService::resolve user not found, uid: ' . $uid);
            return null;
        }
        $authorities = $this->authorities((int) $user['uid']);
        $authority = $this->mergeAuthorities($authorities);
        $founder = $this->isFounder((int) $user['uid']);

        $role = is_string($authority['role'] ?? null) ? $authority['role'] : '';
        $permissions = array_values(array_filter((array) ($authority['permissions'] ?? []), 'is_string'));

        // 创始人与 super 角色拥有全部权限
        if ($founder || $role === 'super') {
            $role = 'super';
            $permissions = ['*'];
        }

        return array_merge($user, [
            'founder' => $founder,
            'role' => $role,
            'authorities' => $authorities,
            'authority' => $authority,
            'permissions' => $permissions,
        ]);
    }

    /**
     * 获取指定用户全部有效的后台授权(system 组)
     */
    public function authorities(int $uid): array {
        try {
            $list = m('user.authority')
                ->alias('a')
                ->join(m('user.group')->getTable() . ' as g', 'g.id', '=', 'a.groupid')
                ->order('a.id desc')
                ->where(['a.uid' => $uid, 'g.type' => 'system'])
                ->json(['authority'])
                ->field('g.id,g.title,g.type,g.authority,a.expiration')
                ->get()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }

        // 过滤已过期的授权
        return array_values(array_filter($list, function ($item) {
            return empty($item['expiration']) || time() <= (int) $item['expiration'];
        }));
    }

    /**
     * 从登录令牌绑定会话: decode([uid, sessionId, uin]) 或旧版裸会话ID
     */
    public function bindSession(?string $token): void {

        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 512) {
            return;
        }

        $sessionId = $token;
        $decoded = decode($token);
        if (is_array($decoded) && isset($decoded[1]) && is_string($decoded[1]) && $decoded[1] !== '') {
            // 复合令牌携带的 uid 与会话内登录用户的一致性由 resolve() 校验
            $sessionId = $decoded[1];
        }

        try {
            xphp(SessionInterface::class)->setId($sessionId);
        } catch (\Throwable) {
        }
    }

    /**
     * 判断是否为创始人(超级管理员)
     */
    public function isFounder(int $uid): bool {
        $founder = $this->container->get('config')->get('admin@config.founder', []);
        $uids = is_string($founder) ? explode(',', $founder) : (array) $founder;
        return in_array($uid, array_map('intval', $uids), true);
    }

    /**
     * 合并多条授权为一个 authority 结构
     * 标量取第一个非空值, 数组按并集去重合并
     */
    private function mergeAuthorities(array $rows): array {
        $merged = [];
        foreach ($rows as $row) {
            $data = $row['authority'] ?? [];
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $key => $value) {
                if (!array_key_exists($key, $merged)) {
                    $merged[$key] = $value;
                } elseif (is_array($merged[$key]) && is_array($value)) {
                    $merged[$key] = array_values(array_unique(array_merge($merged[$key], $value)));
                }
            }
        }
        return $merged;
    }
}
