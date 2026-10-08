<?php

declare(strict_types=1);

namespace App\openai;

/**
 * OpenAI Task
 *
 * 任务来源支持两种:
 * - openai_tasks 表: 业务方按任务名调用 app('openai.Task')->run($taskname, $data, $options)
 * - 任务目录 (config.php + system.jinja + user.jinja): 兼容旧版按路径传参的调用方式,
 *   目录不存在时以表为准, 表中也没有才报错
 * 用内置轻量 Jinja2 渲染器替换模板变量后调用 OpenAI Chat API。
 */
class Task {
    /**
     * 执行任务
     *
     * $taskname 支持三种形式:
     * - 任务名: 优先查 openai_tasks 表 (status=1), 无记录时回退 openai 模块 Task/{name} 目录
     * - 任务目录路径: 直接按文件加载, 兼容旧版 app_path('xx', 'Task/yy') 传参
     * - $options: 覆盖任务配置 (provider/model/temperature 等, 优先级最高)
     */
    public function run(string $taskname, $data = [], $options = []) {
        if ($taskname === '') {
            throw new \RuntimeException('OpenAI Task name must not be empty');
        }

        $task = $this->loadTask($taskname);
        $config = $task['config'];
        if (!empty($options)) {
            $config = array_merge($config, $options);
        }
        if (empty($config)) {
            throw new \RuntimeException('OpenAI Task ' . $taskname . ' not found');
        }

        // 如果配置的模型为数组，随机抽取一个模型执行
        if (isset($config['model']) && is_array($config['model'])) {
            $models = array_values(array_filter($config['model'], fn ($m) => is_string($m) && trim($m) !== ''));
            if (!empty($models)) {
                $config['model'] = $models[array_rand($models)];
            }
        }

        // 未显式指定供应商时，把按模型路由的结果写回 config，使用量明细记录到真实命中的供应商
        if (empty($config['provider'])) {
            try {
                $config['provider'] = app('openai')->resolveProvider(null, (string) ($config['model'] ?? ''));
            } catch (\Throwable) {
                // 路由不可用时保持为空，明细里记作未知供应商
            }
        }

        $systemJinja = $this->renderJinja($task['system_template'], $data);
        $userJinja = $this->renderJinja($task['user_template'], $data);

        $startedAt = microtime(true);
        try {
            $response = app('openai')->chat()->create(array_merge($config, [
                'messages' => [
                    ['role' => 'system', 'content' => $systemJinja],
                    ['role' => 'user',   'content' => $userJinja],
                ]
            ]));
            $array = $response->toArray();
            $this->recordUsage($taskname, $config, $array['usage'] ?? [], (int) ((microtime(true) - $startedAt) * 1000), '');
            return $array;
        } catch (\Throwable $e) {
            $this->recordUsage($taskname, $config, [], (int) ((microtime(true) - $startedAt) * 1000), $e->getMessage());
            throw $e;
        }
    }

    /**
     * 加载任务配置与模板
     */
    private function loadTask(string $taskname): array {
        // 含路径分隔符 → 旧版按路径加载
        if (strpbrk($taskname, '/\\') !== false) {
            return $this->loadFileTask($taskname);
        }

        // 数据库任务优先; 表不存在等异常时静默降级为文件模式, 保证未装表的环境可用
        $row = null;
        try {
            $row = db('openai_tasks')->where('name', $taskname)->where('status', 1)->first();
        } catch (\Throwable) {
        }
        if (!empty($row)) {
            return $this->loadDbTask((array) $row);
        }

        return $this->loadFileTask(xphp('app')->getAppPath('openai', 'Task') . '/' . $taskname);
    }

    /**
     * 从任务目录加载 (config.php + system.jinja + user.jinja)
     */
    private function loadFileTask(string $taskPath): array {
        $config = cache_read('config.php', $taskPath);
        return [
            'name' => basename($taskPath),
            'config' => is_array($config) ? $config : [],
            'system_template' => is_file($taskPath . '/system.jinja') ? (string) file_get_contents($taskPath . '/system.jinja') : '',
            'user_template' => is_file($taskPath . '/user.jinja') ? (string) file_get_contents($taskPath . '/user.jinja') : '',
        ];
    }

    /**
     * 从 openai_tasks 表记录加载; config JSON 先合并, 显式列 (provider/model/temperature/max_tokens) 覆盖之
     */
    private function loadDbTask(array $row): array {
        $config = [];
        if (!empty($row['config'])) {
            $decoded = json_decode((string) $row['config'], true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        if (!empty($row['provider'])) {
            $config['provider'] = (string) $row['provider'];
        }
        $model = trim((string) ($row['model'] ?? ''));
        if ($model !== '') {
            $decoded = str_starts_with($model, '[') ? json_decode($model, true) : null;
            $config['model'] = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : $model;
        }
        if (isset($row['temperature']) && $row['temperature'] !== null && $row['temperature'] !== '') {
            $config['temperature'] = (float) $row['temperature'];
        }
        if (!empty($row['max_tokens'])) {
            $config['max_tokens'] = (int) $row['max_tokens'];
        }
        return [
            'name' => (string) $row['name'],
            'config' => $config,
            'system_template' => (string) ($row['system_template'] ?? ''),
            'user_template' => (string) ($row['user_template'] ?? ''),
        ];
    }

    /**
     * 将模块 Task 目录下的文件任务导入 openai_tasks 表
     * 同名任务已存在时跳过, 避免覆盖数据库中的修改; config.php 中
     * 未映射的字段 (如 enable_thinking) 保留进 config JSON 不丢失
     */
    public function importFromFiles(string $module = 'openai'): array {
        $baseDir = xphp('app')->getAppPath($module, 'Task');
        $mapped = ['provider', 'model', 'temperature', 'max_tokens'];
        $rows = [];

        foreach ((array) glob($baseDir . '/*', GLOB_ONLYDIR) as $dir) {
            $configFile = $dir . '/config.php';
            if (!is_file($configFile)) {
                continue;
            }
            $config = (array) (include $configFile);
            $model = $config['model'] ?? '';
            $extra = array_diff_key($config, array_flip($mapped));
            $rows[] = [
                'name' => basename($dir),
                'title' => basename($dir),
                'remark' => '',
                'provider' => (string) ($config['provider'] ?? ''),
                'model' => is_array($model)
                    ? (string) json_encode(array_values(array_filter($model, 'is_string')), JSON_UNESCAPED_UNICODE)
                    : (string) $model,
                'temperature' => isset($config['temperature']) ? (float) $config['temperature'] : null,
                'max_tokens' => isset($config['max_tokens']) ? (int) $config['max_tokens'] : null,
                'config' => !empty($extra) ? (string) json_encode($extra, JSON_UNESCAPED_UNICODE) : '',
                'system_template' => is_file($dir . '/system.jinja') ? (string) file_get_contents($dir . '/system.jinja') : '',
                'user_template' => is_file($dir . '/user.jinja') ? (string) file_get_contents($dir . '/user.jinja') : '',
                'uniqid' => random(16),
            ];
        }

        $existing = [];
        try {
            foreach (db('openai_tasks')->select(['name'])->get() as $r) {
                $existing[] = (string) (is_object($r) ? $r->name : $r['name']);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('openai_tasks 表不可用, 请先完成应用安装: ' . $e->getMessage());
        }

        $new = array_values(array_filter($rows, fn ($r) => !in_array($r['name'], $existing, true)));
        if (!empty($new)) {
            db('openai_tasks')->upsert($new, 'name');
        }
        return [
            'imported' => count($new),
            'skipped' => count($rows) - count($new),
            'names' => array_column($new, 'name'),
        ];
    }

    /**
     * 记录任务执行用量 (优先写 openai_task_usages 表, 表不可用时退回 jsonl 文件, 供后台使用记录与统计读取)
     */
    protected function recordUsage(string $taskname, array $config, array $usage, int $durationMs, string $error): void {
        try {
            $record = [
                'task' => basename($taskname),
                'provider' => (string) ($config['provider'] ?? ''),
                'model' => (string) ($config['model'] ?? ''),
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                'duration_ms' => $durationMs,
                'error' => mb_substr($error, 0, 1000),
            ];
            try {
                db('openai_task_usages')->insert($record);
                return;
            } catch (\Throwable) {
                // 表不可用时退回 jsonl 文件
            }
            file_put_contents(
                runtime_path('logs') . 'openai-usage.jsonl',
                json_encode(array_merge(['time' => date('Y-m-d H:i:s')], $record), JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // 记录失败不影响主流程
        }
    }

    // ==================== 轻量 Jinja2 渲染器 ====================

    /**
     * 渲染 Jinja2 模板(支持 {{ var }} / {{ var | filter }} / {% for %} / {% if %})
     */
    protected function renderJinja(string $template, array $data): string {
        // 1. 处理 {% for item in collection %} ... {% endfor %}
        $template = $this->renderForLoops($template, $data);
        // 2. 处理 {% if %} / {% endif %}
        $template = $this->renderIfBlocks($template, $data);
        // 3. 处理 {{ variable | filter }} 和 {{ variable }}
        $template = $this->renderVariables($template, $data);
        return $template;
    }

    /**
     * 渲染 {% for item in collection %} ... {% endfor %}
     * 支持 {{ item.field }} 形式访问循环变量属性
     */
    private function renderForLoops(string $template, array $data): string {
        $pattern = '/\{%\s*for\s+(\w+)\s+in\s+(\w+)\s*%\}(.*?)\{%\s*endfor\s*%\}/s';
        return preg_replace_callback($pattern, function ($m) use ($data) {
            $itemName = $m[1];
            $collName = $m[2];
            $body = $m[3];
            $collection = $data[$collName] ?? [];
            if (!is_array($collection)) {
                return '';
            }
            $total = count($collection);
            $out = '';
            foreach ($collection as $idx => $item) {
                // 构建循环作用域:合并父数据 + 循环变量
                $loopData = $data;
                $loopData[$itemName] = $item;
                $loopData['loop'] = [
                    'index' => $idx + 1,
                    'index0' => $idx,
                    'first' => $idx === 0,
                    'last' => $idx === $total - 1,
                ];
                // 递归渲染循环体(支持 {{ item.field }} 和 {{ if }} 块)
                $rendered = $this->renderIfBlocks($body, $loopData);
                $rendered = $this->renderVariables($rendered, $loopData);
                $out .= $rendered;
            }
            return $out;
        }, $template);
    }

    /**
     * 渲染 {% if variable %} ... {% elif %} ... {% else %} ... {% endif %}
     */
    private function renderIfBlocks(string $template, array $data): string {
        // 先处理嵌套的 if 块(从内到外)
        $maxIter = 10;
        while ($maxIter-- > 0 && preg_match('/\{%\s*if\s+/', $template)) {
            $template = preg_replace_callback(
                '/\{%\s*if\s+(not\s+)?(\w+)\s*%\}(.*?)(\{%\s*(?:elif|else)\s*.*?%\}.*?)?\{%\s*endif\s*%\}/s',
                function ($m) use ($data) {
                    $negate = !empty(trim($m[1] ?? ''));
                    $varName = $m[2];
                    $rest = $m[3] . ($m[4] ?? '');

                    $val = $data[$varName] ?? null;
                    $truthy = !empty($val) && $val !== '' && $val !== '0' && $val !== [];
                    if ($negate) {
                        $truthy = !$truthy;
                    }

                    if ($truthy) {
                        // 取 if 块内容(排除 elif/else 部分)
                        if (preg_match('/^(.*?)(?:\{%\s*(?:elif|else)\s)/s', $m[0], $hm)) {
                            // 重新匹配:只取 if 后到首个 elif/else 之前
                        }
                        // 简化:直接用 $m[3] 作为 then 分支
                        return $m[3];
                    }

                    // 检查 elif 分支
                    $restFull = $m[4] ?? '';
                    if (preg_match_all('/\{%\s*elif\s+(\w+)\s*%\}(.*?)(?=\{%\s*(?:elif|else|endif)\s)/s', $restFull, $elifs, PREG_SET_ORDER)) {
                        foreach ($elifs as $ei) {
                            $elifVal = $data[$ei[1]] ?? null;
                            $elifTruthy = !empty($elifVal) && $elifVal !== '' && $elifVal !== '0' && $elifVal !== [];
                            if ($elifTruthy) {
                                return $ei[2];
                            }
                        }
                    }

                    // else 分支
                    if (preg_match('/\{%\s*else\s*%\}(.*)/s', $restFull, $em)) {
                        return $em[1];
                    }
                    return '';
                },
                $template
            );
        }
        return $template;
    }

    /**
     * 渲染 {{ variable }} 和 {{ variable | filter }}
     * 支持: length / upper / lower / default / join / tojson
     */
    private function renderVariables(string $template, array $data): string {
        $pattern = '/\{\{\s*(\w+(?:\.\w+)*)(?:\s*\|\s*(\w+)(?:\([\'"]?(.*?)[\'"]?\))?)?\s*\}\}/';
        return preg_replace_callback($pattern, function ($m) use ($data) {
            $path = $m[1];
            $filter = $m[2] ?? '';
            $filterArg = $m[3] ?? '';

            // 解析 a.b.c 路径
            $val = $this->resolveVar($path, $data);

            // 应用 filter
            if ($filter !== '') {
                $val = $this->applyFilter($val, $filter, $filterArg);
            }

            return $this->toJinjaValue($val);
        }, $template);
    }

    /**
     * 解析变量路径(支持 a.b.c,循环内 a 为数组时自动展开)
     */
    private function resolveVar(string $path, array $data) {
        $parts = explode('.', $path);
        $val = $data;
        foreach ($parts as $p) {
            if (is_array($val) && array_key_exists($p, $val)) {
                $val = $val[$p];
            } else {
                // 尝试对象属性访问
                if (is_object($val) && property_exists($val, $p)) {
                    $val = $val->$p;
                } else {
                    return null;
                }
            }
        }
        return $val;
    }

    /**
     * 应用 Jinja2 filter
     */
    private function applyFilter($val, string $filter, string $arg = '') {
        switch ($filter) {
            case 'length':
                return is_array($val) ? count($val) : mb_strlen((string)($val ?? ''));
            case 'upper':
                return mb_strtoupper((string)($val ?? ''));
            case 'lower':
                return mb_strtolower((string)($val ?? ''));
            case 'default':
                return ($val === null || $val === '' || $val === false) ? $arg : $val;
            case 'join':
                return is_array($val) ? implode($arg ?: ', ', $val) : (string)($val ?? '');
            case 'tojson':
                return json_encode($val, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            case 'truncate':
                $parts = array_map('trim', explode(',', $arg));
                $maxLen = (int)($parts[0] ?: 255);
                $s = (string)($val ?? '');
                if (mb_strlen($s) > $maxLen) {
                    return mb_substr($s, 0, $maxLen) . '...';
                }
                return $s;
            default:
                return $val;
        }
    }

    /**
     * 将 PHP 值转为模板输出字符串
     */
    private function toJinjaValue($val): string {
        if ($val === null || $val === false) {
            return '';
        }
        if ($val === true) {
            return '1';
        }
        if (is_array($val)) {
            return json_encode($val, JSON_UNESCAPED_UNICODE);
        }
        return (string)$val;
    }
}
