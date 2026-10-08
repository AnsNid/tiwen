<?php

declare(strict_types=1);

namespace App\openai\Controller\Admin;

use App\admin\Authorization;
use App\openai\Pool;
use Throwable;

/**
 * 模型配置管理
 * 一行 = 某供应商下的一个模型(支持 * 结尾的前缀规则)，同一模型配在多个供应商下时
 * priority 最小者胜出，即前台调用的 model_provider_map 优先级语义
 */
class Model extends Authorization {

    /**
     * 模型列表 (含所属供应商是否有可用账号的标记)
     */
    public function models() {

        $provider = (string) input('.provider', '', 'trim');
        $keyword = (string) input('.keyword', '', 'trim');

        try {
            $query = db('openai_models')->orderBy('priority')->orderBy('provider')->orderBy('id');
            if ($provider !== '') {
                $query->where('provider', $provider);
            }
            if ($keyword !== '') {
                $query->where('model', 'like', '%' . $keyword . '%');
            }
            $rows = $query->get();
        } catch (Throwable) {
            show_json(['code' => 201, 'message' => 'openai_models 表不可用, 请先执行 Install/20260922_add_provider_pool.sql']);
            return;
        }

        $accounts = $this->accountProviders();

        $models = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $name = (string) ($r['provider'] ?? '');
            $models[] = [
                'id' => (int) ($r['id'] ?? 0),
                'provider' => $name,
                'model' => (string) ($r['model'] ?? ''),
                'priority' => (int) ($r['priority'] ?? 100),
                'remark' => (string) ($r['remark'] ?? ''),
                'source' => (string) ($r['source'] ?? 'manual'),
                'status' => (int) ($r['status'] ?? 1),
                'is_prefix' => str_ends_with((string) ($r['model'] ?? ''), '*'),
                // 模型指向的供应商在账号池里没有启用账号时，前台调用会回退到文件配置
                'provider_ready' => isset($accounts[$name]),
                'updated_at' => (string) ($r['updated_at'] ?? ''),
            ];
        }

        show_json([
            'code' => 200,
            'data' => [
                'models' => $models,
                'providers' => array_keys($accounts),
                'default_provider' => app('openai')->defaultProvider(),
            ],
        ]);
    }

    /**
     * 保存模型 (id 为空则新增，同 provider + model 视为同一条)
     * @log
     */
    public function modelSave() {

        $id = (int) input('post.id', 0, 'intval');
        $provider = (string) input('post.provider', '', 'trim');
        $model = (string) input('post.model', '', 'trim');

        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $provider)) {
            show_json(['code' => 201, 'message' => '供应商标识只能含字母/数字/下划线/中划线, 且不超过32位']);
            return;
        }
        // 模型名要允许 deepseek-ai/DeepSeek-V3、/root/.cache/llama.cpp/* 这类写法，只排除空白与控制字符
        if ($model === '' || preg_match('/\s/', $model) || mb_strlen($model) > 128) {
            show_json(['code' => 201, 'message' => '请填写模型名(不含空格, 不超过128字符)']);
            return;
        }

        $row = [
            'provider' => $provider,
            'model' => $model,
            'priority' => max(0, min(9999, (int) input('post.priority', 100, 'intval'))),
            'remark' => mb_substr((string) input('post.remark', '', 'trim'), 0, 500),
            'status' => (int) input('post.status', 1, 'intval') === 1 ? 1 : 0,
        ];
        $source = (string) input('post.source', '', 'trim');
        if (in_array($source, ['manual', 'pulled'], true)) {
            $row['source'] = $source;
        }

        try {
            $duplicateQuery = db('openai_models')->where('provider', $provider)->where('model', $model);
            if ($id > 0) {
                $duplicateQuery->where('id', '<>', $id);
            }
            if (!empty($duplicateQuery->first())) {
                show_json(['code' => 201, 'message' => "供应商 {$provider} 下已存在模型「{$model}」"]);
                return;
            }

            if ($id > 0) {
                if (empty(db('openai_models')->where('id', $id)->first())) {
                    show_json(['code' => 201, 'message' => '模型不存在']);
                    return;
                }
                db('openai_models')->where('id', $id)->update($row);
            } else {
                $row['source'] = $row['source'] ?? 'manual';
                $row['uniqid'] = random(16);
                db('openai_models')->insert($row);
            }
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '保存失败: ' . $e->getMessage()]);
            return;
        }

        $this->flushPool();

        show_json(['code' => 200, 'message' => '模型已保存, 约 1 秒内全 worker 生效']);
    }

    /**
     * 删除模型
     * @log
     */
    public function modelDelete() {

        $id = (int) input('post.id', 0, 'intval');
        if ($id <= 0) {
            show_json(['code' => 201, 'message' => '请选择模型']);
            return;
        }

        try {
            $deleted = db('openai_models')->where('id', $id)->delete();
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '删除失败: ' . $e->getMessage()]);
            return;
        }

        if (!$deleted) {
            show_json(['code' => 201, 'message' => '模型不存在']);
            return;
        }

        $this->flushPool();

        show_json(['code' => 200, 'message' => '模型已删除']);
    }

    /**
     * 启用/停用模型 (停用后不再参与 model_provider_map 路由)
     * @log
     */
    public function modelToggle() {

        $id = (int) input('post.id', 0, 'intval');
        if ($id <= 0) {
            show_json(['code' => 201, 'message' => '请选择模型']);
            return;
        }

        try {
            $row = (array) db('openai_models')->where('id', $id)->first();
            if (empty($row['id'])) {
                show_json(['code' => 201, 'message' => '模型不存在']);
                return;
            }
            $status = (int) ($row['status'] ?? 1) === 1 ? 0 : 1;
            db('openai_models')->where('id', $id)->update(['status' => $status]);
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '操作失败: ' . $e->getMessage()]);
            return;
        }

        $this->flushPool();

        show_json([
            'code' => 200,
            'message' => $status === 1 ? '模型已启用' : '模型已停用',
            'data' => ['id' => $id, 'status' => $status],
        ]);
    }

    /**
     * 从供应商接口拉取模型清单 (只返回不入库，由后台勾选后再导入)
     *
     * 走 getClient()->models()->list() 而非 app('openai')->models()，
     * 既绕开用量埋点(管理动作不该计入看板)，也能精确指定拉哪个账号
     */
    public function modelsFetch() {

        $provider = (string) input('post.provider', '', 'trim');
        $account = (string) input('post.account', '', 'trim');
        if ($provider === '') {
            show_json(['code' => 201, 'message' => '请选择供应商']);
            return;
        }

        try {
            $response = app('openai')->getClient($provider, $account)->models()->list();
            $list = (array) ($response->toArray()['data'] ?? []);
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '拉取失败: ' . $e->getMessage()]);
            return;
        }

        $existing = [];
        try {
            foreach (db('openai_models')->where('provider', $provider)->select(['model'])->get() as $r) {
                $existing[(string) (is_object($r) ? $r->model : $r['model'])] = true;
            }
        } catch (Throwable) {
            // 表不可用时全部按未入库处理
        }

        $models = [];
        foreach ($list as $item) {
            $id = trim((string) (is_array($item) ? ($item['id'] ?? '') : (is_object($item) ? ($item->id ?? '') : '')));
            if ($id === '') {
                continue;
            }
            $models[] = ['model' => $id, 'exists' => isset($existing[$id])];
        }

        show_json([
            'code' => 200,
            'message' => "从 {$provider} 拉取到 " . count($models) . ' 个模型',
            'data' => ['provider' => $provider, 'models' => $models],
        ]);
    }

    /**
     * 批量导入拉取到的模型 (已存在的跳过，不覆盖后台改过的优先级与备注)
     * @log
     */
    public function modelsImport() {

        $provider = (string) input('post.provider', '', 'trim');
        $models = input('post.models', []);
        $priority = max(0, min(9999, (int) input('post.priority', 100, 'intval')));
        $remark = mb_substr((string) input('post.remark', '', 'trim'), 0, 500);

        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $provider)) {
            show_json(['code' => 201, 'message' => '无效的供应商标识']);
            return;
        }
        if (is_string($models)) {
            $decoded = json_decode($models, true);
            $models = is_array($decoded) ? $decoded : array_filter(array_map('trim', preg_split('/[,\n\r]+/', $models)));
        }
        $models = array_values(array_filter(array_map(fn ($v) => trim((string) $v), (array) $models), fn ($v) => $v !== '' && !preg_match('/\s/', $v)));
        if (empty($models)) {
            show_json(['code' => 201, 'message' => '请选择要导入的模型']);
            return;
        }

        $existing = [];
        try {
            foreach (db('openai_models')->where('provider', $provider)->select(['model'])->get() as $r) {
                $existing[(string) (is_object($r) ? $r->model : $r['model'])] = true;
            }
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '导入失败: ' . $e->getMessage()]);
            return;
        }

        $rows = [];
        foreach (array_unique($models) as $model) {
            if (isset($existing[$model]) || mb_strlen($model) > 128) {
                continue;
            }
            $rows[] = [
                'uniqid' => random(16),
                'provider' => $provider,
                'model' => $model,
                'priority' => $priority,
                'remark' => $remark,
                'source' => 'pulled',
                'status' => 1,
            ];
        }

        if (empty($rows)) {
            show_json(['code' => 200, 'message' => '所选模型均已存在, 没有需要导入的']);
            return;
        }

        try {
            db('openai_models')->insert($rows);
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '导入失败: ' . $e->getMessage()]);
            return;
        }

        $this->flushPool();

        show_json([
            'code' => 200,
            'message' => '已导入 ' . count($rows) . ' 个模型, 跳过 ' . (count($models) - count($rows)) . ' 个',
            'data' => ['imported' => count($rows)],
        ]);
    }

    /**
     * 在线调试模型 (直接测试模型连通性、供应商路由与输出表现)
     * @log
     */
    public function modelTest() {

        $model = (string) input('post.model', '', 'trim');
        $provider = (string) input('post.provider', '', 'trim');
        $prompt = (string) input('post.prompt', '', 'trim');
        $system = (string) input('post.system', '', 'trim');
        $temperature = (float) input('post.temperature', 0.7);
        $maxTokens = (int) input('post.max_tokens', 2000);

        if ($model === '') {
            show_json(['code' => 201, 'message' => '请指定测试模型名称']);
            return;
        }

        if ($prompt === '') {
            $prompt = '你好！请回复一句话，确认模型接口工作正常并输出你的模型名称。';
        }

        $messages = [];
        if ($system !== '') {
            $messages[] = ['role' => 'system', 'content' => $system];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'temperature' => max(0, min(2, $temperature)),
            'max_tokens' => max(1, $maxTokens),
        ];

        if ($provider !== '') {
            $payload['provider'] = $provider;
        }

        $startedAt = microtime(true);
        try {
            $response = app('openai')->chat()->create($payload);
            $duration = (int) ((microtime(true) - $startedAt) * 1000);
            $array = $response->toArray();

            $choice = $array['choices'][0] ?? [];
            $reasoning = $choice['message']['reasoning_content'] ?? '';
            $content = $choice['message']['content'] ?? ($choice['text'] ?? '');

            $fullText = $reasoning !== ''
                ? "【思考/推理过程】\n{$reasoning}\n\n【回答输出】\n{$content}"
                : (is_string($content) ? $content : json_encode($content, JSON_UNESCAPED_UNICODE));

            show_json([
                'code' => 200,
                'message' => '模型调用成功',
                'data' => [
                    'duration_ms' => $duration,
                    'provider' => $provider !== '' ? $provider : app('openai')->resolveProvider(null, $model),
                    'model' => $model,
                    'text' => $fullText,
                    'usage' => $array['usage'] ?? [],
                    'choices' => $array['choices'] ?? [],
                    'raw' => $array,
                ],
            ]);
        } catch (Throwable $e) {
            isJsonException($e);
            $duration = (int) ((microtime(true) - $startedAt) * 1000);
            show_json([
                'code' => 201,
                'message' => '模型调用失败: ' . $e->getMessage(),
                'data' => [
                    'duration_ms' => $duration,
                    'error' => $e->getMessage(),
                ],
            ]);
        }
    }

    /**
     * 账号池里有启用账号的 provider 集合
     *
     * @return array<string,true>
     */
    private function accountProviders(): array {

        try {
            $route = (new Pool($this->container))->read();
        } catch (Throwable) {
            return [];
        }

        $ready = [];
        foreach ((array) ($route['accounts'] ?? []) as $provider => $accounts) {
            foreach ((array) $accounts as $account) {
                // 文件兜底的账号没有 name，只有表里的账号才算"后台已配置"
                if (!empty($account['from_db'])) {
                    $ready[(string) $provider] = true;
                    break;
                }
            }
        }
        return $ready;
    }

    /**
     * 重建路由缓存，使模型映射改动约 1 秒内在全 worker 生效
     */
    private function flushPool(): void {

        try {
            (new Pool($this->container))->rebuild();
        } catch (Throwable) {
            // 重建失败时缓存文件保持原样，下次 read() 会自动重试
        }
    }
}
