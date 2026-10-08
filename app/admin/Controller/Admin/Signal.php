<?php

declare(strict_types=1);

namespace App\admin\Controller\Admin;

use App\admin\Authorization;
use xphp\Framework\Server;
use xphp\Context\ApplicationContext;
use Swoole\Server as SwooleServer;

/**
 * 信号管理器, 重启等
 */

class Signal extends Authorization {


    /**
     * 获取当前服务器进程列表
     */
    public function getProcessList() {
        $result = [];

        // 获取主进程ID
        $masterPid = ApplicationContext::getContainer()->get(SwooleServer::class)->getMasterPid();
        if ($masterPid > 0) {
            $result[] = [
                'pid' => $masterPid,
                'type' => 'Master',
                'status' => 'Running',
            ];

            // 获取管理进程ID
            $managerPid = ApplicationContext::getContainer()->get(SwooleServer::class)->getManagerPid();
            if ($managerPid > 0) {
                $result[] = [
                    'pid' => $managerPid,
                    'type' => 'Manager',
                    'status' => 'Running',
                ];
            }

            // 获取工作进程ID列表
            $server = ApplicationContext::getContainer()->get(SwooleServer::class);
            $workerNum = $server->setting['worker_num'] ?? 0;

            // 手动获取所有worker进程的PID
            for ($workerId = 0; $workerId < $workerNum; $workerId++) {
                $pid = $server->getWorkerPid($workerId);
                if ($pid > 0) {
                    $result[] = [
                        'pid' => $pid,
                        'type' => 'Worker',
                        'worker_id' => $workerId,
                        'status' => 'Running',
                    ];
                }
            }
        }

        return $this->response->json([
            'code' => 200,
            'message' => '获取进程列表成功',
            'data' => $result,
        ]);
    }

    /**
     * 运行时真实指标: CPU 负载 / 内存占用 / 活跃协程 / 活跃连接 / 运行时长
     */
    public function stats() {

        $server = ApplicationContext::getContainer()->get(SwooleServer::class);

        // Swoole 运行时统计
        $coroutineStats = [];
        $serverStats = [];
        try {
            if (class_exists('\Swoole\Coroutine')) {
                $coroutineStats = (array) \Swoole\Coroutine::stats();
            }
            $serverStats = (array) $server->stats();
        } catch (\Throwable) {
            // 非 Swoole 环境(如 CLI 调试)时保持为空
        }

        $setting = (array) ($server->setting ?? []);
        [$cpuUsage, $cores] = $this->cpuUsage();
        $memory = $this->memoryUsage();

        $startTime = (int) ($serverStats['start_time'] ?? 0);

        // 磁盘: 项目所在分区 (PHP 原生, 跨平台)
        $disk = ['total_gb' => 0.0, 'used_gb' => 0.0, 'free_gb' => 0.0, 'usage' => 0.0];
        $diskTotal = @disk_total_space(BASE_PATH);
        $diskFree = @disk_free_space(BASE_PATH);
        if (is_float($diskTotal) && $diskTotal > 0) {
            $free = is_float($diskFree) ? max(0.0, $diskFree) : 0.0;
            $used = max(0.0, $diskTotal - $free);
            $disk = [
                'total_gb' => round($diskTotal / 1073741824, 1),
                'used_gb' => round($used / 1073741824, 1),
                'free_gb' => round($free / 1073741824, 1),
                'usage' => round($used / $diskTotal * 100, 1),
            ];
        }

        return $this->response->json([
            'code' => 200,
            'data' => [
                'cpu' => [
                    'usage' => $cpuUsage,
                    'cores' => $cores,
                ],
                'memory' => $memory,
                'disk' => $disk,
                'coroutine' => [
                    'count' => (int) ($coroutineStats['coroutine_num'] ?? 0),
                    'peak' => (int) ($coroutineStats['coroutine_peak_num'] ?? 0),
                    'max' => (int) ($setting['max_coro_num'] ?? 100000),
                ],
                'connections' => (int) ($serverStats['connection_num'] ?? 0),
                'max_connections' => (int) ($setting['max_connection'] ?? 10000),
                'request_count' => (int) ($serverStats['request_count'] ?? 0),
                'start_time' => $startTime,
                'uptime_str' => $this->uptimeStr($startTime > 0 ? max(0, time() - $startTime) : 0),
            ],
        ]);
    }

    /**
     * CPU 使用率: Linux 读 /proc/stat 双采样求差; macOS 汇总进程 CPU 占比按核数归一
     * @return array{0: ?float, 1: int} [使用率%, 核心数]
     */
    private function cpuUsage(): array {

        $cores = $this->cpuCores();

        if (is_file('/proc/stat')) {
            $first = $this->readProcStat();
            if ($first) {
                if (class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() >= 0) {
                    \Swoole\Coroutine::sleep(0.15);
                } else {
                    usleep(150000);
                }
                $second = $this->readProcStat();
                $deltaTotal = ($second['total'] ?? 0) - ($first['total'] ?? 0);
                $deltaIdle = ($second['idle'] ?? 0) - ($first['idle'] ?? 0);
                if ($deltaTotal > 0) {
                    $usage = (1 - $deltaIdle / $deltaTotal) * 100;
                    return [round(max(0.0, min(100.0, $usage)), 1), $cores];
                }
            }
            return [null, $cores];
        }

        // macOS: ps 汇总进程 CPU 占比(单核基准), 按核心数归一为整机负载
        $sum = $this->execCommand("ps -Ao pcpu | awk '{s+=\$1} END {print s}'");
        if ($sum !== '' && is_numeric($sum)) {
            return [round(max(0.0, min(100.0, ((float) $sum) / max(1, $cores))), 1), $cores];
        }

        // 兜底: 负载均值估算
        $load = function_exists('sys_getloadavg') ? (array) sys_getloadavg() : [0];
        return [round(min(100.0, ((float) ($load[0] ?? 0)) / max(1, $cores) * 100), 1), $cores];
    }

    /**
     * 读取 /proc/stat 的 CPU 总时间片与空闲时间片
     */
    private function readProcStat(): ?array {

        $content = @file_get_contents('/proc/stat');
        if (!is_string($content) || $content === '') {
            return null;
        }
        if (!preg_match('/^cpu\s+(.+)$/m', $content, $m)) {
            return null;
        }
        $fields = array_map('intval', preg_split('/\s+/', trim($m[1])));
        // user nice system idle iowait irq softirq steal...
        return [
            'total' => array_sum($fields),
            'idle' => ($fields[3] ?? 0) + ($fields[4] ?? 0),
        ];
    }

    /**
     * CPU 核心数
     */
    private function cpuCores(): int {

        if (is_file('/proc/cpuinfo')) {
            $count = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
            if ($count > 0) {
                return $count;
            }
        }
        $ncpu = $this->execCommand('sysctl -n hw.ncpu 2>/dev/null || nproc 2>/dev/null');
        if ($ncpu !== '' && (int) $ncpu > 0) {
            return (int) $ncpu;
        }
        return 4;
    }

    /**
     * 内存占用: Linux 读 /proc/meminfo; macOS 用 vm_stat + sysctl
     * @return array{used_mb: float, total_mb: float, usage: float}
     */
    private function memoryUsage(): array {

        if (is_file('/proc/meminfo')) {
            $info = (string) @file_get_contents('/proc/meminfo');
            if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $info, $total)) {
                preg_match('/^MemAvailable:\s+(\d+)\s+kB/m', $info, $avail);
                $totalMb = ((int) $total[1]) / 1024;
                $usedMb = $totalMb - ((int) ($avail[1] ?? 0)) / 1024;
                return [
                    'used_mb' => round(max(0.0, $usedMb), 1),
                    'total_mb' => round($totalMb, 1),
                    'usage' => $totalMb > 0 ? round(max(0.0, $usedMb / $totalMb) * 100, 1) : 0.0,
                ];
            }
        }

        // macOS
        $vmStat = $this->execCommand('vm_stat');
        $bytes = $this->execCommand('sysctl -n hw.memsize');
        if ($vmStat !== '' && $bytes !== '' && is_numeric($bytes)) {
            preg_match('/page size of (\d+) bytes/', $vmStat, $pm);
            $pageSize = max(1, (int) ($pm[1] ?? 4096));
            $freePages = $this->vmStatPages($vmStat, 'Pages free') + $this->vmStatPages($vmStat, 'Pages speculative');
            $totalBytes = (int) $bytes;
            $usedBytes = max(0, $totalBytes - $freePages * $pageSize);
            $totalMb = $totalBytes / 1048576;
            $usedMb = $usedBytes / 1048576;
            return [
                'used_mb' => round($usedMb, 1),
                'total_mb' => round($totalMb, 1),
                'usage' => $totalMb > 0 ? round($usedMb / $totalMb * 100, 1) : 0.0,
            ];
        }

        // 兜底: 仅报告当前进程占用 (total 为 0, 前端展示为 '-')
        return [
            'used_mb' => round(memory_get_usage(true) / 1048576, 1),
            'total_mb' => 0.0,
            'usage' => 0.0,
        ];
    }

    /**
     * 解析 vm_stat 输出中的页数 (如 "Pages free: 12345.")
     */
    private function vmStatPages(string $output, string $key): int {

        if (preg_match('/^' . preg_quote($key, '/') . ':\s+(\d+)\./m', $output, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    /**
     * 协程安全的命令执行
     */
    private function execCommand(string $command): string {

        try {
            if (class_exists('\Swoole\Coroutine') && \Swoole\Coroutine::getCid() >= 0 && class_exists('\Swoole\Coroutine\System')) {
                $result = \Swoole\Coroutine\System::exec($command, 5);
                return trim((string) ($result['output'] ?? ''));
            }
        } catch (\Throwable) {
        }
        return trim((string) @shell_exec($command));
    }

    /**
     * 运行时长文案
     */
    private function uptimeStr(int $seconds): string {

        if ($seconds <= 0) {
            return '-';
        }
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $str = '';
        if ($days > 0) {
            $str .= $days . '天';
        }
        if ($days > 0 || $hours > 0) {
            $str .= $hours . '小时';
        }
        return $str . $minutes . '分';
    }

    /**
     * 重启服务器
     */
    public function restart() {
        Server::restart();
        return $this->response->json([
            'code' => 200,
            'message' => '服务器重启信号已发送',
        ]);
    }

    /**
     * 获取信号名称
     */
    protected function getSignalName(int $signal): string {
        $constants = get_defined_constants(true)['pcntl'] ?? [];
        $signalNames = array_filter($constants, function ($key) {
            return strpos($key, 'SIG') === 0 && strpos($key, 'SIG_') !== 0;
        }, ARRAY_FILTER_USE_KEY);
        $signalNames = array_flip($signalNames);
        return $signalNames[$signal] ?? "SIGNAL_{$signal}";
    }
}
