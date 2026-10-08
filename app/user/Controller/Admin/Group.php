<?php

namespace App\user\Controller\Admin;

use App\admin\Authorization;

class Group extends Authorization {

    /**
     * 角色列表
     */
    public function roleList() {

        $type = input('.type', '', 'trim');

        $type = array_values(array_filter(explode(',', $type)));
        $model = m('user.group')->field('id,title,alias,type')->order('anicount asc, id asc')->where(['status' => 1]);
        if ($type) {
            $model->where(['type' => $type]);
        }
        $list = $model->get()->toArray();

        $types = [
            'system' => '系统用户组',
            'default' => '自定义用户组',
            'member' => '会员组',
        ];


        $data = [];
        foreach ($list as $value) {

            if (!isset($data[$value['type']])) {
                $data[$value['type']] = [
                    'type' => $value['type'],
                    'title' => $types[$value['type']] ?? $value['type'],
                    'children' => [],
                    'disabled' => true,
                ];
            }
            $data[$value['type']]['children'][] = $value;
        }

        show_json([
            'code' => 200,
            'data' => array_values($data),
        ]);
    }

    /**
     * 角色列表
     */
    public function lists() {

        $type = input('.type', '', 'trim');
        $model = m('user.group')->order('id desc')->json(['authority'])->where(['status' => [0, 1]]);
        if ($type) {
            $model->where(['type' => $type]);
        }
        $list = $model->get()
            ->toArray();
        $data = [];


        $authoritycount = m('user.authority')->groupBy('groupid')->pluck('count(*)', 'groupid')->toArray();
        $list = $this->container->get('array')->orderby($list, 'anicount', SORT_ASC, 'id', SORT_ASC);

        foreach ($list as $value) {
            $data[$value['type']][] = array_merge($value, [
                'status' => (string) $value['status'],
                'image' => [
                    'aid' => (int) $value['aid'],
                    'url' => $value['aid'] ? get_img($value['aid']) : '',
                ],
                'count' => $value['type'] == 'system' ? ($authoritycount[$value['id']] ?? 0)  : ($count[$value['id']] ?? 0),
                'authority' => $value['type'] == 'system' ? array_replace_recursive(['menu' => [], 'grid' => [], 'dataType' => 1], $value['authority'] ?: []) : []
            ]);
        }
        $permissions = app('user.permissions')->getPermission();
        show_json([
            'code' => 200,
            'data' => $data,
            'permissions' => $permissions ?: []
        ]);
    }

    /**
     * 删除
     * @log
     */
    public function delete() {

        $id = input('post.id', 0, 'intval');
        $model = m('user.group')->where(['id' => $id]);
        $info = $model->one();
        if (empty($info)) {
            show_json([
                'code' => 201,
                'message' => '没有找到需要删除的角色',
            ]);
        }

        $model->update(['status' => -1]);
        // 删除绑定用户关系
        $info['type'] == 'system' ? m('user.authority')->where('groupid', $info['id'])->delete() : app('user')->where('groupid', $info['id'])->update([
            'groupid' => 0
        ]);
        show_json([
            'code' => 200,
            'message' => '删除成功',
        ]);
    }

    /**
     * 数据提交
     * @log
     */
    public function submit() {

        $info = input('post.', []);
        $data = [
            'title' => trim($info['title']),
            'alias' => trim($info['alias']),
            'message' => trim($info['message']),
            'status' => $info['status'] ? 1 : 0,
            'authority' => $info['authority'] ?: [],
            'anicount' => $info['type'] == 'member' ? intval($info['anicount']) : 0,
            'aid' => $info['image']['aid'] ?? ($info['aid'] ?: 0),
            'options' => $info['options'] ?: [],
        ];

        if (empty($data['title'])) {
            show_json([
                'code' => 201,
                'message' => '请填写角色名称',
                'data' => $info
            ]);
        }

        if ($data['alias']) {
            $modelcount = m('user.group')->where(['alias' => $data['alias'], 'status' => [0, 1]]);
            if (isset($info['id']) && $info['id']) {
                $modelcount->where('id', '<>', $info['id']);
            }
            if ($modelcount->count() > 0) {
                show_json([
                    'code' => 201,
                    'message' => '别名不可以设置重复',
                ]);
            }
        }

        if (isset($info['id']) && $info['id'] > 0) {

            $model = m('user.group')->where(['id' => $info['id']]);
            $group = $model->one();
            if (empty($group)) {
                show_json([
                    'code' => 201,
                    'message' => '没有找到需要编辑的角色',
                ]);
            }

            $model->update($data);
            show_json([
                'code' => 200,
                'message' => '操作成功',
                'data' => $data,
            ]);
        }

        if (empty($info['type']) || in_array($info['type'], ['system', 'default', 'member']) == false) {
            show_json([
                'code' => 201,
                'message' => '请选择用户组类别属于',
            ]);
        }

        $model = m('user.group');
        $model->insert(array_merge($data, ['type' => $info['type']]));
        show_json([
            'code' => 200,
            'message' => '操作成功',
            'data' => $data,
        ]);
    }

    /**
     * 菜单设置
     */
    public function menuSystem() {

        $menuData = app('admin.menu')->getTreeMenu();
        $menu = $menuData['menu'] ?? [];
        show_json([
            'code' => 200,
            'data' => [
                'menu' => $menu ?: [],
                'grid' => [
                    [
                        'key' => 'welcome',
                        'title' => '欢迎',
                        'isFixed' => true,
                    ],
                    [
                        'key' => 'ver',
                        'title' => '版本信息',
                        'isFixed' => true,
                    ],
                    [
                        'key' => 'time',
                        'title' => '时钟',
                    ],
                    [
                        'key' => 'progress',
                        'title' => '进度环',
                    ],
                    [
                        'key' => 'echarts',
                        'title' => '实时收入',
                    ],
                    [
                        'key' => 'about',
                        'title' => '关于项目',
                    ],
                ],
            ],
        ]);
    }
}
