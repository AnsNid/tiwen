<?php

namespace App\tiwen\Controller\Admin;

use App\tiwen\Controller\Api\Responses;
use App\tiwen\Service\MemberService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AdminAuthMiddleware;

/**
 * 后台会员管理接口（需 tiwen:admin 权限）
 * 会员分页检索、套餐/配额调整、用量重置
 */
#[Middleware(AdminAuthMiddleware::class)]
trait MembersTrait {

    use Responses;

    /**
     * 会员列表
     * @doc(method: 'get', description: '分页查询会员(关联用户资料),支持按昵称/邮箱/UID 搜索与套餐筛选', tag: "后台-会员")
     * @param int $page 页码(默认1)
     * @param int $limit 每页数量(默认20)
     * @param string $keyword 关键词(昵称/邮箱/UID)
     * @param string $plan 套餐筛选(free/pro/team,空为全部)
     * @return array {code: 200, data: {count: 100, page: 1, limit: 20, data: [...]}}
     */
    public function members() {
        $page = max(1, (int) input('page', 1));
        $limit = min(100, max(1, (int) input('limit', 20)));
        $keyword = trim((string) input('keyword', ''));
        $plan = trim((string) input('plan', ''));

        $query = m('tiwen_members')->newQuery();
        if ($plan === 'free') {
            $query->where(function ($q) {
                $q->where('plan', 'free')->orWhere('plan', '')->orWhereNull('plan');
            });
        } elseif ($plan !== '') {
            $query->where('plan', $plan);
        }

        // 关键词命中用户表时按 uid 集过滤,纯数字直接当 uid
        if ($keyword !== '') {
            $uids = [];
            if (ctype_digit($keyword)) {
                $uids[] = (int) $keyword;
            }
            $like = m('user.Api')
                ->where('email', 'like', "%{$keyword}%")
                ->orWhere('nickname', 'like', "%{$keyword}%")
                ->limit(200)
                ->get(['uid'])->toArray() ?: [];
            foreach ($like as $u) {
                $uids[] = (int) $u['uid'];
            }
            $query->whereIn('uid', array_unique($uids) ?: [0]);
        }

        $count = (int) ((clone $query)->count() ?? 0);
        $rows = $query->orderBy('uid', 'desc')
            ->limit($limit)
            ->offset(($page - 1) * $limit)
            ->get()->toArray() ?: [];

        // 批量补用户资料
        $uids = array_map(fn($r) => (int) $r['uid'], $rows) ?: [0];
        $users = m('user.Api')->whereIn('uid', $uids)->get(['uid', 'username', 'nickname', 'email', 'regdate'])->toArray() ?: [];
        $userMap = [];
        foreach ($users as $u) {
            $userMap[(int) $u['uid']] = $u;
        }

        $list = [];
        foreach ($rows as $r) {
            $u = $userMap[(int) $r['uid']] ?? [];
            $list[] = [
                'uid' => (int) $r['uid'],
                'name' => (string) ($u['nickname'] ?? ($u['username'] ?? ('UID ' . $r['uid']))),
                'email' => (string) ($u['email'] ?? ''),
                'avatar' => avatar((int) $r['uid']),
                'plan' => (string) $r['plan'],
                'planInterval' => (string) $r['plan_interval'],
                'tokensQuota' => (int) $r['tokens_quota'],
                'tokensUsed' => (int) $r['tokens_used'],
                'dailyAsks' => (int) $r['daily_asks'],
                'subscriptionStatus' => (string) $r['subscription_status'],
                'currentPeriodEnd' => (int) $r['current_period_end'],
                'stripeCustomerId' => (string) $r['stripe_customer_id'],
                'createdAt' => (string) ($r['created_at'] ?? ''),
                'userCreatedAt' => (string) ($u['regdate'] ?? ''),
            ];
        }

        return $this->ok([
            'count' => $count,
            'page' => $page,
            'limit' => $limit,
            'data' => $list,
        ]);
    }

    /**
     * 会员调整
     * @doc(method: 'post', description: '调整会员套餐/配额/用量,可手动开通或降级(不走 Stripe)', tag: "后台-会员")
     * @param int $uid 会员UID
     * @param string $plan 目标套餐: free/pro/team(选填,不改则不传)
     * @param int $tokens_quota 月度 Token 配额(选填,不传则按目标套餐默认值)
     * @param bool $reset_usage 是否重置本月用量与今日次数(默认 false)
     * @return array {code: 200, message: '已保存'}
     */
    public function memberSave() {
        $uid = (int) input('uid', 0);
        if ($uid <= 0) {
            return $this->fail('参数错误');
        }
        $member = m('tiwen_members')->where('uid', $uid)->one();
        if (!$member) {
            return $this->fail('会员不存在');
        }

        $members = new MemberService();
        $plan = trim((string) input('plan', ''));
        $update = [];

        if ($plan !== '') {
            $conf = $members->planConfig($plan);
            if (!$conf) {
                return $this->fail('套餐不存在');
            }
            $update['plan'] = $plan;
            // 未显式传配额时,套用目标套餐默认配额
            $quota = input('tokens_quota', null);
            if ($quota === null || $quota === '') {
                $update['tokens_quota'] = (int) ($conf['tokens_quota'] ?? 0);
            }
            if ($plan === 'free') {
                $update['plan_interval'] = '';
            }
        }

        $quota = input('tokens_quota', null);
        if ($quota !== null && $quota !== '') {
            $update['tokens_quota'] = max(0, (int) $quota);
        }

        if (input('reset_usage', false)) {
            $update['tokens_used'] = 0;
            $update['daily_asks'] = 0;
            $update['quota_reset_date'] = date('Y-m-01', strtotime('+1 month'));
            $update['daily_ask_date'] = date('Y-m-d');
        }

        if (!$update) {
            return $this->fail('没有需要调整的项');
        }

        m('tiwen_members')->where('uid', $uid)->update($update);
        return $this->ok(null, '会员已调整');
    }

    /**
     * 会员提问记录
     * @doc(method: 'get', description: '分页查看指定会员的提问记录(跨会话按时间倒序),含所属会话、问题文本、各模型回答卡与Token消耗,支持按问题关键词搜索', tag: "后台-会员")
     * @param int $uid 会员UID
     * @param int $page 页码(默认1)
     * @param int $limit 每页数量(默认10,最大50)
     * @param string $keyword 问题关键词(模糊匹配提问文本)
     * @return array {code: 200, data: {stats: {sessions: 12, rounds: 48, tokens: 123456}, count: 48, page: 1, limit: 10, data: [...]}}
     */
    public function memberRecords() {
        $uid = (int) input('uid', 0);
        if ($uid <= 0) {
            return $this->fail('参数错误');
        }
        $page = max(1, (int) input('page', 1));
        $limit = min(50, max(1, (int) input('limit', 10)));
        $keyword = trim((string) input('keyword', ''));

        // 提问归属按会话间接关联(rounds 无 uid 列),先取该会员的会话ID集
        $sessionIds = m('tiwen_sessions')->where('uid', $uid)->orderBy('id', 'desc')->pluck('id')->toArray() ?: [0];
        $sessionRows = m('tiwen_sessions')->whereIn('id', $sessionIds)->get(['id', 'title'])->toArray() ?: [];
        $sessionTitles = [];
        foreach ($sessionRows as $s) {
            $sessionTitles[(int) $s['id']] = (string) $s['title'];
        }

        // ---- 用量概览 ----
        $stats = [
            'sessions' => count($sessionIds) === 1 && (int) $sessionIds[0] === 0 ? 0 : count($sessionIds),
            'rounds' => (int) (m('tiwen_rounds')->whereIn('session_id', $sessionIds)->count() ?? 0),
            'tokens' => (int) (m('tiwen_cards')->where('uid', $uid)->where('status', 'done')->sum('token_count') ?? 0),
        ];

        // ---- 轮次分页(时间倒序,关键词过滤) ----
        $countModel = m('tiwen_rounds')->whereIn('session_id', $sessionIds);
        $listModel = m('tiwen_rounds')->whereIn('session_id', $sessionIds);
        if ($keyword !== '') {
            $countModel->where('question', 'like', "%{$keyword}%");
            $listModel->where('question', 'like', "%{$keyword}%");
        }
        $count = (int) ($countModel->count() ?? 0);

        $roundRows = $listModel
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->offset(($page - 1) * $limit)
            ->get()->toArray() ?: [];

        // ---- 批量取各轮的回答卡片 ----
        $roundIds = array_map(fn($r) => (int) $r['id'], $roundRows) ?: [0];
        $cardRows = m('tiwen_cards')->whereIn('round_id', $roundIds)
            ->get(['round_id', 'model_id', 'model_name', 'token_count', 'duration_sec', 'checked', 'status'])->toArray() ?: [];
        $cardsByRound = [];
        foreach ($cardRows as $c) {
            $cardsByRound[(int) $c['round_id']][] = [
                'modelId' => (string) $c['model_id'],
                'modelName' => (string) ($c['model_name'] ?: $c['model_id']),
                'tokens' => (int) $c['token_count'],
                'duration' => round((float) $c['duration_sec'], 1),
                'checked' => (bool) $c['checked'],
                'failed' => (string) $c['status'] === 'failed',
            ];
        }

        $list = [];
        foreach ($roundRows as $r) {
            $cards = $cardsByRound[(int) $r['id']] ?? [];
            $tokens = 0;
            foreach ($cards as $c) {
                if (!$c['failed']) {
                    $tokens += $c['tokens'];
                }
            }
            $list[] = [
                'id' => (int) $r['id'],
                'sessionId' => (int) $r['session_id'],
                'sessionTitle' => $sessionTitles[(int) $r['session_id']] ?? ('会话 #' . $r['session_id']),
                'roundIndex' => (int) $r['round_index'],
                'question' => (string) ($r['question'] ?? ''),
                'tag' => (string) ($r['tag'] ?? '主问题'),
                'createdAt' => (string) ($r['created_at'] ?? ''),
                'tokens' => $tokens,
                'cards' => $cards,
            ];
        }

        return $this->ok([
            'stats' => $stats,
            'count' => $count,
            'page' => $page,
            'limit' => $limit,
            'data' => $list,
        ]);
    }
}
