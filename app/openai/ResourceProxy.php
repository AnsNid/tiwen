<?php

declare(strict_types=1);

namespace App\openai;

/**
 * Resource proxy to route calls to the appropriate provider client based on model/provider hints.
 *
 * 这里也是全站 LLM 调用的唯一收口点：所有 app('openai')->xxx()->yyy() 都经过 __call，
 * 因此看板的用量埋点落在这一层，覆盖各业务模块的直接调用(不只是 Task::run)。
 */
final class ResourceProxy {

    /**
     * 不计入用量的资源：后台「拉取模型」会频繁调 models()->list()，属于管理动作
     */
    private const UNSTRACKED_RESOURCES = ['models'];

    private MultiProviderClient $router;
    private string $resourceName;

    public function __construct(MultiProviderClient $router, string $resourceName) {
        $this->router = $router;
        $this->resourceName = $resourceName;
    }

    public function __call(string $method, array $arguments) {

        $explicitProvider = null;
        $model = null;

        if (isset($arguments[0])) {
            $first = $arguments[0];
            if (is_array($first)) {
                // provider hint via special key
                if (isset($first['provider']) && is_string($first['provider'])) {
                    $explicitProvider = $first['provider'];
                } elseif (isset($first['x_provider']) && is_string($first['x_provider'])) {
                    $explicitProvider = $first['x_provider'];
                }
                // model name for routing
                if (isset($first['model']) && $first['model']) {
                    // 模型名称可能是数组,随机返回一个
                    if (is_array($first['model'])) {
                        $model = count($first['model']) > 1 ? $first['model'][array_rand($first['model'])] : reset($first['model']);
                    } else {
                        $model = $first['model'];
                    }
                }
            } elseif (is_string($first)) {
                // Some endpoints accept model as first param (future-proof)
                $model = $first;
            }
        }
        $provider = $this->router->resolveProvider($explicitProvider, $model);
        $account = $this->router->pickAccountName($provider);
        $client = $this->router->getClient($provider, $account);
        $resource = $client->{$this->resourceName}();

        if (isset($arguments[0]) && is_array($arguments[0])) {
            // provider / x_provider 仅用于本地路由，剥离后避免透传给上游
            // （yunwu 等网关会严格校验 body 字段类型，多余的 provider 字符串会触发类型不匹配错误）
            unset($arguments[0]['provider'], $arguments[0]['x_provider']);

            // 模型名称原本可能是数组（随机选一个），路由后规范化为实际选中的字符串
            if (isset($arguments[0]['model']) && is_array($arguments[0]['model']) && isset($model) && is_string($model)) {
                $arguments[0]['model'] = $model;
            }
        }

        $startedAt = microtime(true);
        try {
            $result = $resource->$method(...$arguments);
        } catch (\Throwable $e) {
            $this->track($provider, $account, $model, [], $startedAt, $e->getMessage());
            throw $e;
        }

        $this->track($provider, $account, $model, $this->extractUsage($result), $startedAt, '');
        return $result;
    }

    /**
     * 落看板日聚合。埋点失败绝不影响主流程
     */
    private function track(string $provider, string $account, mixed $model, array $usage, float $startedAt, string $error): void {

        if (in_array($this->resourceName, self::UNSTRACKED_RESOURCES, true)) {
            return;
        }

        try {
            $fields = [
                'total' => 1,
                'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
                'duration_ms' => max(0, (int) ((microtime(true) - $startedAt) * 1000)),
            ];
            $fields[$error === '' ? 'success' : 'failed'] = 1;

            m('openai.StatsDaily')->record(
                $provider,
                $account,
                is_string($model) ? $model : '',
                $fields
            );
        } catch (\Throwable) {
            // 表未建或写入异常时静默跳过
        }
    }

    /**
     * 从响应对象取 usage
     *
     * 流式响应(createStreamed)返回的是 StreamResponse，usage 在最后一个 chunk 且需上游
     * 支持 stream_options.include_usage，此处拿不到，token 记 0，调用次数与耗时仍然准确。
     *
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
     */
    private function extractUsage(mixed $result): array {

        $usage = [];
        if (is_object($result) && property_exists($result, 'usage')) {
            $raw = $result->usage;
            if (is_object($raw) && method_exists($raw, 'toArray')) {
                $raw = $raw->toArray();
            }
            if (is_array($raw)) {
                $usage = $raw;
            }
        }

        $prompt = (int) ($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? $usage['output_tokens'] ?? 0);
        $total = (int) ($usage['total_tokens'] ?? 0);

        return [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            // 部分网关不回 total_tokens，用输入输出补齐
            'total_tokens' => $total > 0 ? $total : $prompt + $completion,
        ];
    }
}
