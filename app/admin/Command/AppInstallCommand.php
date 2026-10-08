<?php

declare(strict_types=1);

namespace App\admin\Command;

use App\admin\Service\ApplicationService;
use Exception;
use xphp\Command\Command;
use xphp\Framework\AppInstall;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

/**
 * 应用安装命令
 */
class AppInstallCommand extends Command {

    /**
     * 构造函数
     */
    public function __construct(protected ContainerInterface $container) {
        parent::__construct('app');
        $this->setDescription('应用管理命令（安装、卸载、更新）');
        $this->addArgument('app', InputArgument::REQUIRED, '应用名称');
        $this->addArgument('action', InputArgument::OPTIONAL, '操作类型：install/i（安装，默认）、uninstall/u（卸载）、update/up（更新）', 'install');
        $this->addOption('database', 'd', InputOption::VALUE_REQUIRED, '目标数据库连接名');
        $this->addOption('with-tables', 't', InputOption::VALUE_NONE, '卸载时同时删除该应用声明的数据表');
        $this->addOption('restart', 'r', InputOption::VALUE_NONE, '操作后是否重启服务');
    }

    /**
     * 执行命令
     */
    public function handle(): int {
        $app = trim((string) $this->input->getArgument('app'));
        $action = $this->normalizeAction((string) $this->input->getArgument('action'));
        $restart = (bool) $this->input->getOption('restart');

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $app)) {
            $this->line('应用名称只能包含字母、数字、连字符和下划线，且必须以字母开头', 'error');
            return 1;
        }

        if ($action === null) {
            $this->line("无效的操作类型，支持的操作：install/i（安装）、uninstall/u（卸载）、update/up（更新）", 'error');
            return 1;
        }

        $installer = $this->container->get(AppInstall::class);
        $service = $this->container->get(ApplicationService::class);

        // 命令行输出回调, 与 HTTP SSE 安装共用同一实现
        $output = function ($message, $type = 'info') {
            $this->line($message, $type === 'restart' ? 'info' : $type);
        };

        return match ($action) {
            'install' => $this->handleInstall($app, $restart, $installer),
            'uninstall' => $this->handleUninstall($app, $service, $output),
            'update' => $this->handleUpdate($app, $restart, $service, $output),
        };
    }

    /**
     * 标准化操作类型，支持简写。
     */
    private function normalizeAction(string $action): ?string {
        return match (strtolower(trim($action))) {
            'install', 'i' => 'install',
            'uninstall', 'u' => 'uninstall',
            'update', 'up' => 'update',
            default => null,
        };
    }

    /**
     * 获取安装使用的数据库连接名。
     */
    private function resolveDatabase(): ?string {
        $databases = $this->container->get('config')->get('databases');
        if (!is_array($databases) || $databases === []) {
            $this->line('未配置可用数据库连接', 'error');
            return null;
        }

        $database = $this->input->getOption('database');
        if ($database !== null) {
            $database = trim((string) $database);
            if (!isset($databases[$database])) {
                $this->line("数据库 {$database} 不存在", 'error');
                return null;
            }
            return $database;
        }

        $default = array_key_exists('default', $databases) ? 'default' : (string) array_key_first($databases);
        if (count($databases) === 1 || !$this->input->isInteractive()) {
            return $default;
        }

        $database = $this->ask('请输入操作到哪个数据库(' . implode(', ', array_keys($databases)) . ')', $default);
        if (!is_string($database) || !isset($databases[$database])) {
            $this->line("数据库 {$database} 不存在", 'error');
            return null;
        }

        return $database;
    }

    /**
     * 处理安装操作
     */
    private function handleInstall(string $app, bool $restart, AppInstall $installer): int {
        $appsFile = BASE_PATH . '/config/apps.php';

        $apps = [];
        if (is_file($appsFile)) {
            $apps = require $appsFile;
        }

        if (is_array($apps) && isset($apps[$app]) && $apps[$app] == 1) {
            $this->line("应用 {$app} 已安装", 'info');
            return 1;
        }

        $database = $this->resolveDatabase();
        if ($database === null) {
            return 1;
        }

        try {
            $installed = (bool) $this->container->get(ApplicationService::class)->install($app, $database, $restart, function ($message, $type) {
                $this->line($message, $type);
            });
        } catch (Exception $e) {
            $this->line($e->getMessage(), 'error');
            return 1;
        }

        if ($installed) {
            return 0;
        }
        $this->line("应用 {$app} 安装失败", 'error');
        return 1;
    }

    /**
     * 处理卸载操作
     * 默认保留数据表, --with-tables 时删除该应用声明的数据表
     */
    private function handleUninstall(string $app, ApplicationService $service, \Closure $output): int {
        if (!app('?' . $app, true)) {
            $this->line("应用 {$app} 未安装", 'info');
            return 1;
        }

        $dropTables = (bool) $this->input->getOption('with-tables');

        $database = $this->resolveDatabase();
        if ($database === null) {
            return 1;
        }

        try {
            $service->uninstall($app, $dropTables, $database, $output);
        } catch (Exception $e) {
            $this->line($e->getMessage(), 'error');
            return 1;
        }
        return 0;
    }

    /**
     * 处理更新操作
     * 刷新文件与注册信息, 幂等补建缺失的数据表
     */
    private function handleUpdate(string $app, bool $restart, ApplicationService $service, \Closure $output): int {
        if (!app('?' . $app, true)) {
            $this->line("应用 {$app} 未安装，无法更新", 'error');
            return 1;
        }

        $database = $this->resolveDatabase();
        if ($database === null) {
            return 1;
        }

        try {
            $service->update($app, $database, $restart, $output);
        } catch (Exception $e) {
            $this->line($e->getMessage(), 'error');
            return 1;
        }
        return 0;
    }
}
