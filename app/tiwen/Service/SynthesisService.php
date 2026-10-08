<?php

declare(strict_types=1);

namespace App\tiwen\Service;

use xphp\HttpServer\Contract\ResponseInterface;

/**
 * 分析综合服务
 * 取会话内勾选的多模型卡片,交叉比对生成结构化研报(SSE 流式)
 */
class SynthesisService {

    private const MAX_CARD_CHARS = 3000;

    public function __construct(private SessionService $sessions = new SessionService(), private ChatService $chat = new ChatService()) {
    }

    /**
     * 流式综合分析
     * 结果由前端写入编辑区草稿;Token 用量由 ChatService 统一核算
     */
    public function stream(ResponseInterface $response, int $uid, int $sessionId): void {
        $response->setHeader('Content-Type', 'text/event-stream;charset=utf-8');
        $response->setHeader('Cache-Control', 'no-cache');
        $response->setHeader('Connection', 'keep-alive');
        $response->setHeader('X-Accel-Buffering', 'no');

        $session = $this->sessions->detail($uid, $sessionId);
        if (!$session) {
            $response->write($this->sse('fail', ['message' => '会话不存在']));
            return;
        }

        $cards = $this->sessions->checkedCards($uid, $sessionId);
        if (!$cards) {
            $response->write($this->sse('fail', ['message' => '请先在卡片上勾选至少 1 项高价值观点']));
            return;
        }

        $rounds = [];
        foreach ($session['rounds'] as $round) {
            $rounds[(int) $round['roundId']] = $round;
        }

        $material = '';
        $modelNames = [];
        foreach ($cards as $card) {
            $body = trim((string) ($card['body'] ?? ''));
            if ($body === '') {
                continue;
            }
            $round = $rounds[(int) ($card['round_id'] ?? 0)] ?? null;
            $roundLabel = $round ? ('第 ' . ((int) $round['roundIndex'] + 1) . ' 轮 · ' . $round['tag']) : '';
            $material .= "\n\n---\n【来源】" . ($card['model_name'] ?? $card['model_id']) . "({$roundLabel})\n【针对问题】" . ($round['question'] ?? '') . "\n【回答】\n" . mb_substr($body, 0, self::MAX_CARD_CHARS);
            $modelNames[] = (string) ($card['model_name'] ?? $card['model_id']);
        }
        if (trim($material) === '') {
            $response->write($this->sse('fail', ['message' => '勾选的卡片没有可用内容']));
            return;
        }

        $modelNames = array_values(array_unique($modelNames));
        $system = (new SettingService())->synthesisSystemPrompt();

        $prompt = '请基于以下 ' . count($modelNames) . ' 个模型(' . implode(' / ', $modelNames) . ')对课题「' . $session['title'] . '」的回答材料,'
            . '进行多模型交叉比对与综合分析,生成一份结构化研报:' . $material;

        $model = (string) (config('tiwen@config.synthesis_model', 'gpt-5.6-sol'));
        $this->chat->stream($response, [
            'model' => $model,
            'prompt' => $prompt,
            'system' => $system,
            'temperature' => 0.4,
            'uid' => $uid,
            'session_id' => 0,
            'round_id' => 0,
        ]);
    }

    private function sse(string $event, array $data): string {
        return "event: {$event}\ndata: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
}
