<?php

namespace App\user;

use Psr\Container\ContainerInterface;

/**
 * 会员权限模型
 */
class Permissions {
    public $appPermissions = [];

    /**
     * 构造函数
     */
    public function __construct(protected ContainerInterface $container) {
        // 获取在线 APP 应用所需权限
        $appList = $this->container->get('app')->getInstalledApps();
        foreach ($appList as $app) {
            $config = $this->container->get('config')->get($app . '@permissions', []);
            if (empty($config)) {
                continue;
            }
            $this->appPermissions = array_merge($this->appPermissions, $config);
        }
    }

    /**
     * 获取权限集
     * @return array
     */
    public function getPermission() {
        $userPermissions = $this->container->get('config')->sysget('config.userPermissions', []);
        $userPermissions = array_merge($this->appPermissions, $userPermissions);
        // 同一value只保留一个
        $data = [];
        foreach ($userPermissions as $value) {
            $data[$value['value']] = $value;
        }
        return array_values($data);
    }

    /**
     * 验证指定会员的指定权限
     * @param int $uid 会员ID
     * @param array|string $permission 权限列表
     * @param int $uin 会员UIN
     * @return bool|array
     */
    public function checkUserPermission($uid, array|string $permission = '', int $uin = 0) {
        $permission = (array) $permission;
        if (empty($permission)) {
            return false;
        }

        $uid = intval($uid);
        $uin = intval($uin);

        $founder = $this->container->get('config')->get('user@config.founder', []);
        // 超级用户直接返回 true
        if ($founder && in_array($uid, array_map('intval', $founder))) {
            return $permission;
        }

        // 先查个人权限, 已命中所需权限则不再查用户组
        $userData = app('user.Data')->get($uid, ['authority'], $uin);
        $personal = array_values(array_filter((array) ($userData['authority'] ?? []), 'is_string'));
        $matched = $personal ? array_intersect($permission, $personal) : [];
        if ($matched) {
            return array_values($matched);
        }

        // 个人权限不够, 再从所在用户组权限拿
        $groupPermissions = $this->getGroupPermission($uid, $uin);
        if ($groupPermissions) {
            $matched = array_intersect($permission, $groupPermissions);
            if ($matched) {
                return array_values($matched);
            }
        }

        return false;
    }


    /**
     * 获取指定会员的权限列表(个人权限 + 所在用户组权限, 个人没有的由用户组补足)
     */
    public function getUserPermission($uid = 0, int $uin = 0) {
        $userData = app('user.Data')->get($uid, ['authority'], $uin);
        $permissions = array_values(array_filter((array) ($userData['authority'] ?? []), 'is_string'));

        // 个人权限不够时, 并入所在用户组的权限
        $groupPermissions = $this->getGroupPermission(intval($uid), intval($uin));
        if ($groupPermissions) {
            $permissions = array_values(array_unique(array_merge($permissions, $groupPermissions)));
        }

        return $permissions;
    }

    /**
     * 获取指定会员所在用户组的权限列表(全部用户组权限去重合并)
     * member 组 authority 为权限键列表, system 组为 {menu, grid, permissions} 结构
     */
    public function getGroupPermission($uid = 0, int $uin = 0) {

        $groupids = $this->getUserGroupids(intval($uid), intval($uin));
        if (empty($groupids)) {
            return [];
        }

        $groups = m('user.group')
            ->where(['id' => $groupids, 'status' => 1])
            ->json(['authority'])
            ->select('authority')
            ->get()
            ->toArray();

        $permissions = [];
        foreach ($groups as $group) {
            $authority = $group['authority'] ?? [];
            if (is_string($authority)) {
                $decoded = json_decode($authority, true);
                $authority = json_last_error() === JSON_ERROR_NONE ? (array) $decoded : [];
            }
            // system 组结构取 permissions 键, 其余按扁平键列表处理
            if (isset($authority['permissions']) && is_array($authority['permissions'])) {
                $authority = $authority['permissions'];
            }

            foreach ((array) $authority as $item) {
                if (is_string($item) && $item !== '') {
                    $permissions[$item] = true;
                }
            }
        }

        return array_keys($permissions);
    }

    /**
     * 获取会员所在全部用户组 ID: user 表主组 + member_authority 附加组, 去重合并
     * 有 uid 查 uid, 没有或查不到再用 uin
     */
    private function getUserGroupids(int $uid = 0, int $uin = 0): array {

        $user = null;
        if ($uid) {
            $user = app('user')->query()->where('uid', $uid)->select('uid,groupid')->one();
        }
        if ((empty($user) || empty($user['groupid'])) && $uin) {
            $user = app('user')->query()->where('uin', $uin)->select('uid,groupid')->one();
        }

        $groupids = [];
        if (!empty($user['groupid'])) {
            $groupids[] = (int) $user['groupid'];
        }

        // member_authority 中的附加用户组(管理组等, 一行一组)
        $memberUid = (int) ($user['uid'] ?? $uid);
        if ($memberUid) {
            $extra = m('user.Authority')
                ->where('uid', $memberUid)
                ->pluck('groupid')
                ->toArray();
            $groupids = array_merge($groupids, array_map('intval', (array) $extra));
        }

        return array_values(array_unique(array_filter($groupids)));
    }
}
