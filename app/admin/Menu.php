<?php

namespace App\admin;


use Psr\Container\ContainerInterface;

class Menu {

    public function __construct(public ContainerInterface $container) {
    }

    /**
     * 构建树形结构
     */
    private function buildTree($items, $parentKey = 'parentid', $keyField = 'id') {
        $tree = [];
        $lookup = [];

        // 创建查找表
        foreach ($items as $item) {
            $lookup[$item[$keyField]] = $item;
            $lookup[$item[$keyField]]['children'] = [];
        }

        // 构建树形结构
        foreach ($lookup as $key => $item) {
            $parentId = $item[$parentKey];
            if ($parentId === '0' || $parentId === 0 || $parentId === null) {
                $tree[] = &$lookup[$key];
            } else {
                if (isset($lookup[$parentId])) {
                    $lookup[$parentId]['children'][] = &$lookup[$key];
                }
            }
        }

        return $tree;
    }

    /**
     * 获取叶子节点（没有子节点的节点）
     */
    private function getLeafNodes($tree) {
        $leafNodes = [];

        foreach ($tree as $node) {
            $this->collectLeafNodes($node, $leafNodes);
        }

        return $leafNodes;
    }

    /**
     * 递归收集叶子节点
     */
    private function collectLeafNodes($node, &$leafNodes) {
        if (empty($node['children'])) {
            $leafNodes[] = $node['id'];
        } else {
            foreach ($node['children'] as $child) {
                $this->collectLeafNodes($child, $leafNodes);
            }
        }
    }

    /**
     * 清理树形结构，移除空的父节点
     */
    private function cleanTree($tree) {
        $cleaned = [];
        foreach ($tree as $key => $node) {
            // 如果没有子节点且没有component，跳过
            if (empty($node['children']) && empty($node['component'])) {
                // continue;
            }

            // 如果有子节点，递归清理
            if (!empty($node['children'])) {
                $node['children'] = $this->cleanTree($node['children']);
                // 如果有子节点且有component，需要特殊处理
                if (!empty($node['children']) && !empty($node['component'])) {
                    $node['name'] = $this->generateId();
                    $node['meta']['title'] = $node['meta']['parenttitle'] ?? $node['meta']['title'];
                }
            }

            $cleaned[$key] = $node;
        }

        return $cleaned;
    }

    /**
     * 生成唯一ID
     */
    private function generateId() {
        return uniqid('menu_', true);
    }

    /**
     * 读取菜单
     */
    public function getList($allowKey = []) {

        $appList = $this->container->get('app')->getInstalledApps(true);
        $registry = $this->container->get(\App\admin\Service\RegistryService::class);
        $routerlist = [];
        $route = [];
        $i = 1;
        foreach ($appList as $key => $app) {
            $config = $this->container->get('config')->get($app . '@admin', []);
            if (isset($config['router'])) {
                foreach ($config['router'] as $key => $value) {
                    $id = id(join([$app, $value['path']]));
                    $routerlist[$id] = array_replace_recursive(['parentid' => 'system', 'name' => '', 'path' => '', 'id' => $i, 'sort' => 0, 'meta' => [
                        'type' => 'menu',
                        'icon' => 'el-icon-menu',
                    ], 'component' => ''], $value, [
                        'meta' => [
                            'app' => $app,
                        ],
                    ]);
                    $i++;
                }
            }
            if (isset($config['menu'])) {
                foreach ($config['menu'] as $key => $value) {
                    $routerlist[$key] = array_replace_recursive([
                        'parentid' => 0,
                        'component' => '',
                        'path' => $key,
                        'name' => $key,
                        'id' => $i,
                        'sort' => 0,
                        'meta' => ['type' => 'menu'],
                    ], $value);
                    $i++;
                }
            }
        }


        // 应用后台接入覆盖(admin_enable 开关 / admin_config 菜单标题图标与排序, 排序前生效)
        $routerlist = $registry->applyMenuOverrides($routerlist);

        $routerlist = $this->container->get('array')->orderby($routerlist, 'sort', SORT_DESC, 'id', SORT_ASC);
        $ids = array_keys($routerlist);
        foreach ($routerlist as $key => $value) {

            if (isset($systemmenu[$key])) {
                $value = array_replace_recursive($value, $systemmenu[$key]);
            }

            $parentid = isset($value['parentid']) && in_array($value['parentid'], $ids) ? $value['parentid'] : null;
            if ($value['parentid'] && is_null($parentid)) {
                $parentid = id(join([$value['meta']['app'], $value['parentid']]));
                $parentid = in_array($parentid, $ids) ? $parentid : null;
            }

            $route[$key] = [
                'id' => $key,
                'parentid' => $parentid ?: '0',
                'name' => $value['name'] ?: $key,
                'path' => $value['meta']['type'] == 'menu' ? '/' . trim($value['path'], '/') : $registry->renderPath((string) $value['path']),
                'meta' => $value['meta'],
                'redirect' => $value['redirect'] ?? '',
                'component' => $value['component'],
            ];

            // 有子类
            if (isset($value['children']) && $value['children']) {
                foreach ($value['children'] as $ckey => $children) {

                    // 'type' != 'iframe',

                    if (isset($children['meta']['type']) && $children['meta']['type'] == 'iframe') {
                    } else {
                        $children['path'] = ($route[$key]['path'] ?? '') . '/' . (isset($children['path']) && $children['path'] ? $children['path'] : $ckey);
                    }
                    $childrenid = id(join([$value['meta']['app'], $children['path']]));
                    if (isset($systemmenu[$childrenid])) {
                        $children = array_replace_recursive($children, $systemmenu[$childrenid]);
                    }
                    $route[$childrenid] = array_replace_recursive($route[$key], $children, [
                        'id' => $childrenid,
                        'parentid' => $key,
                        'name' => $children['name'] ?? $childrenid,
                    ]);
                }
            }
        }

        // 应用后台接入覆盖(admin_enable 开关 / admin_config 顶层显示路径重写)
        $route = $registry->applyOverrides($route);

        // 权限过滤
        if ($allowKey) {
            $route = array_filter($route, function ($item) use ($allowKey) {
                return in_array($item['id'], $allowKey);
            });
        }

        return array_values($route);
    }

    /**
     * 获取树形结构菜单
     */
    public function getTreeMenu($allowKey = []) {
        $list = $this->getList($allowKey);

        // 构建树形结构
        $tree = $this->buildTree($list);
        foreach ($tree as $key => $node) {
            if (empty($node['children']) && empty($node['component'])) {
                unset($tree[$key]);
            }
        }

        // 清理树形结构
        $menu = $this->cleanTree($tree);

        // 获取叶子节点
        $leafNodes = $this->getLeafNodes($tree);

        // 查找重定向路径
        $redirect = null;
        $isDashboard = false;

        foreach ($list as $item) {
            if ($item['path'] == '/dashboard') {
                $isDashboard = true;
                break;
            }

            // 权限检查，找到第一个有权限的叶子节点作为重定向
            if (is_null($redirect) && in_array($item['id'], $leafNodes)) {
                $redirect = $item['path'];
            }
        }

        // 如果没有dashboard且有重定向路径，添加dashboard路由
        if (!$isDashboard && $redirect) {
            $menu[] = [
                'path' => '/dashboard',
                'parentid' => 0,
                'meta' => [],
                'redirect' => $redirect,
            ];
        }

        return [
            'menu' => array_values($menu ?: []),
            'leafNodes' => $leafNodes,
            'redirect' => $redirect
        ];
    }
}
