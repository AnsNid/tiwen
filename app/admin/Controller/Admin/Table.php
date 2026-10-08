<?php

declare(strict_types=1);

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use App\admin\Service\DispatchService;
use App\admin\Service\RegistryService;
use App\admin\Service\TableService;

/**
 * 获取表格结构
 */

class Table extends Authorization {

    /**
     * 声明式表格: 结构定义
     */
    public function schema() {

        $definition = $this->resolveDefinition();
        if (!$definition) {
            show_json([
                'code' => 201,
                'message' => '未找到可用的声明式表格',
            ]);
        }

        show_json([
            'code' => 200,
            'data' => [
                'name' => $definition['name'],
                'app' => $definition['app'] ?? '',
                'label' => $definition['label'] ?? '',
                'primary' => $definition['key'] ?? 'id',
                'columns' => !empty($definition['columns']) ? $definition['columns'] : ['*' => '全部列'],
                'searchable' => array_values((array) ($definition['searchable'] ?? [])),
                'editable' => array_values((array) ($definition['editable'] ?? [])),
            ],
        ]);
    }

    /**
     * 声明式表格: 分页查询
     */
    public function rows() {

        $definition = $this->requireDefinition();

        $data = $this->container->get(TableService::class)->rows($definition, [
            'keyword' => input('.keyword', '', 'trim'),
            'filter' => (array) input('.filter', []),
            'page' => input('.page', 1, 'intval'),
            'limit' => input('.limit', 20, 'intval'),
            'orderby' => input('.orderby', '', 'trim'),
            'order' => input('.order', 'desc', 'trim'),
        ]);

        show_json([
            'code' => 200,
            'data' => $data,
        ]);
    }

    /**
     * 声明式表格: 新增/保存一行(id 为空时新增)
     */
    public function save() {

        $definition = $this->requireDefinition();
        if (empty($definition['editable'])) {
            show_json([
                'code' => 201,
                'message' => '该表格未开放编辑',
            ]);
        }

        $id = input('.id', '');
        $data = (array) input('.data', []);

        try {
            $service = $this->container->get(TableService::class);
            $result = $id === '' || $id === null
                ? $service->create($definition, $data)
                : $service->update($definition, $id, $data);
        } catch (\Throwable $e) {
            show_json([
                'code' => 201,
                'message' => $e->getMessage(),
            ]);
        }

        show_json([
            'code' => 200,
            'message' => '操作成功',
            'data' => is_int($result) ? ['id' => $result] : [],
        ]);
    }

    /**
     * 声明式表格: 删除一行
     */
    public function remove() {

        $definition = $this->requireDefinition();
        $id = input('.id', '');

        if ($id === '' || $id === null) {
            show_json([
                'code' => 201,
                'message' => '缺少主键参数 id',
            ]);
        }

        try {
            $this->container->get(TableService::class)->delete($definition, $id);
        } catch (\Throwable $e) {
            show_json([
                'code' => 201,
                'message' => $e->getMessage(),
            ]);
        }

        show_json([
            'code' => 200,
            'message' => '操作成功',
        ]);
    }

    /**
     * 获取编辑结构
     */
    public function getUpdate() {

        $token = input('.token', '', 'decode');
        $name = input('.name', '', 'trim');
        $event = input('.event', '', 'trim');

        if (empty($token)) {
            show_json([
                'code' => 201,
                'message' => 'Token error',
            ]);
        }

        $eventdata = $this->container->get('event')->dispatch('table.' . $name . '@' . ($event ?: 'update'), $token);
        if ($eventdata instanceof \Psr\Http\Message\ResponseInterface) {
            return $eventdata;
        }

        show_json([
            'code' => 200,
            'data' => $eventdata ?: [],
        ]);
    }


    /**
     * 表格查询
     */
    public function get() {

        $name = input('.name', '', 'trim');
        if (strpos($name, '@') !== false) {
            list($name, $method) = explode('@', $name, 2);
            $explode = $name ? array_filter(explode('.', str_replace(['/', '\\', ':', '@'], '.', $name))) : [];
            $app = array_shift($explode);
            $abstract = join('\\', $explode);
            $eventdata = $this->container->get(DispatchService::class)->dispatchController($app, $abstract, $method);
        } else {
            $eventdata = $this->container->get('event')->dispatch('admin.table.' . $name . '@get');
        }

        if ($eventdata instanceof \Psr\Http\Message\ResponseInterface) {
            return $eventdata;
        }
        show_json([
            'code' => 200,
            'data' => $eventdata ?: [],
        ]);
    }

    /**
     * 解析声明式表格定义, 未找到时返回 null
     */
    private function resolveDefinition(): ?array {

        $name = input('.name', '', 'trim');
        if ($name === '') {
            return null;
        }

        $definition = $this->container->get(RegistryService::class)->tableDefinition($name);
        if (empty($definition) || empty($definition['table'])) {
            return null;
        }
        return $definition;
    }

    /**
     * 解析声明式表格定义, 未找到时直接输出错误
     */
    private function requireDefinition(): array {

        $definition = $this->resolveDefinition();
        if (!$definition) {
            show_json([
                'code' => 201,
                'message' => '未找到可用的声明式表格',
            ]);
        }
        return $definition;
    }
}
