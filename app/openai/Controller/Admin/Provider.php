<?php

declare(strict_types=1);

namespace App\openai\Controller\Admin;

use App\admin\Authorization;
use App\openai\Pool;
use Throwable;

use function xphp\Collection\data_set;

/**
 * 供应商账号池管理
 * 一行 = 一个账号(一把 key)，同一 provider 下的多行构成账号池，调用时按 priority 取最高的启用账号
 */
class Provider extends Authorization {

    /**
     * 账号池列表 (密钥只回掩码，永不返回明文)
     */
    public function accounts() {

        try {
            $rows = db('openai_providers')->orderBy('provider')->orderBy('priority')->orderBy('id')->get();
        } catch (Throwable) {
            show_json(['code' => 201, 'message' => 'openai_providers 表不可用, 请先执行 Install/20260922_add_provider_pool.sql']);
            return;
        }

        $accounts = [];
        $grouped = [];
        foreach ($rows as $r) {
            $r = (array) $r;
            $provider = (string) ($r['provider'] ?? '');
            $apiKey = (string) ($r['api_key'] ?? '');
            $grouped[$provider] = ($grouped[$provider] ?? 0) + 1;
            $accounts[] = [
                'id' => (int) ($r['id'] ?? 0),
                'provider' => $provider,
                'name' => (string) ($r['name'] ?? ''),
                'base_uri' => (string) ($r['base_uri'] ?? ''),
                'has_key' => $apiKey !== '',
                'key_preview' => $this->maskKey($apiKey),
                'api_key_header' => (string) ($r['api_key_header'] ?? ''),
                'organization' => (string) ($r['organization'] ?? ''),
                'headers' => (string) ($r['headers'] ?? ''),
                'query_params' => (string) ($r['query_params'] ?? ''),
                'request_timeout' => (int) ($r['request_timeout'] ?? 300),
                'priority' => (int) ($r['priority'] ?? 100),
                'remark' => (string) ($r['remark'] ?? ''),
                'status' => (int) ($r['status'] ?? 1),
                'updated_at' => (string) ($r['updated_at'] ?? ''),
            ];
        }

        // 表里没有账号、当前仍靠文件/env/config 表兜底的 provider
        $fallback = [];
        try {
            [$configured] = (new Pool($this->container))->configuredProviders();
            foreach ($configured as $provider => $config) {
                if (isset($grouped[$provider])) {
                    continue;
                }
                $fallback[] = [
                    'provider' => (string) $provider,
                    'base_uri' => (string) ($config['base_uri'] ?? ''),
                    'has_key' => (string) ($config['api_key'] ?? '') !== '',
                    'request_timeout' => (int) ($config['request_timeout'] ?? 300),
                ];
            }
        } catch (Throwable) {
            // 兜底信息只用于展示，取不到不影响账号池
        }

        show_json([
            'code' => 200,
            'data' => [
                'default_provider' => app('openai')->defaultProvider(),
                'accounts' => $accounts,
                'fallback' => $fallback,
                'pool_active' => $accounts !== [],
            ],
        ]);
    }

    /**
     * 保存账号 (api_key 留空表示保持原密钥不变)
     * @log
     */
    public function accountSave() {

        $id = (int) input('post.id', 0, 'intval');
        $provider = (string) input('post.provider', '', 'trim');
        $name = (string) input('post.name', '', 'trim');
        $baseUri = (string) input('post.base_uri', '', 'trim');
        $apiKey = (string) input('post.api_key', '', 'trim');

        if (!preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $provider)) {
            show_json(['code' => 201, 'message' => '供应商标识只能含字母/数字/下划线/中划线, 且不超过32位']);
            return;
        }
        if ($baseUri === '' || mb_strlen($baseUri) > 255) {
            show_json(['code' => 201, 'message' => '请填写接口地址(不超过255字符)']);
            return;
        }

        $name = $name !== '' ? mb_substr($name, 0, 64) : $provider;

        $row = [
            'provider' => $provider,
            'name' => $name,
            'base_uri' => $baseUri,
            'api_key_header' => mb_substr((string) input('post.api_key_header', '', 'trim'), 0, 64),
            'organization' => mb_substr((string) input('post.organization', '', 'trim'), 0, 64),
            'headers' => $this->encodeJson(input('post.headers', '')),
            'query_params' => $this->encodeJson(input('post.query_params', '')),
            'request_timeout' => max(1, min(3600, (int) input('post.request_timeout', 300, 'intval'))),
            'priority' => max(0, min(9999, (int) input('post.priority', 100, 'intval'))),
            'remark' => mb_substr((string) input('post.remark', '', 'trim'), 0, 500),
            'status' => (int) input('post.status', 1, 'intval') === 1 ? 1 : 0,
        ];

        try {
            // 同 provider 下账号名不可重复: 看板按 (provider, account) 维度聚合，重名会把两个账号的用量并到一起
            $duplicateQuery = db('openai_providers')->where('provider', $provider)->where('name', $name);
            if ($id > 0) {
                $duplicateQuery->where('id', '<>', $id);
            }
            if (!empty($duplicateQuery->first())) {
                show_json(['code' => 201, 'message' => "供应商 {$provider} 下已存在同名账号「{$name}」"]);
                return;
            }

            if ($id > 0) {
                $exists = db('openai_providers')->where('id', $id)->first();
                if (empty($exists)) {
                    show_json(['code' => 201, 'message' => '账号不存在']);
                    return;
                }
                // 密钥留空表示保持不变
                if ($apiKey !== '') {
                    $row['api_key'] = $apiKey;
                }
                db('openai_providers')->where('id', $id)->update($row);
            } else {
                if ($apiKey === '') {
                    show_json(['code' => 201, 'message' => '请填写 API Key']);
                    return;
                }
                $row['api_key'] = $apiKey;
                $row['uniqid'] = random(16);
                db('openai_providers')->insert($row);
            }
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '保存失败: ' . $e->getMessage()]);
            return;
        }

        $this->flushPool();

        show_json(['code' => 200, 'message' => '账号已保存, 约 1 秒内全 worker 生效']);
    }

    /**
     * 删除账号
     * @log
     */
    public function accountDelete() {

        $id = (int) input('post.id', 0, 'intval');
        if ($id <= 0) {
            show_json(['code' => 201, 'message' => '请选择账号']);
            return;
        }

        try {
            $deleted = db('openai_providers')->where('id', $id)->delete();
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '删除失败: ' . $e->getMessage()]);
            return;
        }

        if (!$deleted) {
            show_json(['code' => 201, 'message' => '账号不存在']);
            return;
        }

        $this->flushPool();

        show_json(['code' => 200, 'message' => '账号已删除']);
    }

    /**
     * 启用/停用账号 (停用后立即从路由中摘除，回退到同 provider 的下一优先级账号)
     * @log
     */
    public function accountToggle() {

        $id = (int) input('post.id', 0, 'intval');
        if ($id <= 0) {
            show_json(['code' => 201, 'message' => '请选择账号']);
            return;
        }

        try {
            $row = (array) db('openai_providers')->where('id', $id)->first();
            if (empty($row['id'])) {
                show_json(['code' => 201, 'message' => '账号不存在']);
                return;
            }
            $status = (int) ($row['status'] ?? 1) === 1 ? 0 : 1;
            db('openai_providers')->where('id', $id)->update(['status' => $status]);
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '操作失败: ' . $e->getMessage()]);
            return;
        }

        $this->flushPool();

        show_json([
            'code' => 200,
            'message' => $status === 1 ? '账号已启用' : '账号已停用',
            'data' => ['id' => $id, 'status' => $status],
        ]);
    }

    /**
     * 设为默认供应商 (未匹配到模型映射时使用)
     * @log
     */
    public function defaultSave() {

        $provider = (string) input('post.provider', '', 'trim');
        if ($provider === '' || !preg_match('/^[a-zA-Z0-9_-]{1,32}$/', $provider)) {
            show_json(['code' => 201, 'message' => '无效的供应商标识']);
            return;
        }

        try {
            db('config')->upsert([['name' => 'openai_default_provider', 'value' => $provider]], 'name', ['value']);
            $this->refreshConfigCache();
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '保存失败: ' . $e->getMessage()]);
            return;
        }

        $this->flushPool();

        show_json(['code' => 200, 'message' => "默认供应商已设为 {$provider}"]);
    }

    /**
     * 把当前生效的文件/env/config 表 provider 配置一键落成账号池行 (已有账号的 provider 跳过)
     * @log
     */
    public function importFromConfig() {

        try {
            [$configured] = (new Pool($this->container))->configuredProviders();
            $existing = [];
            foreach (db('openai_providers')->select(['provider'])->get() as $r) {
                $existing[(string) (is_object($r) ? $r->provider : $r['provider'])] = true;
            }
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '导入失败: ' . $e->getMessage()]);
            return;
        }

        $rows = [];
        foreach ($configured as $provider => $config) {
            if (isset($existing[$provider])) {
                continue;
            }
            $rows[] = [
                'uniqid' => random(16),
                'provider' => (string) $provider,
                'name' => (string) $provider,
                'base_uri' => (string) ($config['base_uri'] ?? ''),
                'api_key' => (string) ($config['api_key'] ?? ''),
                'api_key_header' => (string) ($config['api_key_header'] ?? ''),
                'organization' => (string) ($config['organization'] ?? ''),
                'headers' => !empty($config['headers']) ? (string) json_encode($config['headers'], JSON_UNESCAPED_UNICODE) : '',
                'query_params' => !empty($config['query_params']) ? (string) json_encode($config['query_params'], JSON_UNESCAPED_UNICODE) : '',
                'request_timeout' => (int) ($config['request_timeout'] ?? 300),
                'priority' => 100,
                'remark' => '从现有配置导入',
                'status' => (string) ($config['api_key'] ?? '') !== '' ? 1 : 0,
            ];
        }

        if (empty($rows)) {
            show_json(['code' => 200, 'message' => '没有可导入的供应商 (均已存在账号或无配置)']);
            return;
        }

        try {
            db('openai_providers')->insert($rows);
        } catch (Throwable $e) {
            show_json(['code' => 201, 'message' => '导入失败: ' . $e->getMessage()]);
            return;
        }

        $this->flushPool();

        show_json([
            'code' => 200,
            'message' => '已导入 ' . count($rows) . ' 个供应商账号 (无密钥的已置为停用)',
            'data' => ['imported' => count($rows), 'providers' => array_column($rows, 'provider')],
        ]);
    }

    /**
     * 密钥掩码: 只保留首3位与末4位
     */
    private function maskKey(string $key): string {

        if ($key === '') {
            return '';
        }
        $length = strlen($key);
        return $length <= 8 ? str_repeat('*', $length) : substr($key, 0, 3) . '****' . substr($key, -4);
    }

    /**
     * headers / query_params 统一存 JSON 字符串
     */
    private function encodeJson(mixed $value): string {

        if (is_array($value)) {
            return $value ? (string) json_encode($value, JSON_UNESCAPED_UNICODE) : '';
        }
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? (string) json_encode($decoded, JSON_UNESCAPED_UNICODE) : '';
    }

    /**
     * 重建账号池缓存，使改动约 1 秒内在全 worker 生效
     */
    private function flushPool(): void {

        try {
            (new Pool($this->container))->rebuild();
        } catch (Throwable) {
            // 重建失败时缓存文件保持原样，下次 read() 会自动重试
        }
    }

    /**
     * 从 config 表全量重建 config 缓存 (与系统设置保存逻辑一致)
     */
    private function refreshConfigCache(): void {

        $list = db('config')->select(['name', 'value'])->get();
        $config = [];
        foreach ($list as $value) {
            if (is_string($value->value) && json_validate($value->value)) {
                $value->value = json_decode($value->value, true);
            }
            data_set($config, $value->name, $value->value);
        }
        cache_write('config', $config);
    }
}
