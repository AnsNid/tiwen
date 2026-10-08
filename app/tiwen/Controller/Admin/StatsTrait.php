<?php

namespace App\tiwen\Controller\Admin;

use App\tiwen\Controller\Api\Responses;
use App\tiwen\Service\SettingService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AdminAuthMiddleware;

/**
 * 后台消耗统计接口（需 tiwen:admin 权限）
 * 平台级 KPI、近 14 天趋势、模型消耗占比、套餐分布、消耗 Top 用户
 */
#[Middleware(AdminAuthMiddleware::class)]
trait StatsTrait {

    use Responses;

    /**
     * 消耗统计概览
     * @doc(method: 'get', description: '平台级消耗统计:KPI、近14天Token趋势、模型消耗、套餐分布、Top用户', tag: "后台-统计")
     * @return array {code: 200, data: {kpi: {...}, daily: {...}, models: [...], plans: [...], topMembers: [...]}}
     */
    public function stats() {
        $today = date('Y-m-d');
        $monthStart = date('Y-m-01');
        $days = 14;

        // ---- KPI ----
        $memberTotal = (int) (m('tiwen_members')->count() ?? 0);
        $paidMembers = (int) (m('tiwen_members')->where('plan', '<>', 'free')->count() ?? 0);
        $activeSubs = (int) (m('tiwen_members')->where('subscription_status', 'active')->count() ?? 0);
        $sessionTotal = (int) (m('tiwen_sessions')->count() ?? 0);
        $todayCards = (int) (m('tiwen_cards')->where('created_at', '>=', $today . ' 00:00:00')->where('status', 'done')->count() ?? 0);
        $todayTokens = (int) (m('tiwen_cards')->where('created_at', '>=', $today . ' 00:00:00')->where('status', 'done')->sum('token_count') ?? 0);
        $monthTokens = (int) (m('tiwen_cards')->where('created_at', '>=', $monthStart . ' 00:00:00')->where('status', 'done')->sum('token_count') ?? 0);
        $totalTokens = (int) (m('tiwen_cards')->where('status', 'done')->sum('token_count') ?? 0);

        // ---- 近 N 天每日消耗(补齐缺日) ----
        $startDate = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $rows = m('tiwen_cards')
            ->where('status', 'done')
            ->where('created_at', '>=', $startDate . ' 00:00:00')
            ->groupBy(dbraw("DATE_FORMAT(created_at, '%Y-%m-%d')"))
            ->select([
                dbraw("DATE_FORMAT(created_at, '%Y-%m-%d') as day"),
                dbraw('SUM(token_count) as tokens'),
                dbraw('COUNT(*) as cards'),
            ])
            ->get()->toArray() ?: [];
        $dayMap = [];
        foreach ($rows as $r) {
            $dayMap[(string) $r['day']] = [(int) $r['tokens'], (int) $r['cards']];
        }
        $dates = [];
        $tokens = [];
        $cards = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} day"));
            $dates[] = $day;
            $tokens[] = $dayMap[$day][0] ?? 0;
            $cards[] = $dayMap[$day][1] ?? 0;
        }

        // ---- 模型消耗占比 ----
        $modelRows = m('tiwen_cards')
            ->where('status', 'done')
            ->groupBy('model_id')
            ->select([
                'model_id',
                dbraw('MAX(model_name) as model_name'),
                dbraw('SUM(token_count) as tokens'),
                dbraw('COUNT(*) as asks'),
                dbraw('AVG(duration_sec) as avg_duration'),
            ])
            ->orderBy('tokens', 'desc')
            ->limit(20)
            ->get()->toArray() ?: [];
        $models = [];
        foreach ($modelRows as $r) {
            $models[] = [
                'modelId' => (string) $r['model_id'],
                'modelName' => (string) ($r['model_name'] ?: $r['model_id']),
                'tokens' => (int) $r['tokens'],
                'asks' => (int) $r['asks'],
                'avgDuration' => round((float) $r['avg_duration'], 1),
                'percent' => $totalTokens > 0 ? round(((int) $r['tokens']) * 100 / $totalTokens, 1) : 0,
            ];
        }

        // ---- 套餐分布(计费口径以运行中套餐定义为准) ----
        $planRows = m('tiwen_members')->groupBy('plan')->select(['plan', dbraw('COUNT(*) as total')])->get()->toArray() ?: [];
        $planNames = [];
        foreach ((new SettingService())->plans() as $id => $conf) {
            $planNames[(string) $id] = (string) ($conf['name'] ?? $id);
        }
        $plans = [];
        $planTotal = 0;
        foreach ($planRows as $r) {
            $planTotal += (int) $r['total'];
        }
        foreach ($planRows as $r) {
            $plans[] = [
                'plan' => (string) $r['plan'],
                'name' => (string) ($planNames[(string) $r['plan']] ?? $r['plan']),
                'total' => (int) $r['total'],
                'percent' => $planTotal > 0 ? round(((int) $r['total']) * 100 / $planTotal, 1) : 0,
            ];
        }

        // ---- 消耗 Top 用户 ----
        $topRows = m('tiwen_members')->orderBy('tokens_used', 'desc')->limit(10)
            ->get(['uid', 'plan', 'tokens_quota', 'tokens_used', 'daily_asks'])->toArray() ?: [];
        $topUids = array_map(fn($r) => (int) $r['uid'], $topRows) ?: [0];
        $users = m('user.Api')->whereIn('uid', $topUids)->get(['uid', 'username', 'nickname', 'email'])->toArray() ?: [];
        $userMap = [];
        foreach ($users as $u) {
            $userMap[(int) $u['uid']] = $u;
        }
        $topMembers = [];
        foreach ($topRows as $r) {
            if ((int) $r['tokens_used'] <= 0) {
                continue;
            }
            $u = $userMap[(int) $r['uid']] ?? [];
            $topMembers[] = [
                'uid' => (int) $r['uid'],
                'name' => (string) ($u['nickname'] ?? ($u['username'] ?? ('UID ' . $r['uid']))),
                'email' => (string) ($u['email'] ?? ''),
                'plan' => (string) $r['plan'],
                'tokensUsed' => (int) $r['tokens_used'],
                'tokensQuota' => (int) $r['tokens_quota'],
            ];
        }

        return $this->ok([
            'kpi' => [
                'memberTotal' => $memberTotal,
                'paidMembers' => $paidMembers,
                'activeSubscriptions' => $activeSubs,
                'sessionTotal' => $sessionTotal,
                'todayCards' => $todayCards,
                'todayTokens' => $todayTokens,
                'monthTokens' => $monthTokens,
                'totalTokens' => $totalTokens,
            ],
            'daily' => ['dates' => $dates, 'tokens' => $tokens, 'cards' => $cards],
            'models' => $models,
            'plans' => $plans,
            'topMembers' => $topMembers,
        ]);
    }
}
