<?php

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use App\admin\Service\ApplicationService;
use Exception;
use xphp\Framework\AppInstall;

/**
 * APP应用管理
 */

class Application extends Authorization {


    /**
     * 查询安装状态
     */
    public function getInstallStatus() {
        $app = input('app');
        show_json([
            'code' => 200,
            'data' => [
                'installed' => app('?' . $app, true),
            ],
        ]);
    }



    /**
     * 获取任务类型目录
     */
    public function lists() {

        // 获取当前版本的远程应用插件
        $cloudList = app('admin.application')->cloudlist();
        // 本地应用列表
        $localList = app('admin.application')->locallist();

        // 合并
        $data = array_merge($cloudList ?: [], $localList ?: []);
        foreach ($data as $app => &$value) {
            // 使用线上新ICON
            $value['icon'] = !empty($cloudList[$value['app']]['icon']) ? $cloudList[$value['app']]['icon'] : ($value['icon'] ?? '');
            $value['installed'] = app('?' . $value['app'], true);
        }

        show_json([
            'code' => 200,
            'data' => array_values($this->container->get('array')->orderby($data, 'installed', SORT_DESC, 'istop', SORT_DESC, 'app', SORT_DESC)),
        ]);
    }

    /**
     * 清理缓存
     */
    public function cleanCache() {
        $this->cache->delete('AdminApplicationCloudList');
        app('admin.application')->cache();
        show_json([
            'code' => 200,
            'message' => '操作成功',
        ]);
    }

    /**
     * 已安装的应用列表
     */
    public function installedApplist() {

        // 本地应用列表
        $localList = app('admin.application')->locallist();

        $list = m('application')->order('timestamp desc')->get()->toArray();
        // 获取当前版本的远程应用插件
        $cloudList = app('admin.application')->cloudlist();

        $openModuleList = $this->config->get('app.open_module_list', []);
        foreach ($openModuleList as $value) {

            $config = app('admin.application')->config($value);
            if (empty($config)) {
                continue;
            }

            $list[] = [
                'app' => $value,
                'timestamp' => time(),
            ];
        }

        $list = array_column($list, null, 'app');
        foreach ($list as $value) {
            $value['disabled'] = in_array($value['app'], $openModuleList);
            // 远程可升级版本
            if (isset($cloudList[$value['app']]) && isset($localList[$value['app']])) {
                if (version_compare($cloudList[$value['app']]['version'], $localList[$value['app']]['version'], '>')) {
                    $value['upgrade'] = 1;
                    $value['upgradeversion'] = $cloudList[$value['app']]['version'];
                }
            }

            // 数据库注册信息(更新后即为最新版本)优先于本地包信息:
            // 多 Worker 下未执行更新的进程仍持有旧的 phar 解压缓存, 本地信息可能滞后
            $info = array_merge($localList[$value['app']] ?? ['isdelete' => 1], $value, [
                'status' => isset($value['status']) && $value['status'] == 1 ? true : false,
                'installed' => 1,
            ]);

            // 后台管理接入配置(存量行缺列时给默认值)
            if (!array_key_exists('admin_enable', $info)) {
                $info['admin_enable'] = 1;
            }
            $info['admin_config'] = json_decode((string) ($info['admin_config'] ?? ''), true) ?: [];

            $info['icon'] = !empty($cloudList[$info['app']]['icon']) ? $cloudList[$info['app']]['icon'] : ($info['icon'] ?? '');
            $data[] = $info;
        }

        show_json([
            'code' => 200,
            'data' => $this->container->get('array')->orderby($data, 'isdelete', SORT_ASC, 'upgrade', SORT_DESC, 'id', SORT_DESC),
        ]);
    }

    /**
     * 清理phar文件缓存
     * @log
     */
    public function pharCleanCache() {
        $app = input('.app', '', 'trim');
        $this->pharCleanFiles($app);
        show_json([
            'code' => 200,
            'message' => 'success',
        ]);
    }

    /**
     * 清理文件
     */
    private function pharCleanFiles($app) {
        $pharPath = $this->container->get('app')->getAppPharPath($app);
        if (file_exists($pharPath)) {
            unlink($pharPath);
        }
        return true;
    }

    /**
     * 上传应用 phar 包
     * 以文件名为应用标识 (如 weixin.phar → weixin), 覆盖同名 phar 即为本地更新;
     * 校验包内 Install/config.php 后返回应用信息, 由前端继续走安装/更新向导
     * @log
     */
    public function upload() {

        $files = $this->request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (empty($file) || $file->getError() != UPLOAD_ERR_OK) {
            show_json([
                'code' => 201,
                'message' => '请选择要上传的应用包 (.phar)',
            ]);
        }

        $originName = (string) ($file->getClientFilename() ?? '');
        if (strtolower(pathinfo($originName, PATHINFO_EXTENSION)) !== 'phar') {
            show_json([
                'code' => 201,
                'message' => '仅支持 .phar 应用包, 当前文件: ' . $originName,
            ]);
        }

        $app = strtolower((string) preg_replace('/[^a-zA-Z0-9_]/', '', pathinfo($originName, PATHINFO_FILENAME)));
        if ($app === '') {
            show_json([
                'code' => 201,
                'message' => '文件名需为应用标识, 如 weixin.phar',
            ]);
        }
        $pharPath = $this->container->get('app')->getAppPharPath($app);
        $tmpPath = $pharPath . '.uploading';
        if (!is_dir(dirname($pharPath))) {
            mkdir(dirname($pharPath), 0755, true);
        }

        try {
            $file->moveTo($tmpPath);
            if (!is_file($tmpPath) || filesize($tmpPath) === 0) {
                throw new Exception('上传内容为空, 请重试');
            }

            // 读取包内应用配置校验包完整性 (IN.XPHP 已在运行环境定义)
            if (!file_exists('phar://' . $tmpPath . '/Install/config.php')) {
                throw new Exception('应用包缺少 Install/config.php, 不是有效的 X-PHP 应用');
            }
            $config = include 'phar://' . $tmpPath . '/Install/config.php';
            if (empty($config) || !is_array($config)) {
                throw new Exception('Install/config.php 内容无效');
            }

            // 校验通过后覆盖正式 phar (已存在同名应用即为更新)
            if (file_exists($pharPath)) {
                unlink($pharPath);
            }
            if (!rename($tmpPath, $pharPath)) {
                throw new Exception('应用包落盘失败, 请检查目录写入权限');
            }
            chmod($pharPath, 0755);
        } catch (Exception $e) {
            isJsonException($e);
            @unlink($tmpPath);
            show_json([
                'code' => 201,
                'message' => '应用包解析失败: ' . $e->getMessage(),
            ]);
        }

        // 清理框架 phar 解压缓存, 让新包内容立即生效
        $this->container->get('app')->getAppPath('__clear_cache__');

        $config = (array) $this->container->get(AppInstall::class)->getAppConfig($app);

        show_json([
            'code' => 200,
            'message' => '应用包上传成功',
            'data' => [
                'app' => $app,
                'name' => (string) ($config['name'] ?? $app),
                'description' => (string) ($config['description'] ?? ''),
                'version' => (string) ($config['version'] ?? ''),
                'icon' => (string) ($config['icon'] ?? ''),
                'installed' => app('?' . $app, true) ? 1 : 0,
            ],
        ]);
    }

    /**
     * 升级应用预检: 返回本地与云端版本对照
     * 未登录云账号时不阻断本地应用包更新 (upgradeversion 为空即本地更新), 仅在响应中附登录态供前端提示
     * @log
     */
    public function upgrade() {
        $app = input('.app', '', 'trim');

        $localList = app('admin.application')->locallist();
        if (empty($localList[$app])) {
            show_json([
                'code' => 201,
                'message' => '没有找到相关的应用',
            ]);
        }
        $version = (string) ($localList[$app]['version'] ?? '');

        $user = $this->container->get('cloud')->get('keyUsage');
        $loggedIn = !empty($user) && !empty($user['uid']);

        $cloudList = $loggedIn ? app('admin.application')->cloudlist() : [];
        $upgradeversion = (string) ($cloudList[$app]['version'] ?? '');

        // 云端无更高版本 (含「上传安装」覆盖本地 phar 后版本号一致的场景) 时不再阻断:
        // 返回空 upgradeversion, 前端按本地应用包执行更新 (刷新数据结构与注册信息)
        if ($upgradeversion !== '' && version_compare($upgradeversion, $version, '<=')) {
            $upgradeversion = '';
        }

        show_json([
            'code' => 200,
            'message' => '操作成功',
            'data' => [
                'app' => $app,
                'version' => $version,
                'upgradeversion' => $upgradeversion,
                'logged_in' => $loggedIn ? 1 : 0,
                'register' => $this->cloudRegisterUrl(),
            ],
        ]);
    }

    /**
     * 设备注册链接: 注册/登录后自动绑定本设备
     */
    private function cloudRegisterUrl(): string {
        try {
            $info = (array) $this->container->get('cloud')->get();
            return trim((string) ($info['crlDistributionPoints'] ?? 'https://cloud.x-php.com'), '/')
                . '/register?token=' . $this->container->get('cloud')->getDeviceId();
        } catch (\Throwable) {
            return 'https://cloud.x-php.com';
        }
    }

    /**
     * 使用流式输出更新应用
     * 云端刷新文件 → 幂等补建缺失数据表 → 重写注册信息与缓存 → 重启服务生效
     * @log
     */
    public function upgradeWithStream() {

        // 在方法开始时就设置响应头
        $response = response();

        $response->setHeader('Content-Type', 'text/event-stream;charset=utf-8');
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setHeader('Connection', 'keep-alive');
        $response->setHeader('Transfer-Encoding', 'chunked');
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'POST,GET');

        // 输出回调函数
        $outputCallback = function ($message, $type = 'info', $data = []) use ($response) {
            $data = [
                'type' => $type,
                'message' => $message,
                'timestamp' => time(),
                'data' => $data,
            ];
            $content = "data: " . json_encode($data) . "\n\n";
            $response->write($content);
        };

        $app = input('.app', '', 'trim');
        $db = input('.db', 'default', 'trim');

        if (empty($app)) {
            $outputCallback('应用名称错误', 'error', ['app' => $app]);
            return;
        }

        try {
            // 交给生命周期服务执行(内部复用 ApplicationService::update 输出)
            // 交给生命周期服务执行(内部复用 ApplicationService::update 输出)
            // 更新不自动重启, 由服务流输出手动重启提示
            $this->container->get(ApplicationService::class)->update(
                $app,
                $db ?: 'default',
                false,
                function ($message, $type = 'info') use ($outputCallback) {
                    $outputCallback((string) $message, (string) $type);
                }
            );
            // 发送更新成功消息
            $outputCallback('应用《' . $app . '》更新成功!', 'success', ['app' => $app]);
        } catch (Exception $e) {
            // 发送错误消息
            $outputCallback($e->getMessage(), 'error', ['app' => $app]);
        }
    }

    /**
     * 卸载应用
     * 默认保留数据表; 传入 drop_tables=1 时删除该应用声明的数据表
     * @log
     */
    public function uninstall() {
        $app = input('post.app', '', 'trim');
        $dropTables = input('post.drop_tables', 0, 'intval') == 1;

        $info = m('application')->where(['app' => $app])->one();

        try {
            $this->container->get(ApplicationService::class)->uninstall($app, $dropTables, 'default');
        } catch (Exception $e) {
            show_json([
                'code' => 201,
                'message' => $e->getMessage(),
            ]);
        }

        show_json([
            'code' => 200,
            'message' => '应用《' . ($info['name'] ?: $app) . '》成功卸载!',
        ]);
    }

    /**
     * 设置应用是否进入后台管理(admin_enable)
     * @log
     */
    public function enable() {
        $app = input('post.app', '', 'trim');
        $enable = input('post.enable', 1, 'intval');

        if ($this->isProtectedApp($app)) {
            show_json([
                'code' => 201,
                'message' => '系统应用不支持此操作',
            ]);
        }

        $model = m('application')->where(['app' => $app]);
        if (empty($model->one())) {
            show_json([
                'code' => 201,
                'message' => '没有找到相关的应用',
            ]);
        }

        try {
            $model->update(['admin_enable' => $enable ? 1 : 0]);
        } catch (\Throwable $e) {
            // 存量库未迁移列时的兜底提示
            show_json([
                'code' => 201,
                'message' => '保存失败: ' . $e->getMessage(),
            ]);
        }

        show_json([
            'code' => 200,
            'data' => ['admin_enable' => $enable ? 1 : 0],
        ]);
    }

    /**
     * 读取/保存应用的后台管理配置(admin_config)
     * JSON 内容: 菜单路径覆盖、表格勾选、排序等
     */
    public function adminConfig() {
        $app = input('.app', '', 'trim');

        $model = m('application')->where(['app' => $app]);
        $info = $model->one();
        if (empty($info)) {
            show_json([
                'code' => 201,
                'message' => '没有找到相关的应用',
            ]);
        }

        // 无提交内容时返回当前配置
        $submit = input('.config', null);
        if ($submit === null) {
            show_json([
                'code' => 200,
                'data' => json_decode((string) $info['admin_config'], true) ?: [],
            ]);
        }

        $config = is_string($submit) ? json_decode($submit, true) : $submit;
        if (!is_array($config)) {
            show_json([
                'code' => 201,
                'message' => '配置格式错误, 需为合法 JSON 对象',
            ]);
        }

        $model->update(['admin_config' => json_encode($config, JSON_UNESCAPED_UNICODE)]);

        show_json([
            'code' => 200,
            'message' => '操作成功',
            'data' => $config,
        ]);
    }

    private function isProtectedApp(string $app): bool {
        return in_array($app, (array) $this->config->get('app.open_module_list', []), true);
    }

    /**
     * 应用声明的数据表格清单(来自 Config/admin.php 的 tables 段与 admin_config 覆盖)
     * 供后台配置界面展示每个应用可在后台管理哪些表
     */
    public function tables() {
        $app = input('.app', '', 'trim');

        try {
            $tables = $this->container->get(\App\admin\Service\RegistryService::class)->tables($app);
        } catch (\Throwable $e) {
            show_json([
                'code' => 201,
                'message' => $e->getMessage(),
            ]);
        }

        show_json([
            'code' => 200,
            'data' => $tables,
        ]);
    }

    /**
     * 应用声明的菜单/桌面入口清单(声明 + admin_config 覆盖合并视图, 含隐藏条目)
     * 供后台配置界面逐条编辑标题/图标/显示/排序
     */
    public function menus() {
        $app = input('.app', '', 'trim');

        try {
            $entries = $this->container->get(\App\admin\Service\RegistryService::class)->appEntries($app);
        } catch (\Throwable $e) {
            show_json([
                'code' => 201,
                'message' => $e->getMessage(),
            ]);
        }

        show_json([
            'code' => 200,
            'data' => $entries,
        ]);
    }

    /**
     * 更改应用状态
     * @log
     */
    public function status() {

        $app = input('post.app', '', 'trim');
        $status = input('post.status', 0, 'intval');

        try {
            $this->container->get(ApplicationService::class)->setStatus($app, $status);
        } catch (Exception $e) {
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
     * 安装应用
     * @log
     * @return [type] [description]
     */
    public function install() {

        $app = input('.app', '', 'trim');
        $db = input('.db', ''); // 数据库

        if (empty($app)) {
            show_json([
                'code' => 201,
                'message' => '应用名称不能为空',
            ]);
        }

        $model = db('application')->where(['app' => $app]);
        $info = $model->one();
        if ($info) {
            show_json([
                'code' => 201,
                'message' => '应用已安装, 如需重新安装请先卸载后操作',
            ]);
        }
        // 判断是否本地应用
        $appPath = $this->container->get('app')->getAppPath($app, 'Install');
        // 文件是否存在
        if (!file_exists($appPath)) {
            // 本地不存在: 云端下载需要开发者账号 (本地上传 phar 的应用不走此分支)
            $user = $this->container->get('cloud')->get('keyUsage');
            if (empty($user) || empty($user['uid'])) {
                show_json([
                    'code' => 205,
                    'message' => '安装云端应用前须先登录 X-PHP 开发者账号; 本地应用包可直接通过「上传安装」部署',
                    'data' => [
                        'url' => '/maintenance',
                        'register' => $this->cloudRegisterUrl(),
                    ],
                ]);
            }

            try {
                $downloadUrl = $this->container->get('cloud')->getDownloadAppUrl($app);
                if (empty($downloadUrl)) {
                    throw new Exception('应用下载地址获取失败');
                }
                $this->container->get('cloud')->downloadFile($downloadUrl, $this->container->get('app')->getAppPharPath($app));
            } catch (Exception $e) {
                isJsonException($e);
                show_json([
                    'code' => 201,
                    'message' => '云端下载失败: ' . $e->getMessage() . ' ; 也可通过「上传安装」部署本地应用包',
                ]);
            }
        }

        try {

            // 获取应用配置
            $config = $this->container->get(AppInstall::class)->getAppConfig($app);
            if (empty($app) || empty($config)) {
                throw new Exception('无法获取应用配置');
            }

            // 检测是否多个库
            $databases = $this->container->get('config')->get('databases');
            if (count($databases) > 1 && empty($db)) {
                show_json([
                    'code' => 202,
                    'message' => '请选择安装到哪个数据库',
                    'data' => [
                        'databases' => array_keys($databases),
                    ]
                ]);
            }


            if (isset($config['require'])) {
                $checkrequire = app('admin.application')->checkrequire($config['require'] ?? []);
            }

            show_json([
                'code' => 200,
                'message' => '进入安装步骤, 请等待...',
                'data' => [
                    'app' => $app,
                    'token' => encode([
                        'app' => $app,
                        'db' => $db,
                    ]),
                ]
            ]);
        } catch (Exception $e) {
            isJsonException($e);
            show_json([
                'code' => 201,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 使用流式输出安装应用
     * @param string $app 应用名称
     * @return void
     */
    public function installWithStream() {

        // 在方法开始时就设置响应头
        $response = response();

        $response->setHeader('Content-Type', 'text/event-stream;charset=utf-8');
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setHeader('Connection', 'keep-alive');
        $response->setHeader('Transfer-Encoding', 'chunked');
        $response->setHeader('Access-Control-Allow-Origin', '*');
        $response->setHeader('Access-Control-Allow-Methods', 'POST,GET');

        // 输出回调函数
        $outputCallback = function ($message, $type = 'info', $data = []) use ($response) {
            $data = [
                'type' => $type,
                'message' => $message,
                'timestamp' => time(),
                'data' => $data,
            ];
            $content = "data: " . json_encode($data) . "\n\n";
            $response->write($content);
        };

        $app = input('.app', '', 'trim');
        $token = input('.token', '', 'decode');

        if (empty($app) || empty($token) || $token['app'] != $app) {
            $outputCallback('应用名称或token错误', 'error', ['app' => $app, 'token' => $token]);
            return;
        }

        try {
            // 发送开始安装消息
            $outputCallback("准备安装应用: {$app}, 检查配置文件", 'info', ['app' => $app]);
            // 文件是否存在
            $config = $this->container->get(AppInstall::class)->getAppConfig($app);
            if (empty($app) || empty($config)) {
                throw new Exception('无法获取应用配置');
            }

            // 交给生命周期服务执行(内部复用 AppInstall 命令模式输出)
            // 安装不重启主服务; 新装独立应用由服务层直接经 xphp daemon start 拉起实例
            $this->container->get(ApplicationService::class)->install(
                $app,
                $token['db'] ?: 'default',
                false,
                function ($message, $type = 'info') use ($outputCallback) {
                    $outputCallback((string) $message, (string) $type);
                }
            );
            // 发送安装成功消息
            $outputCallback('应用《' . ($config['name'] ?: $app) . '》安装成功!', 'success', ['app' => $app]);
        } catch (Exception $e) {
            // 发送错误消息
            $outputCallback($e->getMessage(), 'error', ['app' => $app]);
        }
    }
}
