<?php

declare(strict_types=1);

namespace App\openai;

use OpenAI;
use Psr\Container\ContainerInterface;
use xphp\Guzzle\ClientFactory as GuzzleClientFactory;

/**
 * A router client that supports multiple OpenAI-compatible providers and model-based routing.
 *
 * 供应商配置来自 Pool(账号池表 → config 表覆盖 → 文件/env)，同一 provider 下可挂多个账号，
 * 按 priority 升序取第一个启用的账号；模型按 openai_models 的优先级映射到 provider。
 */
final class MultiProviderClient {

    /**
     * 生效的路由表：accounts(provider => 账号列表) / models(精确) / prefixes(前缀) / default
     *
     * @var array{accounts:array<string,array<int,array>>,models:array<string,string>,prefixes:array<string,string>,default:string,db_ok:bool}
     */    private array $route = [
        'accounts' => [
            'openai' => [[
                'name' => '',
                'base_uri' => 'api.openai.com/v1',
                'api_key' => '',
                'api_key_header' => '',
                'organization' => '',
                'headers' => [],
                'query_params' => [],
                'request_timeout' => 300,
                'priority' => 100,
                'from_db' => false,
            ]],
        ],
        'models' => [],
        'prefixes' => [],
        'default' => 'openai',
        'db_ok' => false,
    ];

    /**
     * 前缀规则按长度倒序，保证最具体的前缀优先命中
     *
     * @var array<string,string>|null
     */
    private ?array $prefixRules = null;

    /** @var array<string,\OpenAI\Client> */
    private array $clients = [];

    private ContainerInterface $container;

    private Pool $pool;

    public function __construct(ContainerInterface $container) {

        $this->container = $container;
        $this->pool = new Pool($container);
        $this->refreshRoute();
    }

    /**
     * 重新加载供应商配置。实例经容器 get() 缓存为每 worker 常驻单例，
     * 故在每次使用前懒刷新：Pool 走 cache_read 自带 1 秒内存 TTL，开销可忽略，
     * 后台修改的配置约 1 秒内全 worker 生效，无需重启。
     */
    private function refreshRoute(): void {

        $route = $this->pool->read();

        // 配置有变化才替换；旧 client 持有旧 base_uri/api_key，必须一并丢弃重建
        if ($route === $this->route) {
            return;
        }

        $this->route = $route;
        $this->prefixRules = null;
        $this->clients = [];
    }

    /**
     * Resolve provider by explicit param or model name.
     */
    public function resolveProvider(?string $explicitProvider, ?string $model): string {

        $this->refreshRoute();

        if (is_string($explicitProvider) && $explicitProvider !== '') {
            return $explicitProvider;
        }

        if (is_string($model) && $model !== '') {
            // exact match
            if (isset($this->route['models'][$model])) {
                return $this->route['models'][$model];
            }
            // prefix match
            foreach ($this->prefixPatterns() as $prefix => $provider) {
                if (str_starts_with($model, $prefix)) {
                    return $provider;
                }
            }
        }
        return $this->route['default'];
    }

    /**
     * 选中该 provider 下优先级最高的启用账号(优先级相同时取先入库的，结果稳定)
     *
     * @return array<string,mixed> 含 name/base_uri/api_key 等，provider 不存在时返回空数组
     */
    public function pickAccount(string $provider): array {
        $this->refreshRoute();
        return $this->accountOf($provider, '')[0] ?? [];
    }

    /**
     * 选中账号的名称，供用量埋点区分账号池里的具体账号
     */
    public function pickAccountName(string $provider): string {
        return (string) ($this->pickAccount($provider)['name'] ?? '');
    }

    /**
     * 当前生效的默认供应商
     */
    public function defaultProvider(): string {
        $this->refreshRoute();
        return $this->route['default'];
    }

    /**
     * Get or create an underlying provider client.
     *
     * @param string $account 账号名，空表示该 provider 下优先级最高的账号
     */
    public function getClient(string $provider, string $account = ''): \OpenAI\Client {

        $this->refreshRoute();

        [$config, $resolvedProvider, $resolvedAccount] = $this->accountOf($provider, $account);

        $cacheKey = $resolvedProvider . '#' . $resolvedAccount;
        if (isset($this->clients[$cacheKey])) {
            return $this->clients[$cacheKey];
        }

        $timeout = (float) ($config['request_timeout'] ?? 300);

        $httpClient = $this->container->get(GuzzleClientFactory::class)->create([
            'timeout' => $timeout,
            'verify' => false,
            'http_errors' => true,
        ]);

        $factory = OpenAI::factory()
            ->withBaseUri((string) ($config['base_uri'] ?? 'api.openai.com/v1'))
            ->withHttpHeader('OpenAI-Beta', 'assistants=v2')
            ->withHttpClient($httpClient);

        // Headers
        $headers = (array) ($config['headers'] ?? []);
        // API Key setup: support both standard Bearer and custom header (e.g., Azure api-key)
        $apiKey = $config['api_key'] ?? '';
        $apiKeyHeader = $config['api_key_header'] ?? null; // e.g., 'api-key' for Azure
        if (is_string($apiKeyHeader) && $apiKeyHeader !== '') {
            $factory = $factory->withHttpHeader($apiKeyHeader, (string) $apiKey);
        } else {
            $factory = $factory->withApiKey((string) $apiKey);
        }

        // Organization (optional)
        if (isset($config['organization']) && is_string($config['organization']) && $config['organization'] !== '') {
            $factory = $factory->withOrganization($config['organization']);
        }

        // Additional headers
        foreach ($headers as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $factory = $factory->withHttpHeader($k, (string) $v);
            }
        }

        // Query params
        $queryParams = (array) ($config['query_params'] ?? []);
        foreach ($queryParams as $k => $v) {
            if (is_string($k) && (is_string($v) || is_numeric($v))) {
                $factory = $factory->withQueryParam($k, (string) $v);
            }
        }

        return $this->clients[$cacheKey] = $factory->make();
    }

    /**
     * 定位账号配置：provider 不存在时回退默认 provider，账号名为空或不匹配时取组内第一个
     *
     * @return array{0:array<string,mixed>,1:string,2:string} [账号配置, 实际 provider, 实际账号名]
     */
    private function accountOf(string $provider, string $account): array {

        $accounts = $this->route['accounts'][$provider] ?? [];
        if (!$accounts) {
            // fallback to default
            $provider = $this->route['default'];
            $accounts = $this->route['accounts'][$provider] ?? [];
        }
        if (!$accounts) {
            return [[], $provider, ''];
        }

        if ($account !== '') {
            foreach ($accounts as $item) {
                if ((string) ($item['name'] ?? '') === $account) {
                    return [$item, $provider, $account];
                }
            }
        }

        $first = $accounts[0];
        return [$first, $provider, (string) ($first['name'] ?? '')];
    }

    /**
     * @return array<string,string>
     */
    private function prefixPatterns(): array {

        if ($this->prefixRules !== null) {
            return $this->prefixRules;
        }

        $rules = [];
        foreach ($this->route['prefixes'] as $prefix => $provider) {
            if (is_string($prefix) && $prefix !== '' && is_string($provider) && $provider !== '') {
                $rules[$prefix] = $provider;
            }
        }
        // 长前缀优先，避免 'gpt-*' 抢走 'gpt-4o-*' 的命中
        uksort($rules, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $this->prefixRules = $rules;
    }

    // --- Resource methods ---
    public function assistants() {
        return new ResourceProxy($this, 'assistants');
    }
    public function audio() {
        return new ResourceProxy($this, 'audio');
    }
    public function batches() {
        return new ResourceProxy($this, 'batches');
    }
    public function chat() {
        return new ResourceProxy($this, 'chat');
    }
    public function completions() {
        return new ResourceProxy($this, 'completions');
    }
    public function embeddings() {
        return new ResourceProxy($this, 'embeddings');
    }
    public function edits() {
        return new ResourceProxy($this, 'edits');
    }
    public function responses() {
        return new ResourceProxy($this, 'responses');
    }
    public function files() {
        return new ResourceProxy($this, 'files');
    }
    public function fineTunes() {
        return new ResourceProxy($this, 'fineTunes');
    }
    public function images() {
        return new ResourceProxy($this, 'images');
    }
    public function models() {
        return new ResourceProxy($this, 'models');
    }
    public function moderations() {
        return new ResourceProxy($this, 'moderations');
    }
    public function threads() {
        return new ResourceProxy($this, 'threads');
    }
    public function vectorStores() {
        return new ResourceProxy($this, 'vectorStores');
    }

    /**
     * 视频生成资源（openai-php SDK 无内置 video 资源，走原始 HTTP）。
     */
    public function videos() {
        return new VideoResource($this);
    }

    /**
     * videos() 的单数别名，便于 app('openai')->video()->create() 写法。
     */
    public function video() {
        return $this->videos();
    }

    /**
     * 供 VideoResource 读取路由后的账号配置（base_uri / api_key / headers 等）。
     *
     * 账号选择只取决于优先级配置、不含轮询状态，因此 create 与后续 task 轮询
     * 会稳定命中同一个账号。
     */
    public function getProviderConfig(?string $explicitProvider, ?string $model): array {
        return $this->pickAccount($this->resolveProvider($explicitProvider, $model));
    }
}
