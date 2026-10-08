<?php

declare(strict_types=1);

namespace App\openai\Controller\Admin;

use App\admin\Authorization;
use App\openai\Pool;
use App\openai\Task;
use Throwable;

/**
 * OpenAI 服务管理
 * 任务 (Task 目录: config.php + system.jinja + user.jinja) 的配置/创建/运行,
 * 使用记录 (openai_task_usages 明细, Task::run 埋点) 与生效供应商清单;
 * 账号池见 Provider 控制器, 模型与优先级见 Model 控制器, 看板见 Board 控制器
 */
class Admin extends Authorization {
    /**
     * 任务根目录 (phar 应用时指向解包目录, 不可写则保存类操作会明确报错)
     */
    private function taskBaseDir(): string {
        return (string) xphp('app')->getAppPath('openai', 'Task', true);
    }

    /**
     * 概览统计
     */
    public function stats() {

        $today = date('Y-m-d');

        $data = [
            'today_calls' => 0,
            'today_tokens' => 0,
            'total_calls' => 0,
            'total_tokens' => 0,
            'failed' => 0,
        ];

        try {
            $stats = m('openai.StatsDaily');
            $all = $stats->overview();
            $todayRow = $stats->overview($today, $today);
            $data['total_calls'] = $all['total_calls'];
            $data['total_tokens'] = $all['total_tokens'];
            $data['failed'] = $all['failed'];
            $data['today_calls'] = $todayRow['total_calls'];
            $data['today_tokens'] = $todayRow['total_tokens'];
        } catch (\Throwable) {
            // 日聚合表不可用时退回明细表(只覆盖 Task 调用)
            $todayCalls = 0;
            $todayTokens = 0;
            foreach ($this->readUsage(2000) as $row) {
                $data['total_calls']++;
                $data['total_tokens'] += (int) ($row['total_tokens'] ?? 0);
                if (!empty($row['error'])) {
                    $data['failed']++;
                }
                if (str_starts_with((string) ($row['time'] ?? ''), $today)) {
                    $todayCalls++;
                    $todayTokens += (int) ($row['total_tokens'] ?? 0);
                }
            }
            $data['today_calls'] = $todayCalls;
            $data['today_tokens'] = $todayTokens;
        }

        try {
            $taskCount = (int) db('openai_tasks')->where('status', 1)->count();
        } catch (\Throwable) {
            // 表不可用时回退文件任务数
            $taskDirs = is_dir($this->taskBaseDir()) ? (array) glob($this->taskBaseDir() . '/*', GLOB_ONLYDIR) : [];
            $taskCount = count($taskDirs);
        }

        show_json([
            'code' => 200,
            'data' => $data + [
                'providers' => count($this->poolRoute()['accounts']),
                'tasks' => $taskCount,
            ],
        ]);
    }

    /**
     * 任务列表 (openai_tasks 表; 表不可用时回退 Task 目录文件任务)
     */
    public function tasks() {

        $dbOk = false;
        $rows = [];
        try {
            foreach (db('openai_tasks')->orderByDesc('id')->get() as $r) {
                $r = (array) $r;
                $modelVal = trim((string) ($r['model'] ?? ''));
                $decoded = str_starts_with($modelVal, '[') ? json_decode($modelVal, true) : null;
                $models = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : ($modelVal !== '' ? [$modelVal] : []);
                $rows[] = [
                    'name' => (string) ($r['name'] ?? ''),
                    'title' => (string) ($r['title'] ?? ''),
                    'remark' => (string) ($r['remark'] ?? ''),
                    'provider' => (string) ($r['provider'] ?? ''),
                    'model' => implode(', ', $models),
                    'models' => $models,
                    'is_multiple_models' => count($models) > 1,
                    'temperature' => isset($r['temperature']) && $r['temperature'] !== null ? (float) $r['temperature'] : null,
                    'max_tokens' => !empty($r['max_tokens']) ? (int) $r['max_tokens'] : null,
                    'has_schema' => false,
                    'system_size' => strlen((string) ($r['system_template'] ?? '')),
                    'user_size' => strlen((string) ($r['user_template'] ?? '')),
                    'status' => (int) ($r['status'] ?? 1),
                    'source' => 'db',
                    'updated_at' => (string) ($r['updated_at'] ?? ''),
                ];
            }
            $dbOk = true;
        } catch (\Throwable) {
            // 表不可用时回退文件任务列表
        }

        if ($dbOk) {
            show_json(['code' => 200, 'data' => $rows]);
            return;
        }

        $base = $this->taskBaseDir();
        $tasks = [];

        foreach ((array) glob($base . '/*', GLOB_ONLYDIR) as $dir) {
            $name = basename($dir);
            $config = [];
            $configFile = $dir . '/config.php';
            if (is_file($configFile)) {
                $config = (array) (include $configFile);
            }
            $modelVal = $config['model'] ?? '';
            $tasks[] = [
                'name' => $name,
                'title' => $name,
                'remark' => '',
                'provider' => (string) ($config['provider'] ?? ''),
                'model' => is_array($modelVal) ? implode(', ', $modelVal) : (string) $modelVal,
                'models' => is_array($modelVal) ? array_values($modelVal) : ($modelVal !== '' ? [(string) $modelVal] : []),
                'is_multiple_models' => is_array($modelVal) && count($modelVal) > 1,
                'temperature' => $config['temperature'] ?? null,
                'max_tokens' => $config['max_tokens'] ?? null,
                'has_schema' => is_file($dir . '/schema.php'),
                'system_size' => is_file($dir . '/system.jinja') ? (int) filesize($dir . '/system.jinja') : 0,
                'user_size' => is_file($dir . '/user.jinja') ? (int) filesize($dir . '/user.jinja') : 0,
                'status' => 1,
                'source' => 'file',
                'updated_at' => is_file($configFile) ? date('Y-m-d H:i:s', (int) filemtime($configFile)) : '',
            ];
        }

        show_json([
            'code' => 200,
            'data' => $tasks,
        ]);
    }

    /**
     * 任务详情 (配置 + 模板内容, openai_tasks 表)
     */
    public function taskGet() {

        $name = (string) input('.name', '', 'trim');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            show_json(['code' => 201, 'message' => '无效的任务标识']);
            return;
        }

        try {
            $r = (array) db('openai_tasks')->where('name', $name)->first();
        } catch (\Throwable) {
            show_json(['code' => 201, 'message' => 'openai_tasks 表不可用, 请先完成应用安装']);
            return;
        }
        if (empty($r['name'])) {
            show_json(['code' => 201, 'message' => '任务不存在']);
            return;
        }

        $modelVal = trim((string) ($r['model'] ?? ''));
        $decoded = str_starts_with($modelVal, '[') ? json_decode($modelVal, true) : null;
        $models = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : ($modelVal !== '' ? [$modelVal] : []);

        show_json([
            'code' => 200,
            'data' => [
                'name' => (string) $r['name'],
                'provider' => (string) ($r['provider'] ?? ''),
                'remark' => (string) ($r['remark'] ?? ''),
                'model' => implode(', ', $models),
                'models' => $models,
                'temperature' => isset($r['temperature']) && $r['temperature'] !== null ? (float) $r['temperature'] : 0.7,
                'max_tokens' => !empty($r['max_tokens']) ? (int) $r['max_tokens'] : 2000,
                'system_template' => (string) ($r['system_template'] ?? ''),
                'user_template' => (string) ($r['user_template'] ?? ''),
                'schema' => '',
            ],
        ]);
    }

    /**
     * 创建 / 保存任务 (openai_tasks 表; 同名覆盖更新)
     * @log
     */
    public function taskSave() {

        $name = (string) input('post.name', '', 'trim');
        $provider = input('post.provider', '', 'trim');
        $modelInput = input('post.model', '');
        $temperature = (float) input('post.temperature', 0.7);
        $maxTokens = (int) input('post.max_tokens', 2000);
        $systemTemplate = (string) input('post.system_template', '');
        $userTemplate = (string) input('post.user_template', '');

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            show_json(['code' => 201, 'message' => '任务标识只能以小写字母开头, 含小写字母/数字/下划线']);
            return;
        }

        $model = '';
        if (is_array($modelInput)) {
            $cleaned = array_values(array_filter(array_map('trim', $modelInput), fn ($v) => $v !== ''));
            $model = count($cleaned) > 1 ? $cleaned : ($cleaned[0] ?? '');
        } elseif (is_string($modelInput)) {
            $modelInput = trim($modelInput);
            if (str_starts_with($modelInput, '[') && str_ends_with($modelInput, ']')) {
                $decoded = json_decode($modelInput, true);
                if (is_array($decoded)) {
                    $cleaned = array_values(array_filter(array_map('trim', $decoded), fn ($v) => $v !== ''));
                    $model = count($cleaned) > 1 ? $cleaned : ($cleaned[0] ?? '');
                }
            }
            if (empty($model)) {
                $parts = array_values(array_filter(array_map('trim', preg_split('/[,\n\r]+/', $modelInput)), fn ($v) => $v !== ''));
                $model = count($parts) > 1 ? $parts : ($parts[0] ?? '');
            }
        }

        if (empty($model)) {
            show_json(['code' => 201, 'message' => '请配置至少一个模型名称']);
            return;
        }

        $row = [
            'name' => $name,
            'title' => $name,
            'remark' => mb_substr((string) input('post.remark', '', 'trim'), 0, 500),
            'provider' => (string) $provider,
            'model' => is_array($model) ? json_encode($model, JSON_UNESCAPED_UNICODE) : (string) $model,
            'temperature' => max(0, min(2, $temperature)),
            'max_tokens' => max(1, $maxTokens),
            'system_template' => $systemTemplate,
            'user_template' => $userTemplate,
        ];

        try {
            $exists = db('openai_tasks')->where('name', $name)->first();
            if (!empty($exists)) {
                db('openai_tasks')->where('name', $name)->update($row);
            } else {
                $row['uniqid'] = random(16);
                db('openai_tasks')->insert($row);
            }
        } catch (\Throwable) {
            show_json(['code' => 201, 'message' => '保存失败: openai_tasks 表不可用, 请先完成应用安装']);
            return;
        }

        show_json([
            'code' => 200,
            'message' => '任务已保存',
            'data' => ['name' => $name],
        ]);
    }

    /**
     * 删除任务 (openai_tasks 表)
     * @log
     */
    public function taskDelete() {

        $name = (string) input('post.name', '', 'trim');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            show_json(['code' => 201, 'message' => '无效的任务标识']);
            return;
        }

        try {
            $deleted = db('openai_tasks')->where('name', $name)->delete();
        } catch (\Throwable) {
            show_json(['code' => 201, 'message' => '删除失败: openai_tasks 表不可用']);
            return;
        }

        if (!$deleted) {
            show_json(['code' => 201, 'message' => '任务不存在']);
            return;
        }

        show_json(['code' => 200, 'message' => '任务已删除']);
    }

    /**
     * 启用/停用任务 (停用后 run() 不再命中该任务, 按名回退文件或报 not found)
     * @log
     */
    public function taskToggle() {

        $name = (string) input('post.name', '', 'trim');
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            show_json(['code' => 201, 'message' => '无效的任务标识']);
            return;
        }

        try {
            $r = (array) db('openai_tasks')->where('name', $name)->first();
            if (empty($r['name'])) {
                show_json(['code' => 201, 'message' => '任务不存在']);
                return;
            }
            $status = (int) ($r['status'] ?? 1) === 1 ? 0 : 1;
            db('openai_tasks')->where('name', $name)->update(['status' => $status]);
        } catch (\Throwable) {
            show_json(['code' => 201, 'message' => '操作失败: openai_tasks 表不可用']);
            return;
        }

        show_json([
            'code' => 200,
            'message' => $status === 1 ? '任务已启用' : '任务已停用',
            'data' => ['name' => $name, 'status' => $status],
        ]);
    }

    /**
     * 将 Task 目录下的文件任务导入 openai_tasks 表 (同名任务已存在时跳过)
     * @log
     */
    public function taskSync() {

        try {
            $result = $this->container->get(Task::class)->importFromFiles();
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '导入失败: ' . $e->getMessage()]);
            return;
        }

        show_json([
            'code' => 200,
            'message' => "导入 {$result['imported']} 个任务, 跳过 {$result['skipped']} 个",
            'data' => $result,
        ]);
    }

    /**
     * 运行任务 (data 为 JSON 字符串, 作为模板变量)
     * @log
     */
    public function taskRun() {

        $name = (string) input('post.name', '', 'trim');
        $dataRaw = (string) input('post.data', '');

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            show_json(['code' => 201, 'message' => '无效的任务标识']);
            return;
        }

        $data = [];
        if (trim($dataRaw) !== '') {
            $data = json_decode($dataRaw, true);
            if (!is_array($data)) {
                show_json(['code' => 201, 'message' => '输入数据需为合法 JSON 对象']);
                return;
            }
        }

        try {
            /** @var Task $task */
            $task = $this->container->get(Task::class);
            // 按任务名执行: openai_tasks 表优先, 无记录时回退 Task 目录文件任务
            $result = $task->run($name, $data);
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '任务执行失败: ' . $e->getMessage()]);
            return;
        }

        show_json([
            'code' => 200,
            'message' => '任务执行成功',
            'data' => [
                'usage' => $result['usage'] ?? [],
                'choices' => $result['choices'] ?? [],
                'result' => $result,
            ],
        ]);
    }

    /**
     * 使用记录 (runtime/logs/openai-usage.jsonl 倒序分页)
     */
    public function usage() {

        $page = max(1, input('.page', 1, 'intval'));
        $limit = min(100, max(1, input('.limit', 20, 'intval')));
        $task = input('.task', '', 'trim');

        $rows = $this->readUsage(2000);
        $total = count($rows);

        if ($task !== '') {
            $rows = array_values(array_filter($rows, fn ($row) => ($row['task'] ?? '') === $task));
        }

        $rows = array_slice($rows, ($page - 1) * $limit, $limit);

        show_json([
            'code' => 200,
            'data' => [
                'data' => $rows,
                'count' => count($rows),
                'total' => $total,
                'page' => $page,
                'limit' => $limit,
            ],
        ]);
    }

    /**
     * 生效的供应商清单 (账号池 → config 表覆盖 → 文件/env，密钥只回布尔标记)
     * 供任务编辑器的供应商下拉使用；账号池的增删改在 Provider 控制器
     */
    public function providers() {

        $route = $this->poolRoute();

        $providers = [];
        foreach ($route['accounts'] as $name => $accounts) {
            $account = $accounts[0] ?? [];
            $providers[] = [
                'name' => (string) $name,
                'base_uri' => (string) ($account['base_uri'] ?? ''),
                'has_key' => (string) ($account['api_key'] ?? '') !== '',
                'request_timeout' => (int) ($account['request_timeout'] ?? 300),
                // 账号池里的账号数，1 表示只有文件/env 兜底配置
                'accounts' => count($accounts),
                'from_db' => !empty($account['from_db']),
            ];
        }

        show_json([
            'code' => 200,
            'data' => [
                'default_provider' => (string) $route['default'],
                'providers' => $providers,
            ],
        ]);
    }

    /**
     * 读取当前生效的路由表
     *
     * @return array{accounts:array<string,array<int,array>>,models:array<string,string>,prefixes:array<string,string>,default:string,db_ok:bool}
     */
    private function poolRoute(): array {

        try {
            return (new Pool($this->container))->read();
        } catch (\Throwable) {
            return ['accounts' => [], 'models' => [], 'prefixes' => [], 'default' => 'openai', 'db_ok' => false];
        }
    }

    /**
     * 读取用量审计 (优先读 openai_task_usages 表, 表不可用时退回 jsonl 文件; 倒序返回: 最新在前)
     */
    private function readUsage(int $maxLines): array {

        try {
            $rows = [];
            foreach (db('openai_task_usages')->orderByDesc('id')->limit($maxLines)->get() as $r) {
                $r = (array) $r;
                $rows[] = [
                    'time' => (string) ($r['created_at'] ?? ''),
                    'task' => (string) ($r['task'] ?? ''),
                    'provider' => (string) ($r['provider'] ?? ''),
                    'model' => (string) ($r['model'] ?? ''),
                    'prompt_tokens' => (int) ($r['prompt_tokens'] ?? 0),
                    'completion_tokens' => (int) ($r['completion_tokens'] ?? 0),
                    'total_tokens' => (int) ($r['total_tokens'] ?? 0),
                    'duration_ms' => (int) ($r['duration_ms'] ?? 0),
                    'error' => (string) ($r['error'] ?? ''),
                ];
            }
            return $rows;
        } catch (\Throwable) {
            // 表不可用时退回 jsonl 文件
        }

        return $this->readUsageFile($maxLines);
    }

    /**
     * 读取 usage jsonl 文件尾部 (最多 2000 行, 倒序返回: 最新在前)
     */
    private function readUsageFile(int $maxLines): array {

        $file = runtime_path('logs') . 'openai-usage.jsonl';
        if (!is_file($file)) {
            return [];
        }

        $handle = @fopen($file, 'rb');
        if (!$handle) {
            return [];
        }

        $size = (int) filesize($file);
        $length = min($size, 1048576);
        fseek($handle, -$length, SEEK_END);
        $content = (string) fread($handle, $length);
        fclose($handle);

        $rows = [];
        foreach (array_reverse(explode("\n", trim($content))) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
            if (count($rows) >= $maxLines) {
                break;
            }
        }

        return $rows;
    }
}
