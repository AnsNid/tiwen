<?php

declare(strict_types=1);

namespace App\tiwen\Service;

use xphp\HttpServer\Contract\ResponseInterface;

/**
 * 多模型对话服务
 * 上游 SSE 流式代理:逐行解析、逐帧下发;支持会话上下文携带与回答卡片落库
 */
class ChatService {
    public function __construct(private SessionService $sessions = new SessionService(), private MemberService $members = new MemberService()) {
    }

    /**
     * 顶尖文本对话模型清单(与前端 ModelDetail 字段对齐,剔除文生图模型)
     */
    public function modelList(): array {
        return [
            ['id' => 'deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash', 'iconColor' => 'from-indigo-500 to-blue-600', 'badge' => 'Flash', 'desc' => '深度求索 V4 Flash 极速模型，代码生成与日常问答高效', 'cat' => '开源极速', 'provider' => 'apilio'],
            ['id' => 'deepseek-v4-pro', 'name' => 'DeepSeek V4 Pro', 'iconColor' => 'from-blue-600 to-indigo-700', 'badge' => 'Pro', 'desc' => '深度求索 V4 Pro 专业版，复杂推理与代码分析能力顶级', 'cat' => '深度推理', 'provider' => 'apilio'],
            ['id' => 'gpt-5-mini', 'name' => 'GPT-5 Mini', 'iconColor' => 'from-slate-600 to-gray-800', 'badge' => 'Mini', 'desc' => 'OpenAI 轻量高效模型，极速响应适合日常对话', 'cat' => '开源极速', 'provider' => 'apilio'],
            // ['id' => 'gpt-5.6-luna', 'name' => 'GPT-5.6 Luna', 'iconColor' => 'from-slate-700 to-slate-900', 'badge' => 'Luna', 'desc' => 'OpenAI 最新旗舰模型 Luna 版本，多模态综合能力卓越', 'cat' => '通用旗舰', 'provider' => 'apilio'],
            ['id' => 'gpt-5.6-sol', 'name' => 'GPT-5.6 Sol', 'iconColor' => 'from-slate-700 to-slate-900', 'badge' => 'Sol', 'desc' => 'OpenAI 旗舰模型 Sol 版本，深度推理与长文本能力突出', 'cat' => '深度推理', 'provider' => 'apilio'],
            ['id' => 'gemini-3.1-pro-preview', 'name' => 'Gemini 3.1 Pro Preview', 'iconColor' => 'from-teal-500 to-cyan-600', 'badge' => 'Pro Preview', 'desc' => 'Google 深度算力预览版模型，超长上下文与代码解析能力', 'cat' => '多模态', 'provider' => 'apilio'],
            ['id' => 'gemini-3.1-flash-lite-preview', 'name' => 'Gemini 3.1 Flash Lite Preview', 'iconColor' => 'from-blue-500 to-indigo-600', 'badge' => 'Flash Lite', 'desc' => 'Google 多模态极速轻量预览版模型，低延迟响应', 'cat' => '多模态', 'provider' => 'apilio'],
            ['id' => 'claude-opus-5', 'name' => 'Claude Opus 5', 'iconColor' => 'from-amber-600 to-orange-500', 'badge' => 'Opus 5', 'desc' => 'Anthropic 旗舰 Opus 5，顶级深度推理与严谨逻辑分析', 'cat' => '深度推理', 'provider' => 'apilio'],
            ['id' => 'claude-fable-5', 'name' => 'Claude Fable 5', 'iconColor' => 'from-amber-500 to-yellow-500', 'badge' => 'Fable', 'desc' => 'Anthropic Fable 5 创意生成模型，文案创作与叙事能力出色', 'cat' => '创意生成', 'provider' => 'apilio'],
            ['id' => 'claude-opus-4-7', 'name' => 'Claude Opus 4.7', 'iconColor' => 'from-orange-600 to-red-500', 'badge' => 'Opus 4.7', 'desc' => 'Anthropic Opus 4.7 经典旗舰，稳定可靠的高阶推理能力', 'cat' => '深度推理', 'provider' => 'apilio'],
            ['id' => 'grok-4.5', 'name' => 'Grok 4.5', 'iconColor' => 'from-slate-800 to-zinc-900', 'badge' => '4.5', 'desc' => 'xAI Grok 4.5 实时搜索模型，最新资讯抓取与犀利分析', 'cat' => '实时资讯', 'provider' => 'apilio'],
            ['id' => 'grok-4.2', 'name' => 'Grok 4.2', 'iconColor' => 'from-zinc-700 to-slate-900', 'badge' => '4.2', 'desc' => 'xAI Grok 4.2 实时对话模型，极速响应与性格化表达', 'cat' => '实时资讯', 'provider' => 'apilio'],
            ['id' => 'glm-5.1', 'name' => 'GLM 5.1', 'iconColor' => 'from-emerald-500 to-green-600', 'badge' => '5.1', 'desc' => '智谱 AI GLM 5.1 旗舰模型，中文理解与逻辑推理领先', 'cat' => '通用旗舰', 'provider' => 'apilio'],
            ['id' => 'glm-5-turbo', 'name' => 'GLM 5 Turbo', 'iconColor' => 'from-green-500 to-teal-600', 'badge' => 'Turbo', 'desc' => '智谱 AI GLM 5 Turbo 极速版，低延迟高吞吐日常对话首选', 'cat' => '开源极速', 'provider' => 'apilio'],
            ['id' => 'kimi-k2.5', 'name' => 'Kimi K2.5', 'iconColor' => 'from-rose-500 to-red-600', 'badge' => 'K2.5', 'desc' => '月之暗面 Kimi K2.5，超长上下文文档处理与深度研读', 'cat' => '长文本', 'provider' => 'apilio'],
            ['id' => 'kimi-k2.6', 'name' => 'Kimi K2.6', 'iconColor' => 'from-red-500 to-orange-600', 'badge' => 'K2.6', 'desc' => '月之暗面 Kimi K2.6 最新版，更强的推理与多模态理解能力', 'cat' => '长文本', 'provider' => 'apilio'],
            ['id' => 'MiniMax-M2.7', 'name' => 'MiniMax M2.7', 'iconColor' => 'from-fuchsia-500 to-pink-600', 'badge' => 'M2.7', 'desc' => 'MiniMax 最新通用大模型，多模态理解与生成能力均衡', 'cat' => '通用旗舰', 'provider' => 'apilio'],
            ['id' => 'MiniMax-M2.5', 'name' => 'MiniMax M2.5', 'iconColor' => 'from-purple-500 to-fuchsia-600', 'badge' => 'M2.5', 'desc' => 'MiniMax M2.5 高效通用模型，响应速度与质量兼顾', 'cat' => '通用旗舰', 'provider' => 'apilio'],
        ];
    }

    /**
     * 模型名称映射
     */
    public function modelName(string $modelId): string {
        foreach ($this->modelList() as $m) {
            if ($m['id'] === $modelId) {
                return (string) $m['name'];
            }
        }
        return $modelId;
    }

    /**
     * 解析供应商：返回 [base_uri, api_key, provider_name]
     * 顺序：传入 provider → 模型清单声明 → model_provider_map 通配 → 默认 provider
     */
    public function resolveProvider(string $model, string $provider = ''): array {
        $config = xphp('config');
        $providers = $config->get('openai@config.providers', []);
        $map = $config->get('openai@config.model_provider_map', []);

        // ① 传入的 provider 优先
        if ($provider !== '' && isset($providers[$provider])) {
            $p = $providers[$provider];
            $baseUri = rtrim((string) ($p['base_uri'] ?? ''), '/');
            if ($baseUri !== '') {
                return [$baseUri, (string) ($p['api_key'] ?? ''), $provider];
            }
        }

        // ② 模型清单中声明的 provider
        $providerName = '';
        foreach ($this->modelList() as $m) {
            if (($m['id'] ?? '') === $model && !empty($m['provider']) && isset($providers[$m['provider']])) {
                $providerName = (string) $m['provider'];
                break;
            }
        }

        // ③ model_provider_map 通配匹配
        if ($providerName === '') {
            foreach ($map as $pattern => $name) {
                if ($this->matchModel((string) $pattern, $model)) {
                    $providerName = (string) $name;
                    break;
                }
            }
        }

        // ④ 默认 provider
        if ($providerName === '') {
            $providerName = (string) $config->get('openai@config.default_provider', 'openai');
        }

        if (isset($providers[$providerName])) {
            $p = $providers[$providerName];
            $baseUri = rtrim((string) ($p['base_uri'] ?? ''), '/');
            if ($baseUri !== '') {
                return [$baseUri, (string) ($p['api_key'] ?? ''), $providerName];
            }
        }

        return ['', '', ''];
    }

    /**
     * 模型通配匹配：以 * 结尾做前缀匹配，否则全等
     */
    protected function matchModel(string $pattern, string $model): bool {
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($model, substr($pattern, 0, -1));
        }
        return $pattern === $model;
    }

    /**
     * SSE 流式对话(单模型单连接)
     * 前端选多模型时各开一条连接并行比对;带 session/round 时流完成后落库并核算用量
     *
     * @param array $opts model prompt system temperature provider messages(额外上下文) uid session_id round_id
     */
    public function stream(ResponseInterface $response, array $opts): void {
        $model = (string) ($opts['model'] ?? '');
        $prompt = trim((string) ($opts['prompt'] ?? ''));
        $system = trim((string) ($opts['system'] ?? ''));
        $temperature = $opts['temperature'] ?? null;
        $provider = (string) ($opts['provider'] ?? '');
        $uid = (int) ($opts['uid'] ?? 0);
        $sessionId = (int) ($opts['session_id'] ?? 0);
        $roundId = (int) ($opts['round_id'] ?? 0);

        // SSE 头(首帧 write 时下发),逐帧 write,最后由控制器 return '' 让框架只 end() 一次。
        $response->setHeader('Content-Type', 'text/event-stream;charset=utf-8');
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setHeader('Connection', 'keep-alive');
        $response->setHeader('X-Accel-Buffering', 'no');

        try {
            if ($prompt === '' || $model === '') {
                $response->write($this->sse('fail', ['message' => '请选择模型并输入内容']));
                return;
            }

            // 解析供应商配置(base_uri / api_key),直接用 Guzzle 原生流式请求上游,
            // 自己按行解析 SSE —— 绕开 openai-php 的解析器,避免聚合网关非标字段被吞。
            [$baseUri, $apiKey, $providerName] = $this->resolveProvider($model, $provider);
            if ($baseUri === '') {
                $response->write($this->sse('fail', ['message' => '未配置可用 provider']));
                return;
            }
            $endpoint = $baseUri . (str_contains($baseUri, '/v1') ? '/chat/completions' : '/v1/chat/completions');

            $messages = [];
            if ($system !== '') {
                $messages[] = ['role' => 'system', 'content' => $system];
            }
            foreach ((array) ($opts['messages'] ?? []) as $msg) {
                if (isset($msg['role'], $msg['content']) && $msg['content'] !== '') {
                    $messages[] = ['role' => (string) $msg['role'], 'content' => (string) $msg['content']];
                }
            }
            $messages[] = ['role' => 'user', 'content' => $prompt];

            $payload = [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
            ];
            if ($temperature !== null && $temperature !== '') {
                $payload['temperature'] = (float) $temperature;
            }

            $client = guzzle([
                'timeout' => 600,
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

            $up = $client->post($endpoint, ['json' => $payload]);

            if ($up->getStatusCode() !== 200) {
                $response->write($this->sse('fail', [
                    'message' => '上游 HTTP ' . $up->getStatusCode() . '：' . mb_substr($up->getBody()->getContents(), 0, 500),
                ]));
                $this->finish($uid, $sessionId, $roundId, $model, '', 0, 0.0, 'failed');
                $this->members->settleRoundStream($uid, $roundId, $model, false);
                return;
            }
            $response->write($this->sse('meta', [
                'model' => $model,
                'model_name' => $this->modelName($model),
                'provider' => $providerName,
                'time' => date('Y-m-d H:i:s'),
            ]));

            // 逐行解析上游 SSE
            $body = $up->getBody();
            $buffer = '';
            $emitted = 0;        // 已下发的有效内容字数
            $chunkCount = 0;     // 解析出的 data 帧总数
            $rawSamples = [];    // 无内容 data 帧样本(最多 3 条)
            $rawBytes = '';      // 收到的原始字节(失败时回吐,最多 2KB)
            $finished = false;
            $usageTokens = 0;    // 上游 usage 里的 total_tokens(若提供)
            $startedAt = microtime(true);
            $fullBody = '';

            $processLine = function (string $line) use (
                $response,
                &$emitted,
                &$chunkCount,
                &$rawSamples,
                &$finished,
                &$usageTokens,
                &$fullBody
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
                // 部分网关在末帧携带 usage
                if (!empty($json['usage']['total_tokens'])) {
                    $usageTokens = (int) $json['usage']['total_tokens'];
                }
                $chunkCount++;
                $choice = $json['choices'][0] ?? [];
                $delta = $choice['delta'] ?? $choice['message'] ?? [];
                // 兼容 content / reasoning_content / text
                $content = (string) ($delta['content'] ?? $delta['reasoning_content'] ?? $delta['text'] ?? '');
                if ($content !== '') {
                    $emitted += strlen($content);
                    $fullBody .= $content;
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
                $this->finish($uid, $sessionId, $roundId, $model, '', 0, 0.0, 'failed');
                $this->members->settleRoundStream($uid, $roundId, $model, false);
                return;
            }

            $duration = microtime(true) - $startedAt;
            $tokens = $usageTokens > 0 ? $usageTokens : $this->estimateTokens($fullBody);

            $response->write($this->sse('usage', [
                'tokens' => $tokens,
                'duration' => round($duration, 1),
            ]));
            $card = $this->finish($uid, $sessionId, $roundId, $model, $fullBody, $tokens, $duration, 'done');
            // 无会话落库场景(如综合分析)也要核算用户用量
            if ($uid > 0 && ($sessionId <= 0 || $roundId <= 0)) {
                $this->members->recordTokens($uid, $tokens);
            }
            $this->members->settleRoundStream($uid, $roundId, $model, true);
            $response->write($this->sse('done', ['reason' => 'stop', 'card' => $card]));
        } catch (\TypeError $e) {
            // 个别上游会返回 SDK 未识别字段(如 accepted_prediction_tokens),数据其实已收完
            if (str_contains($e->getMessage(), 'acceptedPredictionTokens')) {
                $response->write($this->sse('done', ['reason' => 'stop']));
            } else {
                $response->write($this->sse('fail', ['message' => $e->getMessage()]));
            }
        } catch (\Throwable $e) {
            // 业务异常以 fail 事件下发(避开 EventSource 内置 error)
            $response->write($this->sse('fail', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 非流式对话(内部调用,如综合分析等)
     * @return array{ok: bool, content: string, tokens: int, message: string}
     */
    public function complete(string $model, array $messages, float $temperature = 0.5, string $provider = ''): array {
        [$baseUri, $apiKey, $providerName] = $this->resolveProvider($model, $provider);
        if ($baseUri === '') {
            return ['ok' => false, 'content' => '', 'tokens' => 0, 'message' => '未配置可用 provider'];
        }
        $endpoint = $baseUri . (str_contains($baseUri, '/v1') ? '/chat/completions' : '/v1/chat/completions');
        try {
            $client = guzzle([
                'timeout' => 300,
                'connect_timeout' => 10,
                'http_errors' => false,
                'verify' => false,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);
            $resp = $client->post($endpoint, ['json' => [
                'model' => $model,
                'messages' => $messages,
                'temperature' => $temperature,
            ]]);
            $data = json_decode((string) $resp->getBody(), true);
            if ($resp->getStatusCode() !== 200) {
                return ['ok' => false, 'content' => '', 'tokens' => 0, 'message' => '上游 HTTP ' . $resp->getStatusCode() . '：' . mb_substr((string) ($data['error']['message'] ?? ''), 0, 300)];
            }
            $content = (string) ($data['choices'][0]['message']['content'] ?? '');
            $tokens = (int) ($data['usage']['total_tokens'] ?? 0) ?: $this->estimateTokens($content);
            return ['ok' => true, 'content' => $content, 'tokens' => $tokens, 'message' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'content' => '', 'tokens' => 0, 'message' => $e->getMessage()];
        }
    }

    /**
     * 流结束:落库卡片并核算用户用量(带会话上下文时)
     * @return array 卡片数据(无会话时为空数组)
     */
    private function finish(int $uid, int $sessionId, int $roundId, string $model, string $body, int $tokens, float $duration, string $status): array {
        if ($uid <= 0 || $sessionId <= 0 || $roundId <= 0) {
            return [];
        }
        $cardId = $this->sessions->saveCard($uid, $sessionId, $roundId, $model, $this->modelName($model), $body, $tokens, $duration, $status);
        if ($tokens > 0) {
            $this->members->recordTokens($uid, $tokens);
        }
        return ['cardId' => $cardId, 'cardUid' => 'card_' . $cardId];
    }

    /**
     * Token 估算:中文约 1 字 1.3 token,英文约 4 字符 1 token,取较大者
     */
    private function estimateTokens(string $text): int {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }
        return (int) ceil(max(mb_strlen($text) * 1.3, strlen($text) / 4));
    }

    /**
     * 构造一个 SSE 事件块：event: <name>\ndata: <json>\n\n
     */
    protected function sse(string $event, array $data): string {
        return "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
