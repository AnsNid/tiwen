<?php

declare(strict_types=1);

namespace App\openai;

use Psr\Container\ContainerInterface;

/**
 * 供应商账号池与模型路由表的读取/缓存层
 *
 * 数据来源优先级：openai_providers / openai_models 表 → config 表覆盖(openai_providers JSON)
 * → Config/config.php 文件与 env。表里某个 provider 没有启用账号时，逐个 provider 回退到
 * 文件配置，保证升级后存量密钥继续可用、表没建好时线上调用不受影响。
 *
 * 缓存走 cache_read/cache_write(runtime/config/openai_pool.php)：文件跨 worker 共享，
 * 内存 TTL 1 秒，后台保存后调用 rebuild() 全 worker 约 1 秒内生效，无需重启。
 */
final class Pool {

    private const CACHE_NAME = 'openai_pool';

    /**
     * 表不可用时的回源节流：期间沿用缓存里的文件配置，避免每次 LLM 调用都打库并抛异常。
     * 节流到期后会再试一次，因此手工建完表无需重启即可自动接管。
     */
    private const DB_RETRY_INTERVAL = 60;

    private static float $dbFailedAt = 0;

    private ContainerInterface $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
    }

    /**
     * 读取生效的路由配置
     *
     * @return array{accounts:array<string,array<int,array>>,models:array<string,string>,prefixes:array<string,string>,default:string,db_ok:bool}
     */
    public function read(): array {
        $cached = cache_read(self::CACHE_NAME);
        if (is_array($cached) && isset($cached['accounts'])) {
            if (!empty($cached['db_ok'])) {
                return $cached;
            }
            // 缓存里是"表不可用"时的文件兜底：节流窗口内不再回源
            if (self::$dbFailedAt !== 0.0 && self::$dbFailedAt + self::DB_RETRY_INTERVAL > microtime(true)) {
                return $cached;
            }
        }
        return $this->rebuild();
    }

    /**
     * 从数据源重建并落盘缓存(后台保存账号/模型后调用)
     */
    public function rebuild(): array {
        $pool = $this->build();
        cache_write(self::CACHE_NAME, $pool, null, 'There is openai provider pool');
        return $pool;
    }

    private function build(): array {
        [$fileProviders, $fileDefault] = $this->configuredProviders();

        $dbAccounts = [];
        $dbModels = ['exact' => [], 'prefix' => []];
        $dbOk = false;

        try {
            $dbAccounts = $this->loadAccounts();
            $dbModels = $this->loadModels();
            $dbOk = true;
            self::$dbFailedAt = 0;
        } catch (\Throwable) {
            self::$dbFailedAt = microtime(true);
        }

        $accounts = $dbAccounts;
        foreach ($fileProviders as $provider => $config) {
            // 表里已有该 provider 的启用账号则以表为准，否则用文件配置兜底
            if (empty($accounts[$provider])) {
                $accounts[$provider] = [$this->toAccount('', $config, 100, false)];
            }
        }

        $default = $this->configuredDefault($fileDefault);
        if (!isset($accounts[$default])) {
            $default = (string) (array_key_first($accounts) ?: 'openai');
        }

        // 文件 model_provider_map 作为兜底，表里的同名规则覆盖之
        [$fileExact, $filePrefix] = $this->splitMap((array) ($this->fileConfig()['model_provider_map'] ?? []));

        return [
            'accounts' => $accounts,
            'models' => array_merge($fileExact, $dbModels['exact']),
            'prefixes' => array_merge($filePrefix, $dbModels['prefix']),
            'default' => $default,
            'db_ok' => $dbOk,
        ];
    }

    /**
     * 账号池：按 provider 分组，组内已按 priority 升序
     *
     * @return array<string,array<int,array>>
     */
    private function loadAccounts(): array {
        $grouped = [];
        foreach (m('openai.Provider')->actives() as $row) {
            $provider = trim((string) ($row['provider'] ?? ''));
            if ($provider === '') {
                continue;
            }
            $grouped[$provider][] = $this->toAccount(
                (string) ($row['name'] ?? ''),
                $row,
                (int) ($row['priority'] ?? 100),
                true
            );
        }
        return $grouped;
    }

    /**
     * 模型路由表。行已按 priority 升序，只保留每个模型最高优先级的那条
     *
     * @return array{exact:array<string,string>,prefix:array<string,string>}
     */
    private function loadModels(): array {
        $exact = [];
        $prefix = [];
        foreach (m('openai.ProviderModel')->actives() as $row) {
            $model = trim((string) ($row['model'] ?? ''));
            $provider = trim((string) ($row['provider'] ?? ''));
            if ($model === '' || $provider === '') {
                continue;
            }
            if (str_ends_with($model, '*')) {
                $key = substr($model, 0, -1);
                if ($key !== '' && !isset($prefix[$key])) {
                    $prefix[$key] = $provider;
                }
                continue;
            }
            if (!isset($exact[$model])) {
                $exact[$model] = $provider;
            }
        }
        return ['exact' => $exact, 'prefix' => $prefix];
    }

    /**
     * 把文件里的 model => provider 映射拆成精确与前缀两组
     *
     * @param array<string,string> $map
     * @return array{0:array<string,string>,1:array<string,string>}
     */
    private function splitMap(array $map): array {
        $exact = [];
        $prefix = [];
        foreach ($map as $model => $provider) {
            $model = (string) $model;
            $provider = (string) $provider;
            if ($model === '' || $provider === '') {
                continue;
            }
            if (str_ends_with($model, '*')) {
                $key = substr($model, 0, -1);
                if ($key !== '') {
                    $prefix[$key] = $provider;
                }
                continue;
            }
            $exact[$model] = $provider;
        }
        return [$exact, $prefix];
    }

    /**
     * 归一化单条账号配置
     *
     * @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function toAccount(string $name, array $source, int $priority, bool $fromDb): array {
        $timeout = (int) ($source['request_timeout'] ?? 300);
        return [
            'name' => $name,
            'base_uri' => (string) ($source['base_uri'] ?? ''),
            'api_key' => (string) ($source['api_key'] ?? ''),
            'api_key_header' => (string) ($source['api_key_header'] ?? ''),
            'organization' => (string) ($source['organization'] ?? ''),
            'headers' => $this->decodeJson($source['headers'] ?? null),
            'query_params' => $this->decodeJson($source['query_params'] ?? null),
            'request_timeout' => $timeout > 0 ? $timeout : 300,
            'priority' => $priority,
            'from_db' => $fromDb,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 默认供应商：config 表覆盖 > 文件配置
     */
    private function configuredDefault(string $fileDefault): string {
        try {
            $override = $this->sysget('config.openai_default_provider');
        } catch (\Throwable) {
            return $fileDefault;
        }
        return (is_string($override) && $override !== '') ? $override : $fileDefault;
    }

    /**
     * 文件配置叠加 config 表覆盖后的 provider 表(改造前 MultiProviderClient::refreshConfig 的逻辑)
     *
     * 后台「从现有配置导入」也读这里，把升级前生效的 provider 一键落成账号池行。
     *
     * @return array{0:array<string,array>,1:string} [provider => 配置, 默认 provider]
     */
    public function configuredProviders(): array {
        $config = $this->fileConfig();
        $providers = (array) ($config['providers'] ?? []);

        try {
            $override = $this->sysget('config.openai_providers');
            if (is_array($override)) {
                foreach ($override as $name => $fields) {
                    if (!is_array($fields)) {
                        continue;
                    }
                    $provider = is_array($providers[$name] ?? null) ? $providers[$name] : [];
                    foreach (['base_uri', 'api_key', 'organization', 'request_timeout'] as $field) {
                        $value = $fields[$field] ?? null;
                        if ($value !== null && $value !== '') {
                            $provider[$field] = $value;
                        }
                    }
                    $providers[$name] = $provider;
                }
            }
        } catch (\Throwable) {
            // 覆盖读取失败时按文件配置运行
        }

        foreach ($providers as $name => $provider) {
            if (!is_array($provider)) {
                unset($providers[$name]);
            }
        }

        return [$providers, (string) ($config['default_provider'] ?? 'openai')];
    }

    /**
     * @return array<string,mixed>
     */
    private function fileConfig(): array {
        $config = $this->container->get('config')->get('openai@config', []);
        return is_array($config) ? $config : [];
    }

    private function sysget(string $key): mixed {
        return $this->container->get('config')->sysget($key);
    }
}
