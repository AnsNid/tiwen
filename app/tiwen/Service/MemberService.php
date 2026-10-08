<?php

declare(strict_types=1);

namespace App\tiwen\Service;

/**
 * 会员服务
 * 注册/登录复用中央 user 应用,套餐与配额存 tiwen_members
 */
class MemberService {

    /** 回合计费相关 Redis 键有效期(1 天,覆盖重试场景) */
    private const ROUND_KEY_TTL = 86400;

    /**
     * 注册新用户(邮箱 + 验证码 + 密码)
     * @return array{ok: bool, message: string, data?: array}
     */
    public function register(string $email, string $password): array {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '邮箱格式不正确'];
        }
        if (mb_strlen($password) < 6) {
            return ['ok' => false, 'message' => '密码不能少于 6 位'];
        }
        if (m('user.Api')->where('email', $email)->value('uid')) {
            return ['ok' => false, 'message' => '该邮箱已注册,请直接登录'];
        }

        // 用户名自动生成:邮箱前缀清洗 + 随机后缀防冲突
        $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $email)[0]) ?: '';
        $prefix = mb_substr('tw_' . ($prefix ?: 'user'), 0, 20);
        $username = $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
        while (m('user.Api')->where('username', $username)->value('uid')) {
            $username = $prefix . '_' . substr(bin2hex(random_bytes(4)), 0, 6);
        }

        try {
            $uid = app('user')->register([
                'username' => $username,
                'password' => $password,
                'nickname' => explode('@', $email)[0] ?: '提问用户',
                'email' => $email,
                'regtype' => 'email',
                'status' => 1,
            ]);
        } catch (\Throwable $e) {
            isJsonException($e);
            logger()->error('tiwen 注册失败: ' . $e->getMessage());
            return ['ok' => false, 'message' => '注册失败,请稍后重试'];
        }
        if (!$uid) {
            return ['ok' => false, 'message' => '注册失败,请稍后重试'];
        }

        $this->ensureMember((int) $uid);
        $login = $this->login($email, $password);
        if ($login['ok']) {
            return ['ok' => true, 'message' => '注册成功', 'data' => $login['data']];
        }
        return ['ok' => true, 'message' => '注册成功,请登录'];
    }

    /**
     * 邮箱密码登录
     * @return array{ok: bool, message: string, data?: array}
     */
    public function login(string $email, string $password): array {
        $email = trim(strtolower($email));
        if ($email === '' || $password === '') {
            return ['ok' => false, 'message' => '邮箱和密码不能为空'];
        }
        try {
            $user = app('user')->login(['account' => $email, 'password' => $password]);
        } catch (\Throwable $e) {
            isJsonException($e);
            logger()->error('tiwen 登录异常: ' . $e->getMessage());
            return ['ok' => false, 'message' => '登录失败,请稍后重试'];
        }
        if (!$user) {
            return ['ok' => false, 'message' => '邮箱或密码不正确'];
        }

        $uid = (int) $user['uid'];
        $this->ensureMember($uid);
        return ['ok' => true, 'message' => '登录成功', 'data' => [
            'token' => encode([$user['uid'], $user['session'], $user['uin']]),
            'user' => $this->profile($uid),
        ]];
    }

    /**
     * 重置登录密码(邮箱验证码校验通过后改密)
     * @return array{ok: bool, message: string}
     */
    public function resetPassword(string $email, string $code, string $password): array {
        $email = trim(strtolower($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => '邮箱格式不正确'];
        }
        if (mb_strlen($password) < 6) {
            return ['ok' => false, 'message' => '密码不能少于 6 位'];
        }
        $user = m('user.Api')->where('email', $email)->one();
        if (!$user) {
            return ['ok' => false, 'message' => '该邮箱尚未注册'];
        }
        if (!(new MailService())->verifyCode($email, $code)) {
            return ['ok' => false, 'message' => '验证码错误或已过期'];
        }
        // 与注册/登录一致:密码以用户 uniqid 作为 key 加密
        m('user.Api')->where('uid', (int) $user['uid'])->update([
            'password' => app('user')->password($password, (string) $user['uniqid']),
        ]);
        return ['ok' => true, 'message' => '密码已重置,请使用新密码登录'];
    }

    /**
     * 确保会员记录存在(首次登录/注册时初始化免费套餐)
     */
    public function ensureMember(int $uid): array {
        $row = m('tiwen_members')->where('uid', $uid)->one();
        if ($row) {
            return $row;
        }
        $free = $this->planConfig('free');
        m('tiwen_members')->insert([
            'uid' => $uid,
            'plan' => 'free',
            'tokens_quota' => (int) ($free['tokens_quota'] ?? 0),
            'quota_reset_date' => date('Y-m-d'),
            'daily_ask_date' => date('Y-m-d'),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return m('tiwen_members')->where('uid', $uid)->one() ?: [];
    }

    /**
     * 用户档案(前端 UserProfile 形状)
     */
    public function profile(int $uid): array {
        $this->rollQuota($uid);
        $u = m('user.Api')->where('uid', $uid)->one() ?: [];
        $member = $this->ensureMember($uid);
        $resetDate = (string) ($member['quota_reset_date'] ?? date('Y-m-d'));
        if ($resetDate < date('Y-m-d')) {
            $resetDate = date('Y-m-01', strtotime('+1 month'));
        }
        return [
            'id' => 'usr_' . $uid,
            'uid' => $uid,
            'email' => (string) ($u['email'] ?? ''),
            'name' => (string) ($u['nickname'] ?? ($u['username'] ?? '')),
            'avatar' => avatar($uid),
            'plan' => (string) ($member['plan'] ?? 'free'),
            'planInterval' => (string) ($member['plan_interval'] ?? ''),
            'subscriptionStatus' => (string) ($member['subscription_status'] ?? ''),
            'currentPeriodEnd' => (int) ($member['current_period_end'] ?? 0),
            'stripeCustomerId' => (string) ($member['stripe_customer_id'] ?? ''),
            'credits' => $this->creditsOf($uid),
            'tokenQuota' => [
                'total' => (int) ($member['tokens_quota'] ?? 0),
                'used' => (int) ($member['tokens_used'] ?? 0),
                'resetDate' => $resetDate,
            ],
            'dailyAsks' => [
                'used' => (int) ($member['daily_asks'] ?? 0),
                'limit' => (int) ($this->planConfig((string) ($member['plan'] ?? 'free'))['daily_asks'] ?? 0),
                'date' => (string) ($member['daily_ask_date'] ?? date('Y-m-d')),
            ],
            'createdAt' => (string) ($u['regdate'] ?? ''),
        ];
    }

    /**
     * 提问前配额校验:免费版每日限次(按并发模型数计,4 模型一次 = 4 次),付费版月度 Token 配额
     * data 携带 plan/used/limit/remaining(免费)或 plan/tokensUsed/tokensQuota(付费),随 402 返回给前端精确提示
     * @return array{ok: bool, message: string, data: array}
     */
    public function checkQuota(int $uid, int $count = 1): array {
        $this->rollQuota($uid);
        $member = $this->ensureMember($uid);
        $plan = (string) ($member['plan'] ?? 'free');

        if ($plan === 'free') {
            $limit = (int) ($this->planConfig('free')['daily_asks'] ?? 20);
            $used = (int) ($member['daily_asks'] ?? 0);
            // 后台每日限次配 0 表示不限制
            if ($limit <= 0) {
                return ['ok' => true, 'message' => '', 'data' => ['plan' => $plan, 'used' => $used, 'limit' => 0]];
            }
            $remaining = max(0, $limit - $used);
            $data = ['plan' => $plan, 'used' => $used, 'limit' => $limit, 'remaining' => $remaining];
            if ($used + $count > $limit) {
                // 剩余 0 次时"减少模型"已无意义,文案只引导升级
                $message = $remaining <= 0
                    ? '今日免费提问额度已用完,可升级套餐解除限制,明日自动重置'
                    : "今日剩余 {$remaining} 次免费提问额度,本次所选 {$count} 个模型需 {$count} 次(按并发模型数计),可减少并发模型或升级套餐解锁";
                return ['ok' => false, 'message' => $message, 'data' => $data];
            }
            return ['ok' => true, 'message' => '', 'data' => $data];
        }

        $quota = (int) ($member['tokens_quota'] ?? 0);
        $used = (int) ($member['tokens_used'] ?? 0);
        $data = ['plan' => $plan, 'tokensUsed' => $used, 'tokensQuota' => $quota];
        if ($quota > 0 && $used >= $quota) {
            return ['ok' => false, 'message' => '本月 Token 配额已用完,可升级套餐或等待下月重置', 'data' => $data];
        }
        return ['ok' => true, 'message' => '', 'data' => $data];
    }

    /**
     * 记提问次数(免费版,按并发模型数累计;原子自增避免并发丢失计数)
     */
    public function recordAsk(int $uid, int $count = 1): void {
        $this->rollQuota($uid);
        $member = $this->ensureMember($uid);
        if ((string) ($member['plan'] ?? 'free') !== 'free') {
            return;
        }
        m('tiwen_members')->where('uid', $uid)->increment('daily_asks', max(1, $count), [
            'daily_ask_date' => date('Y-m-d'),
        ]);
    }

    /**
     * 记录 Token 消耗(付费版月度配额;原子自增避免并发丢失计数)
     */
    public function recordTokens(int $uid, int $tokens): void {
        if ($tokens <= 0) {
            return;
        }
        $this->rollQuota($uid);
        $member = $this->ensureMember($uid);
        if ((string) ($member['plan'] ?? 'free') === 'free') {
            return;
        }
        m('tiwen_members')->where('uid', $uid)->increment('tokens_used', $tokens);
    }

    /**
     * 登记本轮的免费次数预扣信息(Redis,供整轮失败时返还)
     */
    public function stashRoundCharge(int $roundId, int $uid, int $count): void {
        if ($roundId <= 0 || $uid <= 0 || $count <= 0) {
            return;
        }
        try {
            xphp('redis')->setex('tiwen:round:charge:' . $roundId, self::ROUND_KEY_TTL, (string) $count);
        } catch (\Throwable $e) {
            logger()->warning('tiwen stashRoundCharge: ' . $e->getMessage());
        }
    }

    /**
     * 登记本轮单个模型的流式结果;该轮全部模型都失败时,返还免费版当日提问次数
     * 返还以 Redis SETNX 标记保证只执行一次,部分成功不返还
     */
    public function settleRoundStream(int $uid, int $roundId, string $model, bool $ok): void {
        if ($uid <= 0 || $roundId <= 0 || $model === '') {
            return;
        }
        try {
            $redis = xphp('redis');
            $settleKey = 'tiwen:round:settle:' . $roundId;
            $redis->hSet($settleKey, $model, $ok ? '1' : '0');
            $redis->expire($settleKey, self::ROUND_KEY_TTL);
            $charged = (int) $redis->get('tiwen:round:charge:' . $roundId);
            if ($charged <= 0 || (int) $redis->hLen($settleKey) < $charged) {
                return;
            }
            if (in_array('1', (array) $redis->hVals($settleKey), true)) {
                return;
            }
            // 全部失败:原子标记后返还,防止并发流重复返还
            if (!$redis->set('tiwen:round:refunded:' . $roundId, '1', ['nx', 'ex' => self::ROUND_KEY_TTL])) {
                return;
            }
            $this->refundAsks($uid, $charged);
        } catch (\Throwable $e) {
            logger()->warning('tiwen settleRoundStream: ' . $e->getMessage());
        }
    }

    /**
     * 返还免费版当日提问次数(仅免费套餐且当日计数有效时)
     */
    public function refundAsks(int $uid, int $count): void {
        $this->rollQuota($uid);
        $member = $this->ensureMember($uid);
        if ((string) ($member['plan'] ?? 'free') !== 'free') {
            return;
        }
        if ((string) ($member['daily_ask_date'] ?? '') !== date('Y-m-d')) {
            return;
        }
        // 只返还已计提的部分,避免减成负数
        $refund = min(max(1, $count), (int) $member['daily_asks']);
        if ($refund <= 0) {
            return;
        }
        m('tiwen_members')->where('uid', $uid)->decrement('daily_asks', $refund);
    }

    /**
     * 跨天/跨月滚动重置计数与配额;一次性包会员期满自动降级免费(积分余额保留)
     */
    public function rollQuota(int $uid): void {
        $member = $this->ensureMember($uid);
        if (!$member) {
            return;
        }
        $today = date('Y-m-d');
        $update = [];

        // 免费版每日计数跨天清零
        if ((string) ($member['daily_ask_date'] ?? '') !== $today) {
            $update['daily_asks'] = 0;
            $update['daily_ask_date'] = $today;
        }

        // 月度 Token 配额跨月重置
        $resetDate = (string) ($member['quota_reset_date'] ?? '');
        if ($resetDate !== '' && $resetDate < $today) {
            $update['tokens_used'] = 0;
            $update['quota_reset_date'] = date('Y-m-01', strtotime('+1 month'));
        }

        if ($update) {
            m('tiwen_members')->where('uid', $uid)->update($update);
        }

        // 一次性包(赠送会员期)到期:套餐回落免费,积分余额与其有效期不受影响
        $periodEnd = (int) ($member['current_period_end'] ?? 0);
        if (
            (string) ($member['plan_interval'] ?? '') === 'one_time'
            && (string) ($member['plan'] ?? 'free') !== 'free'
            && $periodEnd > 0
            && $periodEnd < time()
        ) {
            $this->downgradeToFree($uid);
        }
    }

    /**
     * 开通/续期套餐(Stripe 支付成功回调)
     */
    public function grantPlan(int $uid, string $plan, string $interval, array $extra = []): void {
        $this->ensureMember($uid);
        $conf = $this->planConfig($plan);
        $update = [
            'plan' => $plan,
            'plan_interval' => $interval,
            'tokens_quota' => (int) ($conf['tokens_quota'] ?? 0),
        ];
        // 新订阅周期重置用量(续费同周期则保留)
        $member = m('tiwen_members')->where('uid', $uid)->one() ?: [];
        $periodEnd = (int) ($extra['current_period_end'] ?? 0);
        if ($periodEnd > (int) ($member['current_period_end'] ?? 0)) {
            $update['tokens_used'] = 0;
        }
        if (!empty($extra['stripe_customer_id'])) {
            $update['stripe_customer_id'] = (string) $extra['stripe_customer_id'];
        }
        if (!empty($extra['stripe_subscription_id'])) {
            $update['stripe_subscription_id'] = (string) $extra['stripe_subscription_id'];
        }
        if (array_key_exists('subscription_status', $extra)) {
            $update['subscription_status'] = (string) $extra['subscription_status'];
        }
        if ($periodEnd > 0) {
            $update['current_period_end'] = $periodEnd;
        }
        m('tiwen_members')->where('uid', $uid)->update($update);
    }

    /**
     * 开通一次性包(旗舰包等,Stripe 单笔支付成功回调)
     * 权益:积分(含加赠)入账走 consume 账本——每笔赠送自带 expired_at,多次购买各批次独立到期,
     * 到期冲销由 consume 的 Crontab 统一处理;赠送 Pro 期内享套餐级月度算力,到期由 rollQuota 降级
     * @param array $extra 支付上下文:stripe_customer_id/payment_ref(用作幂等 uniqid)
     */
    public function grantOneTime(int $uid, string $plan, array $extra = []): void {
        $this->ensureMember($uid);
        $conf = $this->planConfig($plan);
        if (!$conf) {
            return;
        }
        $credits = (int) round((float) ($conf['credits'] ?? 0) * (1 + ((int) ($conf['bonus_percent'] ?? 0) / 100)));
        $giftMonths = (int) ($conf['gift_pro_months'] ?? 0);
        $validityMonths = max(1, (int) ($conf['validity_months'] ?? 24));

        $update = [
            'plan' => $plan,
            'plan_interval' => 'one_time',
            // 赠送会员期内按套餐配额享算力(旗舰包默认 Pro 级);新购重置本月已用
            'tokens_quota' => (int) ($conf['tokens_quota'] ?? 0),
            'tokens_used' => 0,
            'subscription_status' => 'active',
            // 会员权益期:有赠送月数按赠送算,否则按积分有效期兜底
            'current_period_end' => $giftMonths > 0 ? strtotime("+{$giftMonths} months") : strtotime("+{$validityMonths} months"),
        ];
        if (!empty($extra['stripe_customer_id'])) {
            $update['stripe_customer_id'] = (string) $extra['stripe_customer_id'];
        }
        m('tiwen_members')->where('uid', $uid)->update($update);

        // 积分到账:以支付单号为幂等键入账,Webhook 与主动查单双通道重复触发也只记一次
        if ($credits <= 0) {
            return;
        }
        if (!app('?consume')) {
            logger()->warning('tiwen 一次性包积分未入账: consume 应用未安装, uid=' . $uid . ' plan=' . $plan);
            return;
        }
        $expiredAt = app('consume.rule')->convertExpire("+{$validityMonths} months");
        app('consume')->record('credit', $uid, $credits, 'tiwen_' . $plan, (string) ($extra['payment_ref'] ?? ''), [
            'app' => 'tiwen',
            'plan' => $plan,
            'gift_pro_months' => $giftMonths,
            'gift_monthly_credits' => (int) ($conf['gift_monthly_credits'] ?? 0),
        ], null, $expiredAt);
    }

    /**
     * 积分概况(个人页展示):余额取自 consume 账本 credit 币种,
     * expiresAt 为最近到期的未冲销批次日期;未安装 consume 或无积分时为零值
     */
    public function creditsOf(int $uid): array {
        if ($uid <= 0 || !app('?consume')) {
            return ['balance' => 0, 'expiresAt' => ''];
        }
        $balance = round((float) app('consume')->getCredit($uid), 2);
        $nextExpire = (string) m('member_consume')
            ->where(['uid' => $uid, 'currency' => 'credit'])
            ->where('amount', '>', 0)
            ->where('expired_settled', 0)
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', date('Y-m-d H:i:s'))
            ->order('expired_at asc')
            ->value('expired_at');
        return [
            'balance' => $balance > 0 ? $balance : 0,
            'expiresAt' => $nextExpire !== '' ? substr($nextExpire, 0, 10) : '',
        ];
    }

    /**
     * 降级为免费套餐(订阅取消/失效)
     */
    public function downgradeToFree(int $uid): void {
        $free = $this->planConfig('free');
        m('tiwen_members')->where('uid', $uid)->update([
            'plan' => 'free',
            'plan_interval' => '',
            'tokens_quota' => (int) ($free['tokens_quota'] ?? 0),
            'subscription_status' => 'canceled',
        ]);
    }

    public function planConfig(string $plan): array {
        return (array) ((new SettingService())->plans()[$plan] ?? []);
    }

    /**
     * 按 Stripe customer 反查 uid
     */
    public function uidByCustomer(string $customerId): int {
        if ($customerId === '') {
            return 0;
        }
        return (int) (m('tiwen_members')->where('stripe_customer_id', $customerId)->value('uid') ?? 0);
    }
}
