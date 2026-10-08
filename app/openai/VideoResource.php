<?php

declare(strict_types=1);

namespace App\openai;

use xphp\Coroutine\Coroutine;

/**
 * 视频生成资源
 *
 * openai-php SDK 没有内置 video 资源，这里直接基于原始 HTTP 调用，
 * 复用 MultiProviderClient 的多供应商配置（base_uri / api_key / headers）。
 *
 * 典型流程（两步异步）：
 *   1. POST /video/generations   提交任务，返回 task_id
 *   2. GET  /video/tasks/{id}    轮询状态，succeeded 时取 result.videos[0].url
 *
 * @see VideoResource::wait() 封装了“提交 + 轮询到完成”的完整同步等待。
 */
final class VideoResource {

    public function __construct(private MultiProviderClient $router) {
    }

    /**
     * 提交视频生成任务
     *
     * @param array $payload {
     *     @var string      $model    模型名，同时用于供应商路由（model_provider_map）
     *     @var string      $prompt   视频描述
     *     @var string|null $size     分辨率，如 "1280x720"
     *     @var int|null    $duration 时长（秒）
     *     @var string|null $provider 显式指定供应商，覆盖模型路由
     * }
     * @return array { id, status, status_update_time, ... }
     */
    public function create(array $payload): array {
        return $this->request('POST', '/v1/video/create', $payload);
    }

    /**
     * 查询单个任务状态
     *
     * @param string      $taskId   任务 ID
     * @param string|null $provider 任务所属供应商（一般可省略，默认走 default_provider）
     * @return array status: pending | processing | succeeded | failed
     */
    public function task(string $taskId, ?string $provider = null): array {
        $config = $this->router->getProviderConfig($provider, null);
        $query = ['id' => $taskId];
        if (isset($provider) && is_string($provider) && $provider !== '') {
            $query['provider'] = $provider;
        }
        return $this->requestWithConfig('GET', '/v1/video/query', $query, $config);
    }

    /**
     * 同步等待：提交任务后轮询直到 succeeded / failed，返回完整结果。
     *
     * @param array $payload create() 的入参
     * @param int   $interval 轮询间隔（秒），默认 10
     * @param int   $maxAttempts 最大轮询次数，默认 120 次（10s × 120 = 20 分钟上限）
     * @return array succeeded 时包含 videos；failed 时抛异常
     * @throws \RuntimeException 生成失败或超时
     */
    public function wait(array $payload, int $interval = 10, int $maxAttempts = 120): array {
        $created = $this->create($payload);
        $taskId = $created['task_id'] ?? $created['id'] ?? '';
        if ($taskId === '') {
            throw new \RuntimeException('Video create response missing task_id: ' . json_encode($created, JSON_UNESCAPED_UNICODE));
        }
        // 记录提交时用到的供应商，后续查询复用，避免路由到错误的供应商
        $provider = $payload['provider'] ?? null;

        for ($i = 0; $i < $maxAttempts; $i++) {
            // 协程安全的 sleep，不阻塞 worker
            Coroutine::sleep(max($interval, 1));

            $result = $this->task($taskId, $provider);
            $status = $result['status'] ?? '';

            if ($status === 'succeeded' || $status === 'completed') {
                return [
                    'task_id' => $taskId,
                    'status' => 'succeeded',
                    'url' => $this->extractVideoUrl($result),
                    'raw' => $result,
                ];
            }

            if ($status === 'failed') {
                throw new \RuntimeException('Video generation failed: ' . ($result['error']['message'] ?? json_encode($result, JSON_UNESCAPED_UNICODE)));
            }
            // pending | processing 继续轮询
        }

        throw new \RuntimeException('Video generation timeout after ' . ($interval * $maxAttempts) . 's, task_id=' . $taskId);
    }

    /**
     * 从任务结果中提取视频地址，兼容多种供应商的返回结构：
     *   - yunwu:        result.video_url
     *   - OpenAI 风格:  result.result.videos[0].url
     *   - 其他:         result.videos[0].url / result.url
     */
    private function extractVideoUrl(array $result): string {
        // 顶层 video_url（yunwu 等）
        if (!empty($result['video_url']) && is_string($result['video_url'])) {
            return $result['video_url'];
        }
        // videos 数组：可能在顶层、result 下、或 data 下
        $videos = $result['videos']
            ?? $result['result']['videos']
            ?? $result['data']['videos']
            ?? [];
        if (is_array($videos) && !empty($videos)) {
            $first = reset($videos);
            if (is_array($first) && !empty($first['url'])) {
                return (string) $first['url'];
            }
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }
        // 直接给一个 url 字段
        if (!empty($result['url']) && is_string($result['url'])) {
            return $result['url'];
        }
        return '';
    }

    /**
     * 基于路由后的 provider 配置发起请求（POST 场景）。
     */
    private function request(string $method, string $path, array $body): array {
        $config = $this->router->getProviderConfig($body['provider'] ?? null, $body['model'] ?? null);
        return $this->requestWithConfig($method, $path, $body, $config);
    }

    /**
     * 用既定 provider 配置发起请求。
     *
     * @param string                $method HTTP 方法
     * @param string                $path   API 路径
     * @param array                 $data   body（POST）或 query（GET）
     * @param array{base_uri:string,api_key:string,api_key_header?:string,request_timeout?:int,headers?:array} $config
     */
    private function requestWithConfig(string $method, string $path, array $data, array $config): array {

        print_r([
            'method' => $method,
            'path' => $path,
            'data' => $data,
            'config' => $config,
            'headers' => $this->buildHeaders($config),
            'base_uri' => $this->normalizeBaseUri((string) ($config['base_uri'] ?? '')),
            'url' => $this->normalizeBaseUri((string) ($config['base_uri'] ?? '')) . $path,
        ]);



        $client = guzzle([
            'base_uri' => $this->normalizeBaseUri((string) ($config['base_uri'] ?? '')),
            'timeout' => (float) ($config['request_timeout'] ?? 600),
            'verify' => false,
            'http_errors' => false,
            'headers' => $this->buildHeaders($config),
        ]);

        $options = strtoupper($method) === 'GET' ? ['query' => $data] : ['json' => $data];

        $response = $client->request($method, $path, $options);
        $contents = $response->getBody()->getContents();
        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Video API returned non-JSON response: ' . $contents);
        }
        return $decoded;
    }

    /**
     * 组装请求头：Bearer 鉴权（或自定义 header，如 Azure 的 api-key）+ 额外 headers。
     */
    private function buildHeaders(array $config): array {
        $headers = array_merge(
            ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
            (array) ($config['headers'] ?? []),
        );
        $apiKey = (string) ($config['api_key'] ?? '');
        $apiKeyHeader = $config['api_key_header'] ?? null;
        if (is_string($apiKeyHeader) && $apiKeyHeader !== '') {
            $headers[$apiKeyHeader] = $apiKey;
        } else {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }
        return $headers;
    }

    /**
     * 规范化 base_uri：补全 scheme、去掉重复的 /v1（路径里已带）。
     * 兼容 "api.openai.com/v1"、"https://yunwu.ai/"、"https://api.x.com/v1" 等。
     */
    private function normalizeBaseUri(string $baseUri): string {
        if ($baseUri === '') {
            return 'https://api.openai.com';
        }
        $uri = $baseUri;
        if (!preg_match('#^https?://#i', $uri)) {
            $uri = 'https://' . $uri;
        }
        // 路径里统一以 /v1 开头，这里去掉 base 末尾的 /v1 与多余斜杠
        $uri = preg_replace('#/?$#i', '', rtrim($uri, '/'));
        return $uri;
    }
}
