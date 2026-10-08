<?php

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use xphp\Collection\Arr;

use function xphp\Collection\data_set;

/**
 * 系统设置
 */
class Setting extends Authorization {
    /**
     * 设置配置参数并缓存生成文件 name 为数组则为批量设置
     * @access public
     * @param  string|array $name  配置参数名（支持二级配置 . 号分割）
     * @param  mixed        $value 配置值
     * @return mixed
     */
    public function updatecache(string $name = '', $value = []) {
        cache_write($name . '.php', $value);
        return $this->config->set($name, $value);
    }

    /**
     * 转换数据库配置
     */
    public function configvalue($iswrite = false) {
        $list = db('config')->select(['name', 'value'])->get();
        $config = [];
        foreach ($list as $value) {
            if (is_string($value->value) && json_validate($value->value)) {
                $value->value = json_decode($value->value, true);
            }
            // 支持 . 号分割的二级配置合并，如 wemedia_ai_image.api_url => ['wemedia_ai_image' => ['api_url' => value]]
            data_set($config, $value->name, $value->value);
        }

        if ($iswrite) {
            $this->updatecache('config', $config);
        }
        return $config;
    }

    /**
     * 设置读取
     */
    public function get() {

        $type = input('.type', '', 'trim');
        $formList = [];
        $appList = $this->container->get('app')->getInstalledApps(true);
        $appName = db('application')->pluck('name', 'app')->toArray();
        $config = $this->configvalue(false);
        // 更新配置
        foreach ($config as $key => &$c) {
            if (is_numeric($c) && $c == 0) {
                $c = 0;
            }
        }

        $settingList = [];
        $info = [];

        foreach ($appList as $key => $app) {
            $settingConfig = $this->config->get($app . '@admin', []);
            if (isset($settingConfig['settingList']) && is_array($settingConfig['settingList']) && $settingConfig['settingList']) {
                $settingList = array_merge($settingList, $settingConfig['settingList']);
            }
            if (isset($settingConfig['setting']) && is_array($settingConfig['setting']) && $settingConfig['setting']) {
                foreach ($settingConfig['setting'] as $key => $value) {
                    $parent = ($value['parent'] ?? $value['primary'] ?? '') ?: $app;
                    if (isset($settingList[$parent]) == false) {
                        $settingList[$parent] = $appName[$app] ?? $parent;
                    }
                    if (isset($formList[$parent]) == false) {
                        $formList[$parent] = [
                            'labelWidth' => '140px',
                            'labelPosition' => 'right',
                            'size' => 'large',
                            'column' => [],
                        ];
                    }
                    $value['component'] = $value['component'] ?? 'input';
                    $info[$value['name']] = Arr::get($config, $value['name'], $value['value'] ?? null);
                    if (in_array($value['name'], array_column($formList[$parent]['column'], 'name'))) {
                        continue;
                    }
                    $formList[$parent]['column'][] = $value;
                }
            }
        }

        $settingData = [];
        foreach ($settingList as $key => $value) {

            // 自定义配置
            if (is_array($value) && $value['type'] == 'extend') {
                $settingData[] = array_merge($value, ['key' => isset($value['key']) && $value['key'] ? $value['key'] : $key]);
            }

            // 过滤普通不存在菜单
            if (in_array($key, array_keys($formList)) == false) {
                continue;
            }

            $settingData[] = [
                'name' => $appName[$key] ?? $value,
                'key' => $key,
            ];
        }

        $extend = [];
        foreach ($settingData as $value) {
            if (isset($value['type']) && $value['type'] == 'extend') {
                $extend[$value['key']] = cache_read($value['key'], '', []);
            }
        }

        $type = input('.type', '', 'trim');
        if ($type && in_array($type, array_column($settingData, 'key'))) {
            $activename = $type;
        }

        // $settingData 指定排序, 如指定的不存在则原位置不动 ['init', 'user'] init排第一个, user排第二个
        usort($settingData, function ($a, $b) {
            $priorityKeys = ['init', 'user'];
            $aIndex = array_search($a['key'], $priorityKeys);
            $bIndex = array_search($b['key'], $priorityKeys);

            // 如果a在优先级列表中，b不在
            if ($aIndex !== false && $bIndex === false) {
                return -1;
            }
            // 如果b在优先级列表中，a不在
            if ($bIndex !== false && $aIndex === false) {
                return 1;
            }
            // 如果都在优先级列表中，按索引顺序排序
            if ($aIndex !== false && $bIndex !== false) {
                return $aIndex - $bIndex;
            }
            // 都不在优先级列表中，保持原有顺序
            return 0;
        });


        show_json([
            'code' => 200,
            'data' => [
                'formList' => $formList,
                'info' => $info,
                'extend' => $extend,
                'settingList' => $settingData,
                'activename' => $activename ?? 'init'
            ],
        ]);
    }

    /**
     * 提交修改系统配置
     * @log
     */
    public function submit() {

        $config = input('post.info', []);
        $extend = input('post.extend', []);

        // 扩展配置
        foreach ($extend as $key => $value) {
            if (empty($key) || empty($value)) {
                continue;
            }
            $this->updatecache($key, array_values($value));
        }

        // 嵌套配置展开为点分割字段，如 wemedia_ai_image.api_url，与读取时 data_set 对称
        $config = Arr::dot($config);

        $insert = [];
        foreach ($config as $key => $value) {

            if (is_null($value)) {
                $value = '';
            }

            if (is_array($value)) {
                $value = array_filter($value);
                $value = json_encode($value ?: [], JSON_UNESCAPED_UNICODE);
            }
            $insert[] = [
                'name' => $key,
                'value' => $value,
            ];
        }

        db('config')->upsert($insert, 'name', ['value']);
        $this->configvalue(true);
        show_json([
            'code' => 200,
            'message' => '操作成功',
            'data' => [
                'info' => $config,
                'extend' => $extend,
            ],
        ]);
    }
}
