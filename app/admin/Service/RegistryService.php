<?php

declare(strict_types=1);

namespace App\admin\Service;

use Psr\Container\ContainerInterface;

/**
 * 后台接入注册中心
 * 聚合各应用 Config/admin.php 的静态声明(router/menu/settingList/setting/tables),
 * 叠加 application 表中的持久化配置(admin_enable/admin_config), 产出统一的接入视图
 */
class RegistryService {

    /**
     * dispatchAllowed 进程内缓存(应用 => [值, 过期时间戳])
     * 避免每个后台请求都查 application 表; TTL 兜底多 Worker 间的陈旧窗口
     */
    private static array $allowedCache = [];

    private const ALLOWED_CACHE_TTL = 30;

    public function __construct(private ContainerInterface $container) {
    }

    /**
     * 全部应用的注册信息, 以 app 名为键
     */
    public function registrations(): array {
        try {
            $rows = m('application')->get()->toArray();
        } catch (\Throwable) {
            return [];
        }
        return array_column((array) $rows, null, 'app');
    }

    /**
     * 指定应用的静态声明(Config/admin.php)
     */
    public function declarations(string $app): array {
        $config = $this->container->get('config')->get($app . '@admin', []);
        return is_array($config) ? $config : [];
    }

    /**
     * 路由声明占位符替换
     * $adminApi$ = 后台统一分发 API 前缀 (/{adminhostpath}/api), 让独立端口等应用
     * 以纯静态字符串声明后台入口, 配置文件中无需编写动态取值逻辑
     */
    public function renderPath(string $path): string {
        if (!str_contains($path, '$adminApi$')) {
            return $path;
        }
        try {
            $adminPath = rtrim((string) $this->container->get('config')->sysget('config.adminhostpath', 'admin'), '/') ?: 'admin';
        } catch (\Throwable) {
            $adminPath = 'admin';
        }
        return str_replace('$adminApi$', '/' . $adminPath . '/api', $path);
    }

    /**
     * 独立端口应用的访问地址: 当前访问 host + 应用 ConfigProvider 声明的服务端口
     * (server.servers 由各应用 ConfigProvider 合并而来, 端口改动自动同步)
     */
    public function standaloneUrl(string $serverName, int $fallbackPort = 0, string $confKey = ''): string {


        if ($confKey) {
            $domain = $this->container->get('config')->sysget($confKey);
            $domains = array_values(array_filter(explode(',', str_replace(';', ',', $domain ?? ''))));
            if ($domains && count($domains) > 0) {
                // 随机选择一个域名
                $domain = $domains[array_rand($domains)];
                // 是否包含@
                if (str_contains($domain, '@')) {
                    $domain = explode('@', $domain)[1];
                }
                return $domain;
            }
        }

        $port = 0;
        foreach ((array) $this->container->get('config')->get('server.servers', []) as $server) {
            if (($server['name'] ?? '') === $serverName) {
                $port = (int) ($server['port'] ?? 0);
                break;
            }
        }
        $uri = $this->container->get('request')->getUri();
        return $uri->getScheme() . '://' . $uri->getHost() . ':' . ($port ?: $fallbackPort);
    }

    /**
     * 指定应用的持久化后台配置(admin_config JSON)
     */
    public function overrides(string $app): array {
        try {
            $row = m('application')->where(['app' => $app])->one();
        } catch (\Throwable) {
            return [];
        }
        if (empty($row)) {
            return [];
        }
        $data = is_array($row) ? ($row['admin_config'] ?? '') : ($row->admin_config ?? '');
        return json_decode((string) $data, true) ?: [];
    }

    /**
     * 应用是否开放后台访问(admin_enable=0 时关闭)
     * 未注册的应用(open_module_list 内置等)默认开放
     */
    public function dispatchAllowed(string $app): bool {

        $cached = self::$allowedCache[$app] ?? null;
        if (is_array($cached) && $cached['expires'] > microtime(true)) {
            return $cached['value'];
        }

        $allowed = $this->resolveDispatchAllowed($app);

        self::$allowedCache[$app] = [
            'value' => $allowed,
            'expires' => microtime(true) + self::ALLOWED_CACHE_TTL,
        ];
        return $allowed;
    }

    /**
     * 清空 dispatchAllowed 进程内缓存
     * 应用注册信息变化(install/uninstall/update/setStatus)时由 ApplicationService::flushCaches 触发
     */
    public static function flushDispatchCache(): void {
        self::$allowedCache = [];
    }

    private function resolveDispatchAllowed(string $app): bool {
        try {
            $row = m('application')->where(['app' => $app])->one();
        } catch (\Throwable) {
            return true;
        }
        if (empty($row)) {
            return true;
        }
        $enable = is_array($row) ? ($row['admin_enable'] ?? 1) : ($row->admin_enable ?? 1);
        return (int) $enable === 1;
    }

    /**
     * 将应用级与条目级持久化覆盖合并进菜单声明集(Menu::getList 在排序前调用)
     * 每条优先级: entries[声明path] > 应用级字段(顶层条目, 或单入口应用的唯一条目) > 静态声明
     * admin_enable=0 的应用整组移除; 排序(sort)在本阶段写入后才真实生效
     */
    public function applyMenuOverrides(array $items): array {

        $registrations = $this->registrations();
        if (!$registrations) {
            return $items;
        }

        $countByApp = [];
        foreach ($items as $item) {
            $app = $item['meta']['app'] ?? '';
            if ($app !== '') {
                $countByApp[$app] = ($countByApp[$app] ?? 0) + 1;
            }
        }

        foreach ($items as $key => $item) {
            $app = $item['meta']['app'] ?? '';
            if ($app === '' || !isset($registrations[$app])) {
                continue;
            }

            $registration = $registrations[$app];
            if ((int) ($registration['admin_enable'] ?? 1) !== 1) {
                unset($items[$key]);
                continue;
            }

            $config = json_decode((string) ($registration['admin_config'] ?? ''), true);
            if (empty($config) || !is_array($config)) {
                continue;
            }

            // 覆盖键与声明键(declaredEntries)一致: 优先条目声明 name, 未命名时回退显示 path
            $entryKey = ltrim((string) ($item['name'] ?? '') ?: (string) ($item['path'] ?? ''), '/');
            $override = (array) ($config['entries'][$entryKey] ?? []);
            $isTopLevel = in_array($item['parentid'] ?? 0, [0, '0', null], true);
            $appLevel = ($isTopLevel || ($countByApp[$app] ?? 0) === 1) ? $config : [];

            // 条目级访问地址覆盖 (iframe/外链入口的打开地址)
            if (!empty($override['path']) && is_string($override['path'])) {
                $items[$key]['path'] = $override['path'];
            }
            $title = (string) ($override['title'] ?? ($appLevel['title'] ?? ''));
            if ($title !== '') {
                $items[$key]['meta']['title'] = $title;
            }
            $icon = (string) ($override['icon'] ?? ($appLevel['icon'] ?? ''));
            if ($icon !== '') {
                $items[$key]['meta']['icon'] = $icon;
            }
            if (array_key_exists('hidden', $override)) {
                $items[$key]['meta']['hidden'] = (bool) $override['hidden'];
            } elseif (!empty($appLevel['hidden'])) {
                $items[$key]['meta']['hidden'] = true;
            }
            if (array_key_exists('sort', $override)) {
                $items[$key]['sort'] = (int) $override['sort'];
            } elseif (isset($appLevel['sort']) && $appLevel['sort'] !== '') {
                $items[$key]['sort'] = (int) $appLevel['sort'];
            }
        }

        return $items;
    }

    /**
     * 将应用级持久化配置应用到已构建的路由/菜单表
     * 仅重写顶层条目(parentid=0)的显示路径; 标题/图标/隐藏/排序的覆盖与
     * admin_enable 移除在 applyMenuOverrides(排序前)完成
     */
    public function applyOverrides(array $routes): array {

        $registrations = $this->registrations();
        if (!$registrations) {
            return $routes;
        }

        foreach ($routes as $key => $item) {
            $app = $item['meta']['app'] ?? '';
            if (!$app || !isset($registrations[$app])) {
                continue;
            }

            // 非顶层条目不做覆盖(子项路径由前端按树形层级组装)
            if (($item['parentid'] ?? '0') !== '0') {
                continue;
            }

            $config = json_decode((string) ($registrations[$app]['admin_config'] ?? ''), true);
            if (!empty($config['path']) && is_string($config['path'])) {
                $routes[$key]['path'] = '/' . trim($config['path'], '/');
            }
        }

        return $routes;
    }

    /**
     * 汇总某应用声明的表格定义
     * 声明格式(Config/admin.php 的 tables 段):
     * [ 'orders' => ['label'=>'订单','table'=>'#@_orders','columns'=>[...],'searchable'=>[...],'editable'=>[...]] ]
     * admin_config.tables 中同名项的 label/searchable/editable 可覆盖
     */
    public function tables(string $app): array {

        $declared = $this->declarations($app)['tables'] ?? [];
        if (!is_array($declared)) {
            $declared = [];
        }

        $override = (array) ($this->overrides($app)['tables'] ?? []);
        foreach ($declared as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }
            $extra = isset($override[$name]) && is_array($override[$name]) ? $override[$name] : [];
            $merged = array_merge([
                'label' => $name,
                'table' => '',
                'columns' => [],
                'searchable' => [],
                'editable' => [],
            ], $definition, array_intersect_key($extra, ['label' => 1, 'searchable' => 1, 'editable' => 1]));
            $merged['name'] = $name;
            $merged['app'] = $app;
            $declared[$name] = $merged;
        }

        return array_values($declared);
    }

    /**
     * 找到指定表格定义(检索键: {app}.{name} 或全局 {name})
     */
    public function tableDefinition(string $reference): ?array {

        if (str_contains($reference, '.')) {
            [$app, $name] = explode('.', $reference, 2);
            $found = $this->findTable($app, $name);
            if ($found) {
                return $found;
            }
        }

        foreach ($this->installedApps() as $app) {
            $found = $this->findTable($app, $reference);
            if ($found) {
                return $found;
            }
        }
        return null;
    }

    private function findTable(string $app, string $name): ?array {
        foreach ($this->tables($app) as $table) {
            if (($table['name'] ?? '') === $name) {
                return $table;
            }
        }
        return null;
    }

    private function installedApps(): array {
        try {
            return array_keys((array) $this->container->get('app')->getInstalledApps(true));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 应用配置界面数据源: 声明入口与持久化覆盖的合并视图(含隐藏条目, 供重新启用)
     */
    public function appEntries(string $app): array {
        return $this->mergeEntries((string) $app, false);
    }

    /**
     * 桌面视图(system/index 的 apps): 每个开放后台的应用打包为一个图标组,
     * 展开其 router/menu 声明中的全部可进入入口; admin_config.desktop=false
     * 时应用标记 desktop=false, 由前端在启动台/桌面隐藏, 左侧菜单与接口不受影响
     */
    public function desktopApps(): array {

        try {
            $installed = (array) $this->container->get('app')->getInstalledApps(true);
        } catch (\Throwable) {
            return [];
        }

        // 单次取全量注册行, 避免逐应用 3 次 DB 查询(overrides×2 + registration×1)的 N+1
        $registrations = $this->registrations();

        $apps = [];
        foreach ($installed as $app) {
            $app = (string) $app;

            $registration = (array) ($registrations[$app] ?? []);

            // admin_enable=0 的应用整组不进桌面(与 dispatchAllowed 同语义)
            if ((int) ($registration['admin_enable'] ?? 1) !== 1) {
                continue;
            }

            $config = json_decode((string) ($registration['admin_config'] ?? ''), true) ?: [];

            // 应用级桌面开关: 关闭时仅从桌面隐藏, 左侧菜单与接口不受影响
            $desktopOn = !isset($config['desktop']) || (bool) $config['desktop'];

            $entries = array_map(fn($e) => [
                'id' => $e['id'],
                'key' => $e['key'] ?? '',
                'name' => $e['key'] ?? '',
                'title' => $e['title'],
                'icon' => $e['icon'],
                'type' => $e['type'],
                'path' => $e['path'],
                'tablename' => $e['tablename'],
                'initialapi' => $e['initialapi'],
                'component' => $e['component'],
                'maximize' => !empty($e['maximize']),
            ], $this->mergeEntries($app, true, $config));

            if (!$entries) {
                continue;
            }

            // 本地安装配置(读文件)仅作为注册行缺失/字段为空时的兜底
            $localConfig = [];
            if (!$registration || ((string) ($registration['name'] ?? '') === '' && (string) ($registration['description'] ?? '') === '')) {
                try {
                    $localConfig = (array) app('admin.application')->config($app);
                } catch (\Throwable) {
                }
            }

            $apps[] = [
                'app' => $app,
                'title' => (string) (($config['title'] ?? '') ?: ($registration['name'] ?? ($localConfig['name'] ?? $app))),
                'description' => (string) ($registration['description'] ?? ($localConfig['description'] ?? '')),
                'version' => (string) ($registration['version'] ?? ($localConfig['version'] ?? '')),
                'icon' => (string) ($config['icon'] ?? ''),
                'desktop' => $desktopOn,
                'entries' => $entries,
            ];
        }

        return $apps;
    }

    /**
     * 汇总应用声明的入口(静态声明, 未合并覆盖)
     * 覆盖键: router 条目为声明 path, 菜单散项为声明分组键
     */
    private function declaredEntries(string $app): array {

        $declared = $this->declarations($app);
        $entries = [];

        foreach ((array) ($declared['router'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $meta = is_array($item['meta'] ?? null) ? $item['meta'] : [];
            $title = (string) ($meta['title'] ?? ($item['name'] ?? ''));
            if ($title === '') {
                continue;
            }
            $type = 'page';
            if (($meta['type'] ?? '') === 'iframe') {
                $type = 'iframe';
            } elseif (!empty($meta['tablename'])) {
                $type = 'table';
            } elseif (!empty($meta['initialapi'])) {
                $type = 'initial';
            }
            // 稳定键: 优先声明 name (path 可能随应用配置变化, 如独立端口应用的访问域名/端口),
            // 桌面固定等持久化身份依赖该键, 不随地址修改而漂移
            $key = ltrim((string) ($item['name'] ?? '') ?: (string) ($item['path'] ?? ''), '/');
            $entries[$key] = [
                'id' => id(join([$app, $key])),
                'title' => $title,
                'icon' => (string) ($meta['icon'] ?? ''),
                'type' => $type,
                'path' => $this->renderPath((string) ($item['path'] ?? '')),
                'tablename' => (string) ($meta['tablename'] ?? ''),
                'initialapi' => (string) ($meta['initialapi'] ?? ''),
                'component' => (string) ($item['component'] ?? ''),
                'hidden' => !empty($meta['hidden']),
                'maximize' => !empty($meta['maximize']),
                'sort' => 0,
            ];
        }

        // menu 分组内直接挂 tablename 的散项
        foreach ((array) ($declared['menu'] ?? []) as $key => $group) {
            $meta = is_array($group['meta'] ?? null) ? $group['meta'] : [];
            if (empty($meta['tablename'])) {
                continue;
            }
            $key = ltrim((string) $key, '/');
            $entries[$key] = [
                'id' => id(join([$app, (string) $key])),
                'title' => (string) ($meta['title'] ?? $key),
                'icon' => (string) ($meta['icon'] ?? ''),
                'type' => 'table',
                'path' => (string) $key,
                'tablename' => (string) $meta['tablename'],
                'initialapi' => '',
                'component' => '',
                'hidden' => !empty($meta['hidden']),
                'maximize' => !empty($meta['maximize']),
                'sort' => 0,
            ];
        }

        return $entries;
    }

    /**
     * 合并应用级与条目级持久化覆盖
     * 优先级: entries[覆盖键] > 应用级 title/icon(仅单入口应用回落) > 静态声明
     * $filterHidden 时剔除隐藏条目(桌面视图); 配置视图传 false 保留全部
     * $preloadedConfig 传入已取好的应用级持久化配置时不再重复查询(desktopApps 批量路径)
     */
    private function mergeEntries(string $app, bool $filterHidden, ?array $preloadedConfig = null): array {

        $entries = $this->declaredEntries($app);
        if (!$entries) {
            return [];
        }

        $config = $preloadedConfig ?? $this->overrides($app);
        $overrides = (array) ($config['entries'] ?? []);
        $single = count($entries) === 1;

        $merged = [];
        foreach ($entries as $key => $entry) {
            $override = (array) ($overrides[$key] ?? []);
            $hidden = array_key_exists('hidden', $override) ? (bool) $override['hidden'] : (bool) $entry['hidden'];
            if ($filterHidden && $hidden) {
                continue;
            }
            $title = (string) ($override['title'] ?? ($single ? ($config['title'] ?? '') : ''));
            $icon = (string) ($override['icon'] ?? ($single ? ($config['icon'] ?? '') : ''));
            $entryPath = (string) ($override['path'] ?? '') !== ''
                ? $this->renderPath((string) $override['path'])
                : (string) $entry['path'];
            $maximize = array_key_exists('maximize', $override)
                ? (bool) $override['maximize']
                : !empty($entry['maximize']);
            $merged[] = [
                'id' => $entry['id'],
                'key' => $key,
                'title' => $title !== '' ? $title : $entry['title'],
                'icon' => $icon !== '' ? $icon : $entry['icon'],
                'title_declared' => $entry['title'],
                'icon_declared' => $entry['icon'],
                'type' => $entry['type'],
                'path' => $entryPath,
                'path_declared' => $entry['path'],
                'tablename' => $entry['tablename'],
                'initialapi' => $entry['initialapi'],
                'component' => $entry['component'],
                'hidden' => $hidden,
                'maximize' => $maximize,
                'sort' => (int) ($override['sort'] ?? 0),
            ];
        }

        usort($merged, fn($a, $b) => [$b['sort'], $a['key']] <=> [$a['sort'], $b['key']]);

        return $merged;
    }
}
