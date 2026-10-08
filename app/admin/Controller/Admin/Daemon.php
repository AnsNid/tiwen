<?php

declare(strict_types=1);

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use Swoole\Server as SwooleServer;
use xphp\Context\ApplicationContext;
use xphp\Framework\Server;

/**
 * 服务运行与守护管理
 * 对接 xphp 启动器(Install/install.sh)的 daemon 子命令:
 * - 运行方式识别: 守护启动时环境变量 XPHP_LAUNCHER=xphp, 独立运行为 php index.php
 * - 守护实例状态: runtime/daemon/<实例名>/ 下的 pid/command/log 等纯文件
 * - 开机自启: xphp daemon boot enable/disable (systemd/launchd/OpenRC/cron 自适应)
 * - 完整重启: 守护模式交由 daemon restart(含端口释放等待), 独立模式由分离的守望进程拉起新实例
 */
class Daemon extends Authorization {
    /** xphp 工具路径进程级缓存 */
    private static ?string $toolPath = null;

    /**
     * 运行状态总览: 运行方式 / 服务器进程 / 守护实例 / 开机自启
     */
    public function status() {

        $masterPid = $this->masterPid();
        $instances = $this->instances();

        // 当前实例 = daemon.child 记录的服务进程即本机 master
        $current = '';
        foreach ($instances as $instance) {
            if (!empty($instance['child_pid']) && (int) $instance['child_pid'] === $masterPid) {
                $current = (string) $instance['name'];
                break;
            }
        }

        $tool = self::findTool();

        show_json([
            'code' => 200,
            'data' => [
                'launcher' => getenv('XPHP_LAUNCHER') === 'xphp' ? 'xphp' : 'standalone',
                'managed' => getenv('XPHP_LAUNCHER') === 'xphp',
                'tool' => [
                    'path' => $tool,
                    'found' => $tool !== '',
                ],
                'server' => $this->serverInfo(),
                'current_instance' => $current,
                'instances' => $instances,
                'boot' => $this->bootStatus($tool),
            ],
        ]);
    }

    /**
     * 完整重启服务
     * target: all=全部守护实例 / 实例名 / 空=当前实例(守护)或本机(独立)
     */
    public function restart() {

        $target = trim((string) input('post.target', ''));
        $clear = input('post.clear_container', 0, 'intval') == 1;
        $managed = getenv('XPHP_LAUNCHER') === 'xphp';
        $tool = self::findTool();

        if (PHP_OS_FAMILY === 'Windows') {
            show_json(['code' => 201, 'message' => '仅支持 Unix/Linux/macOS 环境的远程重启']);
            return;
        }

        // 守护模式: 交给 xphp daemon restart, 其内部完成端口释放等待/孤儿清理/退避拉起
        if ($managed && $tool !== '') {

            if ($target === '' || $target === 'current') {
                $target = $this->currentInstance() ?: 'main';
            }

            if ($target !== 'all' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/', $target)) {
                show_json(['code' => 201, 'message' => '无效的实例名']);
                return;
            }
            if ($target !== 'all' && !is_dir(runtime_path('daemon') . $target)) {
                show_json(['code' => 201, 'message' => "守护实例 {$target} 不存在"]);
                return;
            }

            $inner = 'sleep 1; exec ' . escapeshellarg($tool)
                . ' -d ' . escapeshellarg(BASE_PATH)
                . ' daemon restart ' . escapeshellarg($target)
                . ($clear ? ' --clear-container' : '');
            if (!$this->dispatchRestart($inner)) {
                show_json(['code' => 201, 'message' => '重启任务启动失败，请检查 runtime/logs/daemon-restart.log 及其写入权限']);
                return;
            }

            show_json([
                'code' => 200,
                'message' => '重启指令已发出, 服务将在数秒内恢复',
                'data' => ['mode' => 'daemon', 'target' => $target],
            ]);
            return;
        }

        // 独立模式: 无守护进程兜底, 由分离的守望进程等当前 master 退出后拉起新实例
        $master = $this->masterPid();
        if ($master <= 0) {
            show_json(['code' => 201, 'message' => '未找到服务主进程, 无法执行重启']);
            return;
        }

        $phpBinary = PHP_BINARY ?: 'php';
        $inner = 'sleep 1'
            . '; kill -TERM ' . $master . ' 2>/dev/null'
            . '; i=0; while kill -0 ' . $master . ' 2>/dev/null; do'
            . ' if [ "$i" -ge 30 ]; then printf "%s\\n" "等待原服务退出超时，重启已中止" >&2; exit 1; fi'
            . '; i=$(( i + 1 )); sleep 1; done'
            . ($clear ? '; rm -rf ' . escapeshellarg(runtime_path('container')) : '')
            . '; cd ' . escapeshellarg(BASE_PATH)
            . ' && exec ' . escapeshellarg($phpBinary) . ' index.php start';
        if (!$this->dispatchRestart($inner)) {
            show_json(['code' => 201, 'message' => '重启任务启动失败，请检查 runtime/logs/daemon-restart.log 及其写入权限']);
            return;
        }

        show_json([
            'code' => 200,
            'message' => '重启指令已发出, 服务将以独立后台进程方式恢复',
            'data' => ['mode' => 'standalone', 'target' => ''],
        ]);
    }

    /**
     * 平滑重载 worker (SIGUSR1, 不中断服务); 代码级变更仍需完整重启才彻底生效
     */
    public function reload() {
        Server::restart();
        show_json([
            'code' => 200,
            'message' => '平滑重载信号已发送',
        ]);
    }

    /**
     * 启动守护实例 (target: 实例名或 all=all 恢复全部已配置实例)
     */
    public function start() {

        $target = trim((string) input('post.target', 'main'));
        $tool = self::findTool();

        if ($tool === '') {
            show_json(['code' => 201, 'message' => '未找到 xphp 工具, 请在服务器终端操作 (xphp daemon start)']);
            return;
        }
        if ($target !== 'all' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/', $target)) {
            show_json(['code' => 201, 'message' => '无效的实例名']);
            return;
        }
        if ($target !== 'all' && !is_dir(runtime_path('daemon') . $target)) {
            show_json(['code' => 201, 'message' => "守护实例 {$target} 不存在, 新实例请在终端执行: xphp daemon start {$target}"]);
            return;
        }

        $inner = 'exec ' . escapeshellarg($tool) . ' -d ' . escapeshellarg(BASE_PATH)
            . ' daemon ' . ($target === 'all' ? 'update' : 'start ' . escapeshellarg($target));
        exec('nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');

        show_json([
            'code' => 200,
            'message' => $target === 'all' ? '正在恢复全部已配置实例' : "正在启动守护实例 {$target}",
        ]);
    }

    /**
     * 停止守护实例 (target: 实例名或 all); 停止当前实例会导致管理后台失联
     */
    public function stop() {

        $target = trim((string) input('post.target', 'main'));
        $tool = self::findTool();

        if ($tool === '') {
            show_json(['code' => 201, 'message' => '未找到 xphp 工具, 请在服务器终端操作 (xphp daemon stop)']);
            return;
        }
        if ($target !== 'all' && !preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/', $target)) {
            show_json(['code' => 201, 'message' => '无效的实例名']);
            return;
        }
        if ($target !== 'all' && !is_dir(runtime_path('daemon') . $target)) {
            show_json(['code' => 201, 'message' => "守护实例 {$target} 不存在"]);
            return;
        }

        $inner = 'exec ' . escapeshellarg($tool) . ' -d ' . escapeshellarg(BASE_PATH)
            . ' daemon stop ' . escapeshellarg($target);
        exec('nohup sh -c ' . escapeshellarg($inner) . ' >/dev/null 2>&1 &');

        $self = $target === 'all' || $target === $this->currentInstance();
        show_json([
            'code' => 200,
            'message' => $self ? "停止指令已发出, 当前管理后台所在实例将下线" : "正在停止守护实例 {$target}",
            'data' => ['self' => $self],
        ]);
    }

    /**
     * 守护实例运行日志尾部 (runtime/daemon/<name>/daemon.log)
     */
    public function log() {

        $name = (string) input('name', '', 'trim');
        $lines = min(500, max(10, (int) input('lines', 200, 'intval')));

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/', $name)) {
            show_json(['code' => 201, 'message' => '无效的实例名']);
            return;
        }

        $file = runtime_path('daemon') . $name . '/daemon.log';
        if (!is_file($file)) {
            show_json([
                'code' => 200,
                'data' => ['name' => $name, 'file' => $file, 'log' => ''],
                'message' => '暂无日志文件',
            ]);
            return;
        }

        show_json([
            'code' => 200,
            'data' => [
                'name' => $name,
                'file' => $file,
                'log' => $this->tailFile($file, $lines),
            ],
        ]);
    }

    /**
     * 开机自启 (xphp daemon boot enable/disable)
     */
    public function boot() {

        $enable = input('post.enable', 1, 'intval') == 1;
        $tool = self::findTool();

        if ($tool === '') {
            show_json([
                'code' => 201,
                'message' => '未找到 xphp 工具, 请先在服务器终端安装 (安装脚本会自动注册 xphp 命令), 或手动执行: xphp -d ' . BASE_PATH . ' daemon boot enable',
            ]);
            return;
        }

        $action = $enable ? 'enable' : 'disable';
        $output = [];
        $rc = 0;
        @exec(escapeshellarg($tool) . ' -d ' . escapeshellarg(BASE_PATH) . ' daemon boot ' . $action . ' 2>&1', $output, $rc);

        $tail = implode("\n", array_slice($output, -8));
        if ($rc !== 0) {
            show_json([
                'code' => 201,
                'message' => ($enable ? '开启' : '关闭') . '开机自启失败: ' . ($tail !== '' ? $tail : '退出码 ' . $rc),
            ]);
            return;
        }

        show_json([
            'code' => 200,
            'message' => $enable ? '开机自启已开启' : '开机自启已关闭',
            'data' => ['output' => $tail],
        ]);
    }

    private function dispatchRestart(string $command): bool {
        // 必须在全新 CLI 中创建独立会话，nohup 无法躲开旧服务的进程组停止信号。
        $bootstrap = 'if (!function_exists("passthru")) { fwrite(STDERR, "passthru 不可用，无法执行重启命令" . PHP_EOL); exit(1); }'
            . ' if (!\Swoole\Process::daemon(true, true)) { exit(1); }'
            . ' passthru($argv[1], $code); exit($code);';
        $output = [];
        $code = 0;
        exec(
            escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($bootstrap)
            . ' -- ' . escapeshellarg($command)
            . ' </dev/null >> ' . escapeshellarg(runtime_path('logs') . 'daemon-restart.log') . ' 2>&1',
            $output,
            $code
        );
        return $code === 0;
    }

    /**
     * 服务器进程信息 (master/manager/worker)
     */
    private function serverInfo(): array {

        $master = 0;
        $manager = 0;
        $workerNum = 0;

        try {
            foreach (\xphp\Server\ServerManager::list() as $server) {
                if (is_array($server) && isset($server[1]) && $server[1] instanceof SwooleServer) {
                    $master = (int) $server[1]->getMasterPid();
                    $manager = (int) $server[1]->getManagerPid();
                    $workerNum = (int) ($server[1]->setting['worker_num'] ?? 0);
                    break;
                }
            }
        } catch (\Throwable) {
        }

        if ($master <= 0) {
            $pidFile = (string) $this->config->get('server.settings.pid_file', runtime_path() . 'xphp.pid');
            if (is_file($pidFile)) {
                $master = (int) trim((string) file_get_contents($pidFile));
                if (!$this->pidAlive($master)) {
                    $master = 0;
                }
            }
        }

        return [
            'master_pid' => $master,
            'manager_pid' => $manager,
            'worker_num' => $workerNum,
        ];
    }

    /**
     * 当前 master 进程 PID
     */
    private function masterPid(): int {
        return (int) ($this->serverInfo()['master_pid'] ?? 0);
    }

    /**
     * 当前服务所属的守护实例名 (daemon.child === master pid)
     */
    private function currentInstance(): string {

        $master = $this->masterPid();
        if ($master <= 0) {
            return '';
        }

        foreach ($this->instances() as $instance) {
            if (!empty($instance['child_pid']) && (int) $instance['child_pid'] === $master) {
                return (string) $instance['name'];
            }
        }
        return '';
    }

    /**
     * 全部守护实例状态 (与 xphp daemon list 的健康度规则一致)
     */
    private function instances(): array {

        $base = runtime_path('daemon');
        if (!is_dir($base)) {
            return [];
        }

        $list = [];
        foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]*$/', $name)) {
                continue;
            }

            $pid = $this->readIntFile($dir . '/daemon.pid');
            $child = $this->readIntFile($dir . '/daemon.child');
            $supervisorAlive = $pid > 0 && $this->pidAlive($pid) && $this->isDaemonLoop($pid);
            $childAlive = $child > 0 && $this->pidAlive($child);
            $gaveup = is_file($dir . '/daemon.gaveup') ? trim((string) file_get_contents($dir . '/daemon.gaveup')) : '';

            $health = 'stopped';
            if ($supervisorAlive) {
                $health = $childAlive ? 'online' : 'waiting';
            } elseif ($gaveup !== '') {
                $health = 'failed';
            }

            $list[] = [
                'name' => $name,
                'health' => $health,
                'pid' => $supervisorAlive ? $pid : 0,
                'child_pid' => $childAlive ? $child : 0,
                'restarts' => $this->readIntFile($dir . '/restarts'),
                'started' => is_file($dir . '/started') ? trim((string) file_get_contents($dir . '/started')) : '',
                'command' => $this->readCommand($dir . '/command'),
                'ports' => $this->readPorts($dir . '/daemon.ports'),
                'gaveup' => $gaveup !== '' ? $this->exitReason($gaveup) : '',
            ];
        }

        return $list;
    }

    /**
     * 开机自启状态 (镜像 xphp daemon boot status 的检测规则)
     */
    private function bootStatus(string $tool): array {

        $init = $this->detectInit();
        $installed = false;
        $enabled = false;

        switch ($init) {
            case 'systemd':
                $installed = is_file('/etc/systemd/system/xphp.service');
                if ($installed) {
                    $enabled = trim((string) @exec('systemctl is-enabled xphp.service 2>/dev/null')) === 'enabled';
                }
                break;

            case 'launchd':
                $plist = ($this->isRoot() ? '/Library/LaunchDaemons/' : (string) (getenv('HOME') ?: '') . '/Library/LaunchAgents/')
                    . 'com.xphp.daemon.plist';
                $installed = $plist !== '' && is_file($plist);
                $enabled = $installed;
                break;

            case 'openrc':
                $installed = is_file('/etc/local.d/xphp-daemon.start');
                $enabled = $installed;
                break;

            case 'cron':
                $output = [];
                @exec('crontab -l 2>/dev/null', $output);
                $haystack = implode("\n", $output) . "\n" . (string) @file_get_contents('/etc/crontab');
                $installed = str_contains($haystack, '--boot-recover');
                $enabled = $installed;
                break;
        }

        // 注册表: <xphp源目录>/boot/projects.list
        $registered = [];
        if ($tool !== '') {
            $source = realpath(dirname($tool));
            if ($source !== false && is_file($source . '/boot/projects.list')) {
                foreach (file($source . '/boot/projects.list', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                    $line = trim($line);
                    if ($line !== '') {
                        $registered[] = $line;
                    }
                }
            }
        }

        $self = realpath(BASE_PATH) ?: BASE_PATH;

        return [
            'init' => $init,
            'installed' => $installed,
            'enabled' => $enabled,
            'registered' => $registered,
            'this_registered' => in_array($self, $registered, true),
            'tool_found' => $tool !== '',
        ];
    }

    /**
     * 检测 init 方案 (systemd/launchd/openrc/cron), 与工具脚本规则一致
     */
    private function detectInit(): string {

        if (PHP_OS_FAMILY === 'Darwin') {
            return 'launchd';
        }
        if (is_dir('/run/systemd/system')) {
            return 'systemd';
        }
        if ((string) @shell_exec('command -v rc-update 2>/dev/null') !== '') {
            return 'openrc';
        }
        if ((string) @shell_exec('command -v crontab 2>/dev/null') !== '' || is_file('/etc/crontab')) {
            return 'cron';
        }
        return '';
    }

    /**
     * 定位 xphp 工具: PATH 查找优先, 再扫描安装脚本的候选源目录
     */
    public static function findTool(): string {

        if (self::$toolPath !== null) {
            return self::$toolPath;
        }

        $candidates = [];

        $output = [];
        @exec('command -v xphp 2>/dev/null', $output);
        foreach ($output as $line) {
            $candidates[] = trim($line);
        }

        $home = (string) (getenv('HOME') ?: '');
        foreach ([
            '/opt/x-php/xphp',
            '/usr/local/x-php/xphp',
            '/var/x-php/xphp',
            $home . '/Library/Application Support/x-php/xphp',
            $home . '/x-php/xphp',
            (string) sys_get_temp_dir() . '/x-php/xphp',
        ] as $path) {
            $candidates[] = $path;
        }

        foreach ($candidates as $path) {
            $path = $path !== '' ? realpath($path) : false;
            if ($path !== false && is_file($path) && is_executable($path)) {
                self::$toolPath = $path;
                return $path;
            }
        }

        self::$toolPath = '';
        return '';
    }

    private function readIntFile(string $file): int {
        if (!is_file($file)) {
            return 0;
        }
        $value = trim((string) file_get_contents($file));
        return preg_match('/^\d+$/', $value) ? (int) $value : 0;
    }

    private function readCommand(string $file): string {
        if (!is_file($file)) {
            return '';
        }
        $parts = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $parts[] = trim($line);
        }
        return implode(' ', $parts);
    }

    private function readPorts(string $file): array {
        if (!is_file($file)) {
            return [];
        }
        $ports = [];
        foreach (preg_split('/\s+/', trim((string) file_get_contents($file))) ?: [] as $port) {
            if (preg_match('/^\d+$/', $port)) {
                $ports[] = (int) $port;
            }
        }
        return $ports;
    }

    /**
     * 进程存活: posix 优先, Linux 回退 /proc, 再回退 kill -0
     */
    private function pidAlive(int $pid): bool {

        if ($pid <= 0) {
            return false;
        }
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        if (is_dir('/proc')) {
            return is_file('/proc/' . $pid);
        }
        $rc = 1;
        @exec('kill -0 ' . $pid . ' 2>/dev/null', $output, $rc);
        return $rc === 0;
    }

    /**
     * 确认 pid 属于 xphp 守护循环 (防 PID 复用误判); 非 Linux 取不到 cmdline 时按存活处理
     */
    private function isDaemonLoop(int $pid): bool {
        $cmdline = @file_get_contents('/proc/' . $pid . '/cmdline');
        if ($cmdline === false) {
            return true;
        }
        return str_contains($cmdline, 'daemon-loop');
    }

    private function isRoot(): bool {
        return function_exists('posix_getuid') ? posix_getuid() === 0 : false;
    }

    /**
     * 服务退出码 → 中文原因 (与工具脚本 daemon_exit_reason 一致)
     */
    private function exitReason(string $code): string {
        return match ($code) {
            '98' => '端口被占用 (Address already in use)',
            '127' => '命令不存在 (command not found)',
            '' => '服务异常退出',
            default => '服务异常退出 (code ' . $code . ')',
        };
    }

    /**
     * 读文件尾部 N 行
     */
    private function tailFile(string $file, int $lines): string {

        $size = (int) @filesize($file);
        if ($size <= 0) {
            return '';
        }

        $handle = @fopen($file, 'rb');
        if (!$handle) {
            return '';
        }

        // 最多回读 256KB 足够覆盖 500 行日志
        $length = min($size, 262144);
        fseek($handle, -$length, SEEK_END);
        $content = (string) fread($handle, $length);
        fclose($handle);

        $rows = preg_split('/\r?\n/', trim($content)) ?: [];
        return implode("\n", array_slice($rows, -$lines));
    }
}
