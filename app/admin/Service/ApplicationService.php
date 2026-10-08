<?php

declare(strict_types=1);

namespace App\admin\Service;

use Exception;
use Psr\Container\ContainerInterface;
use xphp\Framework\App;
use xphp\Framework\AppInstall;

/**
 * 应用生命周期服务
 * 安装/卸载/更新/状态切换的统一实现, HTTP 控制器与 CLI 命令共用
 */
class ApplicationService {
    public function __construct(private ContainerInterface $container) {
    }

    /**
     * 输出器: 无回调时静默
     */
    private function output(?callable $output = null): \Closure {
        return function (string $message, string $type = 'info') use ($output) {
            if ($output) {
                $output($message, $type);
            }
        };
    }

    /**
     * 系统保护应用(open_module_list), 不允许卸载/停用
     */
    public function protectedApps(): array {
        return (array) $this->container->get('config')->get('app.open_module_list', []);
    }

    public function isProtected(string $app): bool {
        return in_array($app, $this->protectedApps(), true);
    }

    /**
     * 应用是否已注册安装(application 表)
     */
    public function installed(string $app): bool {
        return (bool) m('application')->where(['app' => $app])->value('id');
    }

    /**
     * 安装应用
     * 本地缺少 Install 目录时会先从云端下载 phar
     * 新装的独立运行(standalone)应用不重启主服务, 安装完成后直接经 xphp daemon start 拉起其守护实例
     */
    public function install(string $app, string $db = 'default', bool $restart = false, ?callable $output = null): bool {

        if ($this->installed($app)) {
            throw new Exception('应用已安装, 如需重新安装请先卸载后操作');
        }

        $installer = $this->container->get(AppInstall::class);
        $installer->setCommandMode(true, function ($message, $type = 'info') use ($output) {
            if ($output) {
                $output((string) $message, (string) $type);
            }
        });

        $result = (bool) $installer->install($app, $restart, false, $db ?: 'default');

        // 新装的独立运行应用: 直接启动其守护实例 (start, 而非重启主服务)
        if ($result && $this->hasStandaloneServer($app)) {
            $out = $this->output($output);
            // 新应用的类与注解不在既有 scan.cache 中, 必须清除后由 daemon 拉起的实例重新扫描,
            // 否则新实例的路由/注解不生效
            $this->clearContainerCache();
            $out("检测到独立服务 {$app}, 正在启动守护实例...", 'restart');
            $this->daemonCommand('start', $app, $out);
        }

        return $result;
    }

    /**
     * 经 xphp 工具向守护器下发实例命令 (start/restart), 异步执行不阻塞当前流程
     * 工具缺失时输出终端手动执行命令
     */
    private function daemonCommand(string $action, string $name, \Closure $out): bool {
        $tool = \App\admin\Controller\Admin\Daemon::findTool();
        if ($tool === '') {
            $out("未找到 xphp 工具, 请在服务器终端执行: xphp -d " . BASE_PATH . " daemon {$action} {$name}", 'comment');
            return false;
        }

        $inner = 'exec ' . escapeshellarg($tool) . ' -d ' . escapeshellarg(BASE_PATH)
            . ' daemon ' . $action . ' ' . escapeshellarg($name);
        exec('nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');

        $out("守护指令已发出: daemon {$action} {$name}", 'restart');
        return true;
    }

    /**
     * 更新应用
     * 刷新云端文件 → 幂等补建缺失数据表(含种子数据) → 重写注册信息与缓存
     * 不自动重启服务, 由调用方/管理员手动重启使新版本完全生效
     * 已存在的表和数据不会被动到; isphar 应用与声明独立 standalone 服务的应用同样适用
     */
    public function update(string $app, string $db = 'default', bool $restart = false, ?callable $output = null): bool {

        $out = $this->output($output);

        if (!is_dir($this->container->get(App::class)->getAppPath($app, '')) && !$this->pharExists($app)) {
            throw new Exception("应用 {$app} 不存在");
        }

        // 1. 云端文件刷新(未登录开发者账号或非云应用时自动跳过)
        $out('正在检查应用文件更新...');
        if ($this->refreshFromCloud($app)) {
            $out('应用文件已更新为最新版本');
        } else {
            $out('使用本地已有应用文件', 'comment');
        }

        // 2. 幂等导入数据库结构: 仅创建缺失的表及其种子数据, 已有表与数据完全不动
        $out('正在检查数据库结构...');
        $result = $this->idempotentImport($app, $db ?: 'default', $out);
        $out("数据库结构检查完成(新建 {$result['created']} 张, 跳过 {$result['skipped']} 条语句)");

        // 3. 重写注册信息(version/status 等, 不触碰管理员配置字段)
        $config = $this->container->get(AppInstall::class)->getAppConfig($app);
        if (empty($config)) {
            throw new Exception('无法获取应用配置');
        }

        m('application')->updateOrInsert(
            ['app' => $app],
            [
                'version' => $config['version'] ?? '',
                // 取前 500 字个字符
                'description' => substr($config['description'] ?? '', 0, 500) ?? '',
                'author' => $config['author'] ?? '',
                'timestamp' => time(),
                'status' => max(1, $this->registeredStatus($app)),
            ]
        );

        // 与卸载/启停同规则: admin/user 等系统应用通过系统升级流程更新
        if ($this->isProtected($app)) {
            return true;
        }

        // 4. 重建缓存并清理进程内已装列表
        $this->flushCaches();
        $out("应用 {$app} 更新完成");

        // 更新不自动重启: 新版本代码需进程重启后才能完全生效 (多 Worker 与独立服务均如此),
        // 由管理员择机手动重启, 避免更新瞬间打断在线请求
        // 新版本可能新增类/注解, 先清除容器扫描缓存, 手动重启后自动重新扫描
        $this->clearContainerCache();
        $out('请手动重启服务以加载新版本', 'restart');
        if ($this->hasStandaloneServer($app)) {
            $out("检测到独立服务 {$app}, 请执行: xphp -d " . BASE_PATH . " daemon restart {$app}", 'restart');
        }
        return true;
    }

    /**
     * 清理 DI 容器缓存目录 (runtime/container, 含注解扫描缓存 scan.cache)
     * 新装/更新应用的类与注解不在既有扫描缓存中, 清除后由新进程重新扫描生成
     */
    private function clearContainerCache(): void {
        $dir = runtime_path('container');
        if (!is_dir($dir)) {
            return;
        }
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($dir);
        } catch (\Throwable) {
            // 清理失败不影响主流程, 重启时以 --clear-container 兜底
        }
    }

    /**
     * 应用是否声明了独立运行服务(standalone server)
     * 新装/新上传的应用不在当前 Worker 的自动加载表与配置缓存中,
     * 因此直接读应用目录(或 phar 内)的 ConfigProvider 文件实时判定
     */
    public function hasStandaloneServer(string $app): bool {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $app)) {
            return false;
        }

        $candidates = [BASE_PATH . '/app/' . $app . '/ConfigProvider.php'];
        $pharPath = $this->container->get(App::class)->getAppPharPath($app);
        if ($pharPath && is_file($pharPath)) {
            $candidates[] = 'phar://' . $pharPath . '/ConfigProvider.php';
        }

        foreach ($candidates as $file) {
            if (!is_file($file)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            if (!str_contains($content, 'standalone')) {
                continue;
            }

            // 手动 include 定义类 (不依赖 Worker 的自动加载表), 解析 provider 返回结构
            $class = 'App\\' . $app . '\\ConfigProvider';
            if (!class_exists($class, false)) {
                include_once $file;
            }
            if (class_exists($class, false)) {
                try {
                    $provider = (array) (new $class())();
                    foreach ((array) ($provider['server']['servers'] ?? []) as $server) {
                        if (is_array($server) && !empty($server['standalone'])) {
                            return true;
                        }
                    }
                    return false;
                } catch (\Throwable) {
                    // 实例化/调用失败时落到文本兜底
                }
            }
            return (bool) preg_match("/['\"]standalone['\"]\s*=>\s*true/", $content);
        }
        return false;
    }

    /**
     * 卸载应用
     * 默认保留数据表, 需要显式传入 $dropTables 才会删除该应用声明的表
     */
    public function uninstall(string $app, bool $dropTables = false, string $db = 'default', ?callable $output = null): void {

        $out = $this->output($output);

        if ($this->isProtected($app)) {
            throw new Exception('系统应用不允许卸载');
        }

        if (!$this->installed($app)) {
            throw new Exception("应用 {$app} 未安装");
        }

        // 先收集要删除的表再删注册记录
        $tables = $dropTables ? $this->appTables($app, $db ?: 'default') : [];

        // 独立运行的应用: 先停掉守护实例 (须在删 phar 之前判定, isphar 应用的声明在 phar 内)
        $standalone = $this->hasStandaloneServer($app);

        m('application')->where(['app' => $app])->delete();

        if ($tables) {
            $connection = $this->container->get(\xphp\DbConnection\Db::class)->connection($db ?: 'default');
            foreach ($tables as $table) {
                $out("正在删除数据表 {$table}...");
                $connection->statement('DROP TABLE IF EXISTS `' . str_replace('`', '', $table) . '`');
            }
            $out('数据表删除完成(' . count($tables) . '张)');
        } else {
            $out('保留数据表, 应用数据未删除', 'comment');
        }

        if ($standalone) {
            $out("检测到独立服务 {$app}, 正在停止守护实例...", 'restart');
            $this->daemonCommand('stop', $app, $out);
        }

        // 清理 phar 文件缓存
        $this->removePhar($app);

        // 卸载后应用的类不再参与注解扫描, 清理容器缓存待下次重启重扫
        $this->clearContainerCache();

        $this->flushCaches();
        $out("应用 {$app} 卸载完成");
    }

    /**
     * 设置应用启停状态(status)
     */
    public function setStatus(string $app, int $status): void {

        if ($this->isProtected($app)) {
            throw new Exception('系统应用不支持此操作');
        }

        $model = m('application')->where(['app' => $app]);
        if (empty($model->one())) {
            throw new Exception('没有找到相关的应用');
        }
        $model->update(['status' => $status ? 1 : 0]);
        $this->flushCaches();
    }

    /**
     * 从云端刷新应用文件(需要开发者账号登录)
     * 返回是否有实际更新
     */
    private function refreshFromCloud(string $app): bool {

        // 未登录开发者账号时跳过, 更新仍可基于本地文件进行
        $user = $this->container->get('cloud')->get('keyUsage');
        if (empty($user) || empty($user['uid'])) {
            return false;
        }

        $downloadUrl = $this->container->get('cloud')->getDownloadAppUrl($app);
        if (empty($downloadUrl)) {
            return false;
        }

        $appContainer = $this->container->get(App::class);
        $appPharPath = $appContainer->getAppPharPath($app);
        $this->removePhar($app);
        $this->container->get('cloud')->downloadFile($downloadUrl, $appPharPath);

        // 非 phar 应用需解压落盘
        try {
            $config = $this->container->get(AppInstall::class)->getAppConfig($app) ?: [];
        } catch (\Throwable) {
            $config = [];
        }

        if (file_exists($appPharPath) && empty($config['isphar'])) {
            $phar = new \Phar($appPharPath);
            if ($phar->isCompressed() === \Phar::GZ || $phar->isCompressed() === \Phar::BZ2) {
                $phar->decompress();
            }
            $phar->extractTo($appContainer->getAppPath($app, '', true), null, true);
        }
        return true;
    }

    /**
     * 幂等导入 Install/install.sql
     * 按表分组执行: 库中不存在的表整组执行其 DROP/CREATE/INSERT 语句(建表+种子数据),
     * 已存在的表整组跳过(更新绝不删表、不重复插种子、不动已有结构与数据)
     */
    private function idempotentImport(string $app, string $db, \Closure $out): array {

        $sqlFile = $this->container->get(App::class)->getAppPath($app, 'Install') . 'install.sql';

        // 无传统 SQL 文件的应用走框架 schema 导入通道
        if (!file_exists($sqlFile)) {
            try {
                $this->container->get(AppInstall::class)->importSql($app, 'install', $db);
            } catch (\Throwable $e) {
                $message = $e->getMessage();
                if (stripos($message, 'exist') === false && stripos($message, '已存在') === false) {
                    throw new Exception($message);
                }
                $out('部分表结构已存在, 自动跳过', 'comment');
            }
            return ['created' => 0, 'skipped' => 0];
        }

        $prefix = (string) $this->container->get('config')->get("databases.{$db}.prefix", '');
        $content = preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($sqlFile));
        $statements = array_values(array_filter(array_map('trim', (array) preg_split('/;\s*[\r\n]+/', (string) $content))));

        try {
            $existing = $this->existingTables($db);
        } catch (\Throwable $e) {
            throw new Exception('无法读取数据库表清单, 已中止结构导入以保护数据: ' . $e->getMessage());
        }
        $created = 0;
        $skipped = 0;
        $freshTables = [];

        $connection = $this->container->get(\xphp\DbConnection\Db::class)->connection($db);
        foreach ($statements as $sql) {
            $parsed = $this->statementTable($sql, $prefix);
            if ($parsed === null) {
                $skipped++;
                continue;
            }
            [$table, $type] = $parsed;

            // 更新流程绝不执行 DROP: 它只为全新安装与 CREATE 配对出现, 已有表的数据必须保护
            if ($type === 'DROP') {
                $skipped++;
                continue;
            }

            $isFresh = in_array($table, $freshTables, true);
            if (in_array($table, $existing, true) && !$isFresh) {
                // 已存在的表整组跳过
                $skipped++;
                continue;
            }

            if ($type === 'CREATE') {
                $freshTables[] = $table;
                $created++;
            } elseif (!$isFresh) {
                // 表既不在库中也不在本次新建清单(异常语句序列), 保护性跳过
                $skipped++;
                continue;
            }

            $connection->statement(str_replace('#@_', $prefix, $sql));
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * 解析语句针对的数据表名与语句类型(识别 #@_ 前缀占位), 无法识别时返回 null
     * @return array{0: string, 1: string}|null [表名(含前缀小写), 类型(DROP|CREATE|ALTER|TRUNCATE|INSERT|REPLACE)]
     */
    private function statementTable(string $sql, string $prefix): ?array {
        $table = null;
        $type = '';
        if (preg_match('/^(DROP|CREATE|ALTER|TRUNCATE)\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?#@_([A-Za-z0-9_]+)`?/is', trim($sql), $matches)) {
            [$type, $table] = [$matches[1], $matches[2]];
        } elseif (preg_match('/^(INSERT|REPLACE)\s+(?:IGNORE\s+)?INTO\s+`?#@_([A-Za-z0-9_]+)`?/is', trim($sql), $matches)) {
            [$type, $table] = [$matches[1], $matches[2]];
        } else {
            return null;
        }
        return [strtolower($prefix . $table), strtoupper($type)];
    }

    /**
     * 当前连接中已存在的表名集合(小写)
     * 查询失败或结果解析不出表名时直接抛出, 绝不能按空集合处理——那会让幂等守卫放行全部 DROP/CREATE
     */
    private function existingTables(string $db): array {
        $rows = $this->container->get(\xphp\DbConnection\Db::class)
            ->connection($db)
            ->select('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');

        $tables = [];
        foreach ((array) $rows as $row) {
            // 列标签大小写随服务端而异(MySQL 8 返回 TABLE_NAME, MariaDB 跟随查询写法), 一律取首列值
            $columns = array_values(is_array($row) ? $row : (array) $row);
            $value = $columns[0] ?? null;
            if ($value) {
                $tables[] = strtolower((string) $value);
            }
        }

        if ($rows && empty($tables)) {
            throw new \RuntimeException('表清单解析为空, 结果列名不可识别');
        }

        return $tables;
    }

    /**
     * 收集应用声明的数据表名(含前缀), 用于卸载删表
     * 来源: application.tables 字段 + Install/install.sql 建表语句
     */
    private function appTables(string $app, string $db): array {

        $prefix = (string) $this->container->get('config')->get("databases.{$db}.prefix", '');
        $names = [];

        // 注册信息中的 tables 字段(逗号分隔, 一般不带前缀)
        $declared = (string) m('application')->where(['app' => $app])->value('tables');
        foreach (array_filter(array_map('trim', explode(',', $declared))) as $name) {
            $names[] = strtolower(str_starts_with($name, $prefix) ? $name : $prefix . $name);
        }

        // install.sql 中声明的建表
        $sqlFile = $this->container->get(App::class)->getAppPath($app, 'Install') . 'install.sql';
        if (file_exists($sqlFile)) {
            $content = (string) file_get_contents($sqlFile);
            if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`#@_([A-Za-z0-9_]+)`/is', $content, $matches)) {
                foreach ($matches[1] as $name) {
                    $names[] = strtolower($prefix . $name);
                }
            }
        }

        // 只允许删除当前库中真实存在的表
        return array_values(array_unique(array_intersect($names, $this->existingTables($db))));
    }

    /**
     * 幂等补齐 application 表的后台管理扩展字段(admin_enable/admin_config)
     * 供存量环境自动迁移; 调用方需自行做进程级缓存避免每次请求重复执行
     */
    public function ensureAdminColumns(): void {

        $prefix = (string) $this->container->get('config')->get('databases.default.prefix', '');
        $table = $prefix . 'application';

        try {
            $connection = $this->container->get(\xphp\DbConnection\Db::class)->connection('default');
            $rows = $connection->select("SHOW COLUMNS FROM `{$table}`");
        } catch (\Throwable) {
            return;
        }

        // 不同驱动下键名大小写不一致(Field/field), 统一做兜底取值
        $columns = [];
        foreach ((array) $rows as $row) {
            $value = is_array($row)
                ? ($row['field'] ?? $row['Field'] ?? reset($row))
                : ($row->field ?? $row->Field ?? null);
            if ($value) {
                $columns[] = strtolower((string) $value);
            }
        }

        // 多 Worker 并发启动时可能重复执行, 忽略"列已存在"的竞争失败
        $ignoreDuplicate = function (\Throwable $e) {
            return str_contains($e->getMessage(), '1060') || str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), '已存在');
        };

        if (!in_array('admin_enable', $columns, true)) {
            try {
                $connection->statement("ALTER TABLE `{$table}` ADD COLUMN `admin_enable` tinyint(1) DEFAULT '1' COMMENT '是否进入后台管理'");
            } catch (\Throwable $e) {
                if (!$ignoreDuplicate($e)) {
                    throw $e;
                }
            }
        }
        if (!in_array('admin_config', $columns, true)) {
            try {
                $connection->statement("ALTER TABLE `{$table}` ADD COLUMN `admin_config` text COMMENT '后台管理配置JSON(路径/菜单/表格等)'");
            } catch (\Throwable $e) {
                if (!$ignoreDuplicate($e)) {
                    throw $e;
                }
            }
        }
    }

    /**
     * 删除应用的 phar 文件缓存
     */
    private function removePhar(string $app): void {
        $pharPath = $this->container->get(App::class)->getAppPharPath($app);
        if ($pharPath && file_exists($pharPath)) {
            @unlink($pharPath);
        }
    }

    private function pharExists(string $app): bool {
        $pharPath = $this->container->get(App::class)->getAppPharPath($app);
        return (bool) ($pharPath && file_exists($pharPath));
    }

    private function registeredStatus(string $app): int {
        return (int) (m('application')->where(['app' => $app])->value('status') ?: 0);
    }

    /**
     * 重建应用缓存(config/apps.php)并清理进程内静态已装列表
     */
    public function flushCaches(): void {
        $value = m('application')->pluck('status', 'app')->toArray();
        cache_write('apps', $value, BASE_PATH . '/config');
        $this->container->get('config')->set('apps', $value);
        RegistryService::flushDispatchCache();
        try {
            $this->container->get(App::class)->clearCache();
        } catch (\Throwable) {
            // 清理失败不影响主流程
        }
    }
}
