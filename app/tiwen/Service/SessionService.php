<?php

declare(strict_types=1);

namespace App\tiwen\Service;

/**
 * 会话服务
 * 会话/轮次/卡片 CRUD,组装为前端 SessionData 形状
 */
class SessionService {

    /**
     * 会话列表(含轮次与卡片)
     */
    public function list(int $uid): array {
        $sessions = m('tiwen_sessions')->where('uid', $uid)->orderBy('updated_at', 'desc')->limit(100)->get()->toArray() ?: [];
        if (!$sessions) {
            return [];
        }
        $sessionIds = array_map(fn($s) => (int) $s['id'], $sessions);
        $rounds = $this->roundsOf($sessionIds);
        $cards = $this->cardsOf(array_map(fn($r) => (int) $r['id'], $rounds) ?: [0]);

        $grouped = [];
        foreach ($rounds as $round) {
            $grouped[(int) $round['session_id']][] = $round;
        }
        $cardGrouped = [];
        foreach ($cards as $card) {
            $cardGrouped[(int) $card['round_id']][] = $card;
        }

        $list = [];
        foreach ($sessions as $s) {
            $list[] = $this->assemble($s, $grouped[(int) $s['id']] ?? [], $cardGrouped);
        }
        return $list;
    }

    /**
     * 单个会话详情
     */
    public function detail(int $uid, int $sessionId): ?array {
        $session = m('tiwen_sessions')->where('id', $sessionId)->where('uid', $uid)->one();
        if (!$session) {
            return null;
        }
        $rounds = $this->roundsOf([(int) $session['id']]);
        $cards = $this->cardsOf(array_map(fn($r) => (int) $r['id'], $rounds) ?: [0]);
        $cardGrouped = [];
        foreach ($cards as $card) {
            $cardGrouped[(int) $card['round_id']][] = $card;
        }
        return $this->assemble($session, $rounds, $cardGrouped);
    }

    /**
     * 创建会话
     */
    public function create(int $uid, string $title = '', string $summary = ''): array {
        $now = date('Y-m-d H:i:s');
        $title = mb_substr(trim($title) ?: '未命名研报会话', 0, 100);
        m('tiwen_sessions')->insert([
            'uid' => $uid,
            'title' => $title,
            'summary' => mb_substr(trim($summary) ?: $title, 0, 200),
            'editor_draft' => '',
            'total_tokens' => 0,
            'active_round_index' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) (m('tiwen_sessions')->where('uid', $uid)->orderBy('id', 'desc')->limit(1)->value('id') ?? 0);
        return ['id' => $id, 'routeId' => $this->routeId($id), 'title' => $title];
    }

    /**
     * 重命名会话
     */
    public function rename(int $uid, int $sessionId, string $title): bool {
        $title = mb_substr(trim($title), 0, 100);
        if ($title === '') {
            return false;
        }
        return (bool) m('tiwen_sessions')->where('id', $sessionId)->where('uid', $uid)->update([
            'title' => $title,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 删除会话(级联删轮次与卡片)
     */
    public function remove(int $uid, int $sessionId): bool {
        $session = m('tiwen_sessions')->where('id', $sessionId)->where('uid', $uid)->one();
        if (!$session) {
            return false;
        }
        $roundIds = array_map(fn($r) => (int) $r['id'], $this->roundsOf([$sessionId]));
        if ($roundIds) {
            m('tiwen_cards')->whereIn('round_id', $roundIds)->delete();
        }
        m('tiwen_rounds')->where('session_id', $sessionId)->delete();
        m('tiwen_sessions')->where('id', $sessionId)->delete();
        return true;
    }

    /**
     * 保存编辑区草稿
     */
    public function saveDraft(int $uid, int $sessionId, string $draft): bool {
        return (bool) m('tiwen_sessions')->where('id', $sessionId)->where('uid', $uid)->update([
            'editor_draft' => $draft,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * 设置激活轮次
     */
    public function setActiveRound(int $uid, int $sessionId, int $roundIndex): bool {
        return (bool) m('tiwen_sessions')->where('id', $sessionId)->where('uid', $uid)->update([
            'active_round_index' => max(0, $roundIndex),
        ]);
    }

    /**
     * 创建提问轮次(返回轮次 id 与序号)
     */
    public function createRound(int $uid, int $sessionId, string $question): array {
        $session = m('tiwen_sessions')->where('id', $sessionId)->where('uid', $uid)->one();
        if (!$session) {
            return ['ok' => false, 'message' => '会话不存在'];
        }
        $question = trim($question);
        if ($question === '') {
            return ['ok' => false, 'message' => '问题不能为空'];
        }
        $roundCount = (int) (m('tiwen_rounds')->where('session_id', $sessionId)->count() ?? 0);
        $now = date('Y-m-d H:i:s');
        m('tiwen_rounds')->insert([
            'session_id' => $sessionId,
            'round_index' => $roundCount,
            'question' => mb_substr($question, 0, 2000),
            'tag' => $roundCount === 0 ? '主问题' : '追问',
            'created_at' => $now,
        ]);
        $roundId = (int) (m('tiwen_rounds')->where('session_id', $sessionId)->orderBy('id', 'desc')->limit(1)->value('id') ?? 0);

        // 首轮时用问题补全会话标题/摘要
        $update = ['active_round_index' => $roundCount, 'updated_at' => $now];
        if ($roundCount === 0) {
            $update['title'] = mb_substr($question, 0, 60);
            $update['summary'] = mb_substr($question, 0, 200);
        }
        m('tiwen_sessions')->where('id', $sessionId)->update($update);

        return ['ok' => true, 'message' => '', 'round_id' => $roundId, 'round_index' => $roundCount];
    }

    /**
     * 保存回答卡片(流式完成后落库)
     */
    public function saveCard(int $uid, int $sessionId, int $roundId, string $modelId, string $modelName, string $body, int $tokens, float $duration, string $status): int {
        $exists = m('tiwen_cards')->where('round_id', $roundId)->where('model_id', $modelId)->one();
        $data = [
            'round_id' => $roundId,
            'session_id' => $sessionId,
            'uid' => $uid,
            'model_id' => mb_substr($modelId, 0, 60),
            'model_name' => mb_substr($modelName, 0, 60),
            'body' => $body,
            'token_count' => $tokens,
            'duration_sec' => round($duration, 1),
            'status' => $status === 'failed' ? 'failed' : 'done',
        ];
        if ($exists) {
            m('tiwen_cards')->where('id', (int) $exists['id'])->update($data);
            $cardId = (int) $exists['id'];
        } else {
            $data['created_at'] = date('Y-m-d H:i:s');
            m('tiwen_cards')->insert($data);
            $cardId = (int) (m('tiwen_cards')->where('round_id', $roundId)->where('model_id', $modelId)->value('id') ?? 0);
        }

        // 会话累计 token
        m('tiwen_sessions')->where('id', $sessionId)->update([
            'total_tokens' => (int) (m('tiwen_cards')->where('session_id', $sessionId)->sum('token_count') ?? 0),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $cardId;
    }

    /**
     * 勾选/取消勾选卡片
     */
    public function toggleCard(int $uid, int $cardId, ?bool $checked = null): bool {
        $card = m('tiwen_cards')->where('id', $cardId)->where('uid', $uid)->one();
        if (!$card) {
            return false;
        }
        $to = $checked ?? !(int) $card['checked'];
        return (bool) m('tiwen_cards')->where('id', $cardId)->update(['checked' => $to ? 1 : 0]);
    }

    /**
     * 删除卡片
     */
    public function deleteCard(int $uid, int $cardId): bool {
        return (bool) m('tiwen_cards')->where('id', $cardId)->where('uid', $uid)->delete();
    }

    /**
     * 取勾选卡片(综合分析素材)
     */
    public function checkedCards(int $uid, int $sessionId): array {
        return m('tiwen_cards')->where('uid', $uid)->where('session_id', $sessionId)->where('checked', 1)->orderBy('id', 'asc')->get()->toArray() ?: [];
    }

    /**
     * 组装多轮上下文文本(每轮问题 + 精选回答)
     * 返回空串表示无历史;与本轮问题合并为单条 user 消息,避免模型误判要回答的对象
     */
    public function buildContext(int $uid, int $sessionId, int $excludeRoundId, int $roundsLimit, int $cardMaxChars): string {
        $rounds = $this->roundsOf([$sessionId]);
        // 排除当前流式轮次,取最近 N 轮
        $rounds = array_values(array_filter($rounds, fn($r) => (int) $r['id'] !== $excludeRoundId));
        $rounds = array_slice($rounds, -$roundsLimit);
        if (!$rounds) {
            return '';
        }
        $cards = $this->cardsOf(array_map(fn($r) => (int) $r['id'], $rounds));
        $cardGrouped = [];
        foreach ($cards as $card) {
            $cardGrouped[(int) $card['round_id']][] = $card;
        }

        $transcript = '';
        foreach ($rounds as $round) {
            $transcript .= "\n【第 " . ((int) $round['round_index'] + 1) . " 轮提问】" . (string) $round['question'] . "\n";
            $roundCards = $cardGrouped[(int) $round['id']] ?? [];
            // 优先勾选卡片;无勾选取首张完成卡片
            $picked = array_values(array_filter($roundCards, fn($c) => (int) $c['checked'] === 1));
            if (!$picked) {
                $picked = array_values(array_filter($roundCards, fn($c) => ($c['status'] ?? '') === 'done'));
                $picked = array_slice($picked, 0, 1);
            }
            foreach ($picked as $card) {
                $body = trim((string) ($card['body'] ?? ''));
                if ($body === '') {
                    continue;
                }
                $transcript .= "\n[" . ($card['model_name'] ?? $card['model_id']) . " 的回答]\n" . mb_substr($body, 0, $cardMaxChars) . "\n";
            }
        }
        return trim($transcript);
    }

    /**
     * 用量统计(个人页)
     */
    public function usage(int $uid): array {
        $byModel = m('tiwen_cards')->where('uid', $uid)->where('status', 'done')
            ->orderBy('id', 'asc')->limit(2000)->get(['model_id', 'model_name', 'token_count', 'duration_sec', 'created_at'])->toArray() ?: [];
        $models = [];
        $totalTokens = 0;
        foreach ($byModel as $c) {
            $mid = (string) $c['model_id'];
            if (!isset($models[$mid])) {
                $models[$mid] = ['modelId' => $mid, 'modelName' => (string) $c['model_name'], 'tokens' => 0, 'asks' => 0, 'durationSec' => 0.0];
            }
            $models[$mid]['tokens'] += (int) $c['token_count'];
            $models[$mid]['asks'] += 1;
            $models[$mid]['durationSec'] += (float) $c['duration_sec'];
            $totalTokens += (int) $c['token_count'];
        }
        // 占比
        foreach ($models as &$m) {
            $m['percent'] = $totalTokens > 0 ? round($m['tokens'] * 100 / $totalTokens, 1) : 0;
            $m['durationSec'] = round($m['durationSec'], 1);
        }
        unset($m);

        // 近 14 天每日用量
        $daily = [];
        for ($i = 13; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} day"));
            $daily[$day] = 0;
        }
        foreach ($byModel as $c) {
            $day = substr((string) $c['created_at'], 0, 10);
            if (isset($daily[$day])) {
                $daily[$day] += (int) $c['token_count'];
            }
        }

        $sessionCount = (int) (m('tiwen_sessions')->where('uid', $uid)->count() ?? 0);
        $roundCount = (int) (m('tiwen_rounds')->whereIn('session_id', array_map(fn($s) => (int) $s['id'], m('tiwen_sessions')->where('uid', $uid)->get(['id'])->toArray() ?: [0]))->count() ?? 0);

        return [
            'totalTokens' => $totalTokens,
            'sessionCount' => $sessionCount,
            'roundCount' => $roundCount,
            'cardCount' => count($byModel),
            'models' => array_values($models),
            'daily' => [['date' => array_keys($daily), 'tokens' => array_values($daily)]][0] ?? ['date' => [], 'tokens' => []],
        ];
    }

    /**
     * 组装前端 SessionData 形状
     */
    private function assemble(array $session, array $rounds, array $cardGrouped): array {
        $roundList = [];
        foreach ($rounds as $round) {
            $cards = $cardGrouped[(int) $round['id']] ?? [];
            $models = [];
            foreach ($cards as $card) {
                $status = (string) ($card['status'] ?? 'done');
                $models[] = [
                    'id' => 'card_' . (int) $card['id'],
                    'cardId' => (int) $card['id'],
                    'modelKey' => (string) $card['model_id'],
                    'modelName' => (string) $card['model_name'],
                    'meta' => number_format((int) $card['token_count']) . ' tok · ' . round((float) $card['duration_sec'], 1) . 's',
                    'tokenCount' => (int) $card['token_count'],
                    'durationSec' => (float) $card['duration_sec'],
                    'body' => (string) ($card['body'] ?? ''),
                    'checked' => (int) $card['checked'] === 1,
                    'status' => $status,
                    'failed' => $status === 'failed',
                    'failMessage' => $status === 'failed' ? '输出异常，请稍后重试' : '',
                ];
            }
            $roundList[] = [
                'id' => 'round_' . (int) $round['id'],
                'roundId' => (int) $round['id'],
                'roundIndex' => (int) $round['round_index'],
                'question' => (string) ($round['question'] ?? ''),
                'tag' => (string) ($round['tag'] ?? '追问'),
                'createdAt' => strtotime((string) $round['created_at']) * 1000,
                'models' => $models,
            ];
        }
        return [
            'id' => $this->routeId((int) $session['id']),
            'sessionId' => (int) $session['id'],
            'title' => (string) ($session['title'] ?? ''),
            'summary' => (string) ($session['summary'] ?? ''),
            'totalTokens' => (int) ($session['total_tokens'] ?? 0),
            'activeRoundIndex' => (int) ($session['active_round_index'] ?? 0),
            'editorDraft' => (string) ($session['editor_draft'] ?? ''),
            'updatedAt' => strtotime((string) $session['updated_at']) * 1000,
            'rounds' => $roundList,
        ];
    }

    private function roundsOf(array $sessionIds): array {
        if (!$sessionIds) {
            return [];
        }
        return m('tiwen_rounds')->whereIn('session_id', $sessionIds)->orderBy('round_index', 'asc')->get()->toArray() ?: [];
    }

    /**
     * 会话路由 id:自增 id 经乘法同余(奇乘数模 2^32 双射)+ 异或盐混淆后 base36,
     * 输出不可预测的非连续短 id,避免路由暴露连续数字;前端仅作标识,接口一律用 sessionId
     */
    private function routeId(int $id): string {
        $x = (($id * 2654435761) & 0xFFFFFFFF) ^ (int) base_convert('5f3759df', 16, 10);
        return 'session_' . base_convert((string) $x, 10, 36);
    }

    private function cardsOf(array $roundIds): array {
        if (!$roundIds) {
            return [];
        }
        return m('tiwen_cards')->whereIn('round_id', $roundIds)->orderBy('id', 'asc')->get()->toArray() ?: [];
    }
}
