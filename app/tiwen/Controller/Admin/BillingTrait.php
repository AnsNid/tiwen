<?php

namespace App\tiwen\Controller\Admin;

use App\tiwen\Controller\Api\Responses;
use App\tiwen\Service\SettingService;
use xphp\HttpServer\Annotation\Middleware;
use App\tiwen\Middleware\AdminAuthMiddleware;

/**
 * 后台账单明细接口（需 tiwen:admin 权限）
 * 平台级 Stripe 支付记录:收款 KPI、流水分页、用户归属
 */
#[Middleware(AdminAuthMiddleware::class)]
trait BillingTrait {

    use Responses;

    /** 计入收入的流水类型(发票成功扣款;checkout 首笔与之重复,不双计) */
    private const REVENUE_TYPES = ['invoice.paid', 'invoice.payment_succeeded'];
    /** 展示的流水类型 */
    private const LIST_TYPES = ['checkout.session', 'invoice.paid', 'invoice.payment_succeeded', 'invoice.payment_failed', 'invoice'];

    /**
     * 账单明细
     * @doc(method: 'get', description: '平台 Stripe 支付记录:收款 KPI + 分页流水(含用户归属),支持按昵称/邮箱/UID/Stripe单号搜索', tag: "后台-账单明细")
     * @param int $page 页码(默认1)
     * @param int $limit 每页数量(默认15)
     * @param string $keyword 关键词(昵称/邮箱/UID/Stripe对象ID)
     * @return array {code: 200, data: {stripeInstalled: true, kpi: {...}, count: 100, page: 1, limit: 15, data: [...]}}
     */
    public function billing() {
        $page = max(1, (int) input('page', 1));
        $limit = min(100, max(1, (int) input('limit', 15)));
        $keyword = trim((string) input('keyword', ''));

        if (!app('?stripe')) {
            return $this->ok([
                'stripeInstalled' => false,
                'kpi' => ['totalRevenue' => 0, 'monthRevenue' => 0, 'successCount' => 0, 'failedCount' => 0],
                'count' => 0,
                'page' => $page,
                'limit' => $limit,
                'data' => [],
            ]);
        }

        // ---- tiwen 客户映射:Stripe customer → uid/套餐 ----
        $memberRows = m('tiwen_members')->where('stripe_customer_id', '<>', '')->get(['uid', 'plan', 'stripe_customer_id'])->toArray() ?: [];
        $customerMap = [];
        $customerIds = [];
        foreach ($memberRows as $r) {
            $customerMap[(string) $r['stripe_customer_id']] = $r;
            $customerIds[] = (string) $r['stripe_customer_id'];
        }

        // ---- 收款 KPI ----
        // invoice.paid 覆盖首笔+续费;对有 invoice 记录的客户不再双计 checkout.session
        // 无 invoice 记录的客户回落 checkout.session(新部署/Webhook 未到的情况)
        $invoicePaid = m('stripe_payments')->whereIn('type', self::REVENUE_TYPES)->whereIn('customer', $customerIds ?: [''])->get(['amount', 'created_at', 'customer'])->toArray() ?: [];
        $customersWithInvoice = [];
        foreach ($invoicePaid as $r) {
            $customersWithInvoice[(string) $r['customer']] = true;
        }
        $revenueRows = $invoicePaid;
        foreach ($customerIds as $cid) {
            if (isset($customersWithInvoice[$cid])) {
                continue;
            }
            $checkoutRows = m('stripe_payments')->where('type', 'checkout.session')->where('customer', $cid)->whereIn('status', ['complete', 'succeeded'])->get(['amount', 'created_at'])->toArray() ?: [];
            $revenueRows = array_merge($revenueRows, $checkoutRows);
        }
        $monthStart = date('Y-m-01');
        $totalRevenue = 0;
        $monthRevenue = 0;
        foreach ($revenueRows as $r) {
            $amount = ((int) $r['amount']) / 100;
            $totalRevenue += $amount;
            if (substr((string) $r['created_at'], 0, 10) >= $monthStart) {
                $monthRevenue += $amount;
            }
        }

        // ---- 流水查询(tiwen 客户范围,支付相关类型,按时间倒序;量级小,取回后内存筛关键词) ----
        $rows = m('stripe_payments')
            ->whereIn('type', self::LIST_TYPES)
            ->whereIn('customer', $customerIds ?: [''])
            ->orderBy('created_at', 'desc')
            ->limit(1000)
            ->get(['type', 'object_id', 'status', 'amount', 'currency', 'customer', 'metadata', 'created_at'])
            ->toArray() ?: [];

        // ---- 用户资料补全 ----
        $uids = array_map(fn($r) => (int) $r['uid'], $memberRows) ?: [0];
        $users = m('user.Api')->whereIn('uid', array_unique($uids))->get(['uid', 'username', 'nickname', 'email'])->toArray() ?: [];
        $userMap = [];
        foreach ($users as $u) {
            $userMap[(int) $u['uid']] = $u;
        }

        // ---- 组装 + 关键词过滤 ----
        $list = [];
        $successCount = 0;
        $failedCount = 0;
        foreach ($rows as $r) {
            $customer = (string) $r['customer'];
            $member = $customerMap[$customer] ?? [];
            $uid = (int) ($member['uid'] ?? 0);
            $u = $userMap[$uid] ?? [];
            $name = (string) ($u['nickname'] ?? ($u['username'] ?? ($uid > 0 ? 'UID ' . $uid : '未知用户')));
            $email = (string) ($u['email'] ?? '');
            $objectId = (string) $r['object_id'];
            $type = (string) $r['type'];
            $isFailed = $type === 'invoice.payment_failed' || (string) $r['status'] === 'failed';
            if ($isFailed) {
                $failedCount++;
            } elseif (in_array($type, self::REVENUE_TYPES, true) || in_array((string) $r['status'], ['complete', 'succeeded', 'paid'], true)) {
                $successCount++;
            }

            // 关键词:对象ID / 邮箱 / 昵称 / UID
            if ($keyword !== '' && !str_contains($objectId, $keyword) && !str_contains($email, $keyword) && !str_contains($name, $keyword) && !(ctype_digit($keyword) && (int) $keyword === $uid)) {
                continue;
            }

            $list[] = [
                'id' => $objectId,
                'type' => $type,
                'typeLabel' => $this->typeLabel($type),
                'status' => (string) $r['status'],
                'failed' => $isFailed,
                'amount' => round(((int) $r['amount']) / 100, 2),
                'currency' => strtoupper((string) $r['currency']),
                'plan' => (string) ($member['plan'] ?? ''),
                'uid' => $uid,
                'name' => $name,
                'email' => $email,
                'avatar' => $uid > 0 ? avatar($uid) : '',
                'createdAt' => (string) $r['created_at'],
            ];
        }

        return $this->ok([
            'stripeInstalled' => true,
            'currency' => (new SettingService())->currency(),
            'currencySymbol' => (new SettingService())->currencySymbol(),
            'kpi' => [
                'totalRevenue' => round($totalRevenue, 2),
                'monthRevenue' => round($monthRevenue, 2),
                'successCount' => $successCount,
                'failedCount' => $failedCount,
            ],
            'count' => count($list),
            'page' => $page,
            'limit' => $limit,
            'data' => array_slice($list, ($page - 1) * $limit, $limit),
        ]);
    }

    /**
     * 流水类型中文标签
     */
    private function typeLabel(string $type): string {
        return match ($type) {
            'checkout.session' => '首次订阅支付',
            'invoice.paid', 'invoice.payment_succeeded' => '订阅续费扣款',
            'invoice.payment_failed' => '扣款失败',
            'invoice' => '订阅发票',
            default => $type,
        };
    }
}
