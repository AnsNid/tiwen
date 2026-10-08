<?php

declare(strict_types=1);

namespace App\index\Controller;

use xphp\HttpServer\Annotation\AutoController;
use xphp\HttpServer\Annotation\RequestMapping;

#[AutoController('/llm')]

/**
 * @doc LLM 流式输出（SSE）预览 —— 单模型单请求，前端选多模型时各开一条连接并行加载
 */

class Llm extends Authorization {
    /** 默认可选模型（provider 路由由 app/openai 的 model_provider_map 自动解析） */
    private const DEFAULT_MODELS = [
        'gpt-5.6-luna',
        'gpt-5.4-mini',
        'deepseek-v4-flash',
        'gemini-3.6-flash',
    ];

    /**
     * 预览页面 /llm/index 或 /llm
     */
    #[RequestMapping(methods: "get")]
    public function index() {
        return view(app_path('index', 'View') . 'llm.html', [
            'title' => 'LLM SSE 流式输出预览',
        ]);
    }

    /**
     * 模型列表 /llm/models —— 返回默认可选模型 + 已配置供应商清单
     * @return array {code, data: string[], providers: string[]}
     */
    #[RequestMapping(methods: "get")]
    public function models() {
        $providers = array_keys($this->container->get('config')->get('openai@config.providers', []));
        show_json(['code' => 200, 'data' => self::DEFAULT_MODELS, 'providers' => array_values($providers)]);
    }

    /**
     * SSE 流式对话接口 /llm/chat —— 单模型单请求
     * 客户端用 EventSource 连接（GET）；前端选了多个模型时各开一条连接并行加载。
     * @param string $model  模型 id
     * @param string $prompt 用户输入
     * @param string $system 系统提示词（可选）
     */
    #[RequestMapping(methods: "get,post")]
    public function chat() {
        $prompt = trim((string) input('prompt', ''));
        $model = (string) input('model', '');
        $system = trim((string) input('system', ''));

        // SSE 头（首帧 write 时下发），逐帧 write，最后 return '' 让框架只 end() 一次。
        $response = $this->response;
        $response->setHeader('Content-Type', 'text/event-stream;charset=utf-8');
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setHeader('Connection', 'keep-alive');
        $response->setHeader('X-Accel-Buffering', 'no');

        try {
            if ($prompt === '' || $model === '') {
                $response->write($this->sse('fail', ['message' => '请选择模型并输入内容']));
                return '';
            }

            // 读取 provider 配置（base_uri / api_key），直接用 Guzzle 原生流式请求上游，
            // 自己按行解析 SSE —— 绕开 openai-php 的解析器，避免聚合网关非标字段被吞。
            $provider = (string) input('provider', 'apilio');
            $providers = $this->container->get('config')->get('openai@config.providers', []);
            $pcfg = $providers[$provider] ?? [];
            $baseUri = rtrim((string) ($pcfg['base_uri'] ?? ''), '/');
            $apiKey = (string) ($pcfg['api_key'] ?? '');
            if ($baseUri === '') {
                $response->write($this->sse('fail', ['message' => '未配置 provider：' . $provider]));
                return '';
            }
            $endpoint = $baseUri . (str_contains($baseUri, '/v1') ? '/chat/completions' : '/v1/chat/completions');

            $client = guzzle([
                'timeout' => (int) ($pcfg['request_timeout'] ?? 600),
                'connect_timeout' => 10,
                'http_errors' => false,
                'verify' => false,
                'stream' => true,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                ],
            ]);

            $messages = [];
            if ($system !== '') {
                $messages[] = ['role' => 'system', 'content' => $system];
            }
            $messages[] = ['role' => 'user', 'content' => $prompt];

            $up = $client->post($endpoint, [
                'json' => [
                    'model' => $model,
                    'messages' => $messages,
                    'stream' => true,
                ],
            ]);

            if ($up->getStatusCode() !== 200) {
                $response->write($this->sse('fail', [
                    'message' => '上游 HTTP ' . $up->getStatusCode() . '：' . $up->getBody()->getContents(),
                ]));
                return '';
            }

            $response->write($this->sse('meta', [
                'model' => $model,
                'time' => date('Y-m-d H:i:s'),
            ]));

            // 逐行解析上游 SSE
            $body = $up->getBody();
            $buffer = '';
            $emitted = 0;        // 已下发的有效内容字数
            $chunkCount = 0;     // 解析出的 data 帧总数
            $rawSamples = [];    // 无内容 data 帧样本（最多 3 条）
            $rawBytes = '';      // 收到的原始字节（失败时回吐，最多 2KB）
            $finished = false;

            $processLine = function (string $line) use (
                $response, &$emitted, &$chunkCount, &$rawSamples, &$finished
            ) {
                $line = rtrim($line, "\r");
                if ($line === '' || $line[0] === ':') {
                    return;
                }
                if (!str_starts_with($line, 'data:')) {
                    return;
                }
                $data = trim(substr($line, 5));
                if ($data === '[DONE]') {
                    $finished = true;
                    return;
                }
                $json = json_decode($data, true);
                if (!is_array($json)) {
                    return;
                }
                // 上游错误对象
                if (isset($json['error'])) {
                    $response->write($this->sse('fail', [
                        'message' => $json['error']['message'] ?? '上游返回错误',
                    ]));
                    $finished = true;
                    return;
                }
                $chunkCount++;
                $choice = $json['choices'][0] ?? [];
                $delta = $choice['delta'] ?? $choice['message'] ?? [];
                // 兼容 content / reasoning_content / text
                $content = (string) ($delta['content'] ?? $delta['reasoning_content'] ?? $delta['text'] ?? '');
                if ($content !== '') {
                    $emitted += strlen($content);
                    $response->write($this->sse('delta', ['content' => $content]));
                } elseif (count($rawSamples) < 3) {
                    $rawSamples[] = $json;
                }
            };

            while (!$finished && !$body->eof()) {
                $chunk = (string) $body->read(1024);
                if ($chunk === '') {
                    \Swoole\Coroutine::sleep(0.001);
                    continue;
                }
                if (strlen($rawBytes) < 2048) {
                    $rawBytes .= substr($chunk, 0, 2048 - strlen($rawBytes));
                }
                $buffer .= $chunk;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 1);
                    $processLine($line);
                }
            }
            // 收尾：处理缓冲区里最后一行
            if (!$finished && $buffer !== '') {
                $processLine($buffer);
            }

            if ($emitted === 0) {
                $response->write($this->sse('fail', [
                    'message' => '上游未返回任何内容（共 ' . $chunkCount . ' 个 data 帧）',
                    'samples' => $rawSamples,
                    'raw' => $rawBytes,
                ]));
            }

            $response->write($this->sse('done', ['reason' => 'stop']));
        } catch (\TypeError $e) {
            // 个别上游会返回 SDK 未识别字段（如 accepted_prediction_tokens），数据其实已收完
            if (str_contains($e->getMessage(), 'acceptedPredictionTokens')) {
                $response->write($this->sse('done', ['reason' => 'stop']));
            } else {
                $response->write($this->sse('fail', ['message' => $e->getMessage()]));
            }
        } catch (\Throwable $e) {
            // 业务异常以 fail 事件下发（避开 EventSource 内置 error）
            $response->write($this->sse('fail', ['message' => $e->getMessage()]));
        }

        return '';
    }

    /**
     * 构造一个 SSE 事件块：event: <name>\ndata: <json>\n\n
     */
    protected function sse(string $event, array $data): string {
        return "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
