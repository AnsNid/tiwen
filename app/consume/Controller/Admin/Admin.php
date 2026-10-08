<?php

namespace App\consume\Controller\Admin;

use App\admin\Authorization;
use xphp\DbConnection\Db;

/**
 *  消费管理
 */

class Admin extends Authorization {

    /**
     * 积分中心初始化: 一次性返回类型/币种字典、当日计数、规则与操作令牌
     */
    public function init() {

        $currencies = $this->config->sysget('config.consume_currency', []);

        $today = m('member_consume')
            ->groupBy('type')
            ->whereTime('created_at', 'between', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->pluck('count(*) as count', 'type')
            ->toArray();

        $types = $this->config->sysget('config.consume_types', []);
        array_unshift($types, ['label' => '全部', 'value' => 'all']);
        foreach ($types as $key => $value) {
            $types[$key]['num'] = $today[$value['value']] ?? 0;
        }

        show_json([
            'code' => 200,
            'data' => [
                'token' => encode(id()),
                'types' => $types,
                'currencies' => $currencies,
                'rule' => app('consume.rule')->getConfig(),
                'timestamp' => [
                    date('d') > 7 ? date('Y-m-01') : date('Y-m-d', strtotime('-7 days')),
                    date('Y-m-d'),
                ],
            ],
        ]);
    }


    /**
     * 积分统计看板
     *
     * 每个指标都要按 created_at 扫一遍区间内的全部流水, 故整体缓存 60 秒, 反复点刷新不重复扫描大表。
     * 缓存键带上区间天数与币种: 切换筛选口径时不会读到上一个口径的数。
     *
     * @param int $days 统计区间天数, 1-90
     * @param string $currency 币种, 空为全部
     */
    public function stats() {

        $days = min(90, max(1, input('days', 7, 'intval')));
        $currency = input('currency', '', 'trim');

        $currencies = array_column($this->config->sysget('config.consume_currency', []), 'value');
        if ($currency && $currencies && !in_array($currency, $currencies, true)) {
            $currency = '';
        }

        $data = xphp('cache')->remember('consume.admin.stats.' . $days . '.' . ($currency ?: 'all'), function () use ($days, $currency) {
            return $this->buildStats($days, $currency);
        }, 60);

        show_json([
            'code' => 200,
            'data' => $data,
        ]);
    }


    /**
     * 看板数据组装: 8 条聚合查询, 无循环内查询
     */
    private function buildStats(int $days, string $currency): array {

        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));
        $startAt = $from . ' 00:00:00';
        $endAt = $to . ' 23:59:59';

        // 环比口径: 紧邻的上一个等长区间
        $prevTo = date('Y-m-d', strtotime($from . ' -1 day'));
        $prevFrom = date('Y-m-d', strtotime($prevTo . ' -' . ($days - 1) . ' days'));

        // 收支拆分是看板的统一口径: 正向流水记发放, 负向记消耗(取绝对值), 一次聚合同时拿到两者。
        // 不用 SUM(amount) 当净额再反推: 那样看不出发放与消耗各自的规模, 而后者才是运营要盯的数
        $split = 'SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income, '
            . 'SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as expense, '
            . 'COUNT(*) as cnt, COUNT(DISTINCT uid) as users';

        // 1. 每日走势: 今日指标直接取最后一天, 不再单独查一次
        $dailyMap = array_column($this->rangeQuery($startAt, $endAt, $currency)
            ->selectRaw('DATE(created_at) as day, ' . $split)
            ->groupBy('day')
            ->get()
            ->toArray(), null, 'day');

        $daily = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $dayStr = date('Y-m-d', strtotime("-{$i} days"));
            $row = $dailyMap[$dayStr] ?? null;
            $daily[] = [
                'date' => $dayStr,
                'label' => date('m-d', strtotime($dayStr)),
                'income' => round((float) ($row['income'] ?? 0), 2),
                'expense' => round((float) ($row['expense'] ?? 0), 2),
                'count' => (int) ($row['cnt'] ?? 0),
                'users' => (int) ($row['users'] ?? 0),
            ];
        }

        // 2. 区间汇总与上一区间汇总(算环比)
        $period = $this->aggregate($startAt, $endAt, $currency);
        $prevPeriod = $this->aggregate($prevFrom . ' 00:00:00', $prevTo . ' 23:59:59', $currency);

        // 3. 按操作类型分布: 哪类事件在发放、哪类在消耗
        $typeRows = $this->rangeQuery($startAt, $endAt, $currency)
            ->selectRaw('type, ' . $split)
            ->groupBy('type')
            ->limit(50)
            ->get()
            ->toArray();
        usort($typeRows, fn($a, $b) => (int) $b['cnt'] <=> (int) $a['cnt']);

        $typeLabels = array_column($this->config->sysget('config.consume_types', []), 'label', 'value');
        $types = [];
        foreach (array_slice($typeRows, 0, 10) as $row) {
            $key = (string) ($row['type'] ?? '');
            $types[] = [
                'type' => $key,
                'label' => $typeLabels[$key] ?? ($key ?: '未分类'),
                'income' => round((float) $row['income'], 2),
                'expense' => round((float) $row['expense'], 2),
                'count' => (int) $row['cnt'],
                'users' => (int) $row['users'],
            ];
        }

        // 4. 按币种分布: 不受币种筛选影响, 选中某一币种时用来对照其余资产的体量
        $currencyRows = $this->rangeQuery($startAt, $endAt, '')
            ->selectRaw('currency, COUNT(*) as cnt, SUM(amount) as net, '
                . 'SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income, '
                . 'SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as expense')
            ->groupBy('currency')
            ->get()
            ->toArray();
        usort($currencyRows, fn($a, $b) => (int) $b['cnt'] <=> (int) $a['cnt']);

        $currencyLabels = array_column($this->config->sysget('config.consume_currency', []), 'label', 'value');
        $currencyStats = [];
        foreach ($currencyRows as $row) {
            $key = (string) ($row['currency'] ?? '');
            $currencyStats[] = [
                'currency' => $key,
                'label' => $currencyLabels[$key] ?? ($key ?: '未指定'),
                'income' => round((float) $row['income'], 2),
                'expense' => round((float) $row['expense'], 2),
                'net' => round((float) $row['net'], 2),
                'count' => (int) $row['cnt'],
            ];
        }

        // 5. 发放排行: 规则里的频次限制是否被绕过, 看头部用户拿了多少最直观
        $topRows = $this->rangeQuery($startAt, $endAt, $currency)
            ->selectRaw('uid, COUNT(*) as cnt, SUM(amount) as net, '
                . 'SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income')
            ->where('uid', '>', 0)
            ->groupBy('uid')
            ->order('income desc')
            ->limit(5)
            ->get()
            ->toArray();

        $uids = array_map('intval', array_column($topRows, 'uid'));
        $users = $uids ? app('user')->whereIn('uid', $uids)->pluck('nickname,username', 'uid')->toArray() : [];

        $topUsers = [];
        foreach ($topRows as $row) {
            $rowUid = (int) $row['uid'];
            $user = $users[$rowUid] ?? null;
            $topUsers[] = [
                'uid' => $rowUid,
                'nickname' => $user?->nickname ?: $user?->username ?: ('UID ' . $rowUid),
                'avatar' => avatar($rowUid, 'mini'),
                'income' => round((float) $row['income'], 2),
                'net' => round((float) $row['net'], 2),
                'count' => (int) $row['cnt'],
            ];
        }

        return [
            'range' => [
                'from' => $from,
                'to' => $to,
                'days' => $days,
                'prev_from' => $prevFrom,
                'prev_to' => $prevTo,
            ],
            'currency' => $currency,
            'today' => $daily[count($daily) - 1],
            'period' => $period,
            'prev_period' => $prevPeriod,
            'daily' => $daily,
            'types' => $types,
            'currencies' => $currencyStats,
            'top_users' => $topUsers,
            'expiry' => $this->expiryStats($currency),
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }


    /**
     * 区间流水查询基座: 统一的时间窗与币种过滤, 时间窗走 created_at 索引
     */
    private function rangeQuery(string $startAt, string $endAt, string $currency) {

        $query = m('member_consume')->whereTime('created_at', 'between', [$startAt, $endAt]);
        if ($currency) {
            $query->where('currency', $currency);
        }
        return $query;
    }


    /**
     * 区间收支汇总
     */
    private function aggregate(string $startAt, string $endAt, string $currency): array {

        $row = $this->rangeQuery($startAt, $endAt, $currency)
            ->selectRaw('SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END) as income, '
                . 'SUM(CASE WHEN amount < 0 THEN -amount ELSE 0 END) as expense, '
                . 'COUNT(*) as cnt, COUNT(DISTINCT uid) as users')
            ->one();

        $income = round((float) ($row['income'] ?? 0), 2);
        $expense = round((float) ($row['expense'] ?? 0), 2);

        return [
            'income' => $income,
            'expense' => $expense,
            'net' => round($income - $expense, 2),
            'count' => (int) ($row['cnt'] ?? 0),
            // COUNT(DISTINCT uid) 不能由每日值相加得到: 同一用户跨天活跃会被重复计数
            'users' => (int) ($row['users'] ?? 0),
        ];
    }


    /**
     * 到期额度统计, 列尚未迁移时返回 null 让看板隐藏该面板
     *
     * expired_at / expired_settled 由 Install/20260916_add_consume_expiry.sql 手工补(框架没有迁移器),
     * 代码先上线、ALTER 还没跑的窗口里直接查会让整块看板报错, 故先探一次列是否存在。
     * 探测结果按 300 秒缓存: 表结构只在发版时变, 没必要每次请求都查一遍 information_schema。
     */
    private function expiryStats(string $currency): ?array {

        $supported = xphp('cache')->remember('consume.admin.expiry_column', function () {
            try {
                return Db::connection()->getSchemaBuilder()->hasColumn('member_consume', 'expired_settled');
            } catch (\Throwable) {
                // hasColumn 仅 MySQL 驱动可靠支持, 探测失败按不支持处理
                return false;
            }
        }, 300);

        if (!$supported) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $soon = date('Y-m-d H:i:s', strtotime('+7 days'));

        // 即将到期: 走 idx_expired(expired_settled, expired_at), 首列等值把已冲销的历史排除在扫描之外
        $pending = m('member_consume')
            ->where('expired_settled', 0)
            ->where('amount', '>', 0)
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<=', $soon);
        if ($currency) {
            $pending->where('currency', $currency);
        }

        // 7 天窗口最多 8 个自然日, limit 只是兜底, 故汇总值直接由分桶结果相加, 省一条查询
        $bucketRows = $pending
            ->selectRaw('DATE(expired_at) as day, COUNT(*) as cnt, SUM(amount) as amount')
            ->groupBy('day')
            ->order('day asc')
            ->limit(10)
            ->get()
            ->toArray();

        $buckets = [];
        $pendingCount = 0;
        $pendingAmount = 0.0;
        foreach ($bucketRows as $row) {
            $amount = round((float) $row['amount'], 2);
            $buckets[] = [
                'date' => (string) $row['day'],
                'label' => date('m-d', strtotime((string) $row['day'])),
                'count' => (int) $row['cnt'],
                'amount' => $amount,
            ];
            $pendingCount += (int) $row['cnt'];
            $pendingAmount += $amount;
        }

        $settled = m('member_consume')->where('type', 'expire');
        if ($currency) {
            $settled->where('currency', $currency);
        }
        // 冲销流水的 amount 为负, 取反后与「即将到期」同为正向额度, 两个数才可比
        $settledRow = $settled->selectRaw('COUNT(*) as cnt, SUM(-amount) as amount')->one();

        return [
            'window_days' => 7,
            'pending_count' => $pendingCount,
            'pending_amount' => round($pendingAmount, 2),
            'settled_count' => (int) ($settledRow['cnt'] ?? 0),
            'settled_amount' => round((float) ($settledRow['amount'] ?? 0), 2),
            'buckets' => $buckets,
        ];
    }


    /**
     * 获取用户指定币种余额
     * @param int $uid 用户ID
     * @param int $uin 用户UIN
     * @param string $currency 币种
     */
    public function balance() {

        $uid = input('uid', 0, 'intval');
        $uin = input('uin', 0, 'intval');
        $currency = input('currency', 'money', 'trim');
        if (!$uid && !$uin) {
            show_json([
                'code' => 201,
                'message' => '请选择用户',
            ]);
        }
        $currencies = array_column($this->config->sysget('config.consume_currency', []), 'value');
        if ($currencies && !in_array($currency, $currencies, true)) {
            show_json([
                'code' => 201,
                'message' => '货币类型无效',
            ]);
        }
        $balance = app('consume')->___count___($currency, $uid, $uin);

        // 更新用户余额
        app('user.data')->set(['uid' => $uid, 'uin' => $uin], [
            'currency_' . $currency => $balance,
        ]);

        show_json([
            'code' => 200,
            'data' => $balance,
        ]);
    }


    /**
     * 批量获取用户指定币种余额
     * @param array $uids 用户ID列表
     * @param string $currency 币种
     */
    public function balances() {

        $currency = input('currency', 'money', 'trim');
        $uids = array_values(array_unique(array_filter(
            array_map('intval', (array) input('uids/a', [])),
            fn(int $uid): bool => $uid > 0
        )));

        if (empty($uids)) {
            show_json([
                'code' => 201,
                'message' => '请选择用户',
            ]);
        }
        if (count($uids) > 200) {
            show_json([
                'code' => 201,
                'message' => '单次最多查询 200 名用户',
            ]);
        }

        $currencies = array_column($this->config->sysget('config.consume_currency', []), 'value');
        if ($currencies && !in_array($currency, $currencies, true)) {
            show_json([
                'code' => 201,
                'message' => '货币类型无效',
            ]);
        }

        $balances = [];
        foreach ($uids as $uid) {
            $balance = app('consume')->___count___($currency, $uid);
            $balances[$uid] = $balance;

            // 会员列表的余额列读的是 user.data 里的 currency_ 缓存, 借此机会刷新
            app('user.data')->set(['uid' => $uid, 'uin' => 0], [
                'currency_' . $currency => $balance,
            ]);
        }

        show_json([
            'code' => 200,
            'data' => $balances,
        ]);
    }


    /**
     * 获取用户列表
     */
    public function userList() {
        $page = max(1, input('.page', 1, 'intval'));
        $limit = min(100, max(1, input('.limit', 20, 'intval')));
        $groupid = input('groupid', 0, 'intval');
        $keyword = input('keyword', '');

        $model = app('user')
            ->alias('u')
            ->field('u.uid,u.username,u.nickname,u.mobile')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->order('u.uid desc');

        if ($groupid) {
            $groups = m('user.group')->order('id desc')->pluck('title,type', 'id')->toArray();
            if (isset($groups[$groupid]) && $groups[$groupid]->type == 'system') {
                $model->join(m('user.authority')->getTable() . ' as a', 'a.uid', '=', 'u.uid')
                    ->where('a.groupid', $groupid);
            } else {
                $model->where('groupid', $groupid);
            }
        }

        if ($keyword) {
            $model->where(function ($query) use ($keyword) {
                $query->where('u.uid', $keyword)
                    ->orWhere('u.mobile', 'like', "%{$keyword}%")
                    ->orWhere('u.nickname', 'like', "%{$keyword}%");
            });
        }

        $count = (clone $model)->offset(0)->count();
        $list = $model->get()->toArray();

        foreach ($list as &$value) {
            $value['nickname'] = $value['uid'] . ' (' . ($value['nickname'] ?: $value['username'] ?: $value['mobile']) . ')';
        }

        show_json([
            'code' => 200,
            'data' => [
                'limit' => $limit,
                'page' => $page,
                'data' => $list,
                'count' => $count,
            ],
        ]);
    }

    /**
     * 获取发布数据
     */
    public function get() {
        show_json([
            'code' => 200,
            'data' => [
                'token' => encode(id()),
                'data' => [],
                'config' => [
                    'url' => '/consume/admin/submit',
                    'label' => '积分操作',
                    'data' => [
                        'type' => 'plus',
                        'currency' => '',
                    ],
                    'column' => [
                        [
                            'label' => '用户',
                            'name' => 'uid',
                            'component' => 'xuserselect',
                            'bind' => [
                                'placeholder' => '请输入用户',
                                'config' => [
                                    'column' => [
                                        ['label' => 'UID', 'name' => 'uid', 'component' => 'text', 'width' => 90],
                                        // ['label' => '手机', 'name' => 'mobile', 'component' => 'text', 'width' => 110],
                                        ['label' => '昵称', 'name' => 'nickname', 'component' => 'text'],
                                    ],
                                    'userProps' => [
                                        'key' => 'uid',
                                        'label' => 'nickname',
                                    ],
                                    'url' => '/consume/admin/userList',
                                    'roleUrl' => '/user/group/roleList',
                                ]
                            ]
                        ],
                        [
                            'label' => '货币',
                            'name' => 'currency',
                            'component' => 'select',
                            'bind' => [
                                'placeholder' => '请选择货币',
                            ],
                            'options' => $this->config->sysget('config.consume_currency', [])
                        ],
                        [
                            'label' => '金额',
                            'name' => 'amount',
                            'component' => 'number',
                            'message' => '请输入金额,如果为负数则为扣除',
                            'bind' => [
                                'placeholder' => '请输入金额',
                                'precision' => 2,
                                'step' => 1
                            ]
                        ],
                        [
                            'label' => '备注',
                            'name' => 'content',
                            'component' => 'input',
                            'bind' => [
                                'placeholder' => '请输入备注',
                            ]
                        ],
                    ]
                ]
            ],
        ]);
    }

    /**
     * 更改用户余额
     * @log
     */
    public function submit() {
        $token = input('post.token', '', 'decode');
        $info = input('post.info/a', []);

        if (empty($token)) {
            show_json([
                'code' => 201,
                'message' => '非法操作',
            ]);
        }

        $uids = array_values(array_unique(array_filter(
            array_map('intval', (array) ($info['uid'] ?? [])),
            fn(int $uid): bool => $uid > 0
        )));
        if (empty($uids)) {
            show_json([
                'code' => 201,
                'message' => '请选择用户',
            ]);
        }
        if (count($uids) > 1000) {
            show_json([
                'code' => 201,
                'message' => '单次最多操作 1000 名用户',
            ]);
        }

        $currencys = $this->config->sysget('config.consume_currency', []);
        $currencys = array_column($currencys, 'value');
        $currency = trim((string) ($info['currency'] ?? ''));

        if (empty($currency) || !in_array($currency, $currencys)) {
            show_json([
                'code' => 201,
                'message' => '请选择货币',
            ]);
        }

        $amount = $info['amount'] ?? null;
        if ($amount === null || $amount === '' || !is_numeric($amount)) {
            show_json([
                'code' => 201,
                'message' => '请输入金额',
            ]);
        }
        $amount = (float) $amount;
        if ($amount < 0 && !$this->config->sysget('config.consumeallow_negative', 0)) {
            show_json([
                'code' => 201,
                'message' => '系统未启用负数金额操作',
            ]);
        }

        $admin = $this->admin() ?? [];
        $adminUid = intval($admin['uid'] ?? 0);
        $content = (string) ($info['content'] ?? '');

        // 幂等键由 payload 派生, 不以客户端 token 为种子: 批次中途失败后重新载入表单会拿到
        // 新 token, 若以 token 为准, 重试会对已入账的用户二次发放
        sort($uids);
        $batchKey = id([
            $adminUid,
            $currency,
            number_format($amount, 2, '.', ''),
            $uids,
            $content,
        ]);

        $failed = [];
        foreach ($uids as $uid) {
            $record = app('consume')->record($currency, $uid, $amount, 'system', $batchKey, [
                'content' => $content,
                'uid' => $adminUid,
                'username' => (string) ($admin['username'] ?? ''),
            ]);
            if (empty($record)) {
                $failed[] = $uid;
            }
        }

        $succeeded = count($uids) - count($failed);

        if ($failed) {
            show_json([
                'code' => 201,
                'message' => '部分用户处理失败: 成功 ' . $succeeded . ' 人, 失败 ' . count($failed)
                    . ' 人 (UID: ' . implode(', ', array_slice($failed, 0, 20)) . ')',
                'data' => [
                    'succeeded' => $succeeded,
                    'failed' => $failed,
                ],
            ]);
        }

        show_json([
            'code' => 200,
            'message' => '操作成功, 已处理 ' . $succeeded . ' 名用户',
            'data' => [
                'succeeded' => $succeeded,
                'failed' => [],
            ],
        ]);
    }

    /**
     * 查询用户明细
     */
    public function detail() {

        $uid = input('.uid/d', 0, 'intval');
        $uin = input('.uin/d', 0, 'intval');

        $currency = input('.currency/s', '', 'trim');

        $user = app('user')->where(function ($query) use ($uid, $uin) {
            $uid && $query->orWhere('uid', $uid);
            $uin && $query->orWhere('uin', $uin);
        })->field('nickname,username,mobile,uid,uin')->one();

        $currencys = $this->config->sysget('config.consume_currency', []);
        $currencys = array_column($currencys, 'label', 'value');

        if (empty($user) && $uin && app('?uc')) {
            $user = m('uc.members')->where(['uid' => $uin])->field('nickname,username,mobile,uid as uin')->one();
        }

        if (empty($user)) {
            show_json([
                'code' => 201,
                'message' => '用户不存在',
            ]);
        }

        show_json([
            'code' => 200,
            'data' => [
                'api' => '/consume/admin/lists',
                'params' => [
                    'uid' => $uid,
                    'uin' => $uin,
                    'currency' => $currency,
                ],
                'key' => 'id',
                'name' => ($user['nickname'] ?: $user['username']) . ' (' . $user['uid'] . ') - ' . $currencys[$currency] . '明细',
                'column' => [
                    [
                        "label" => "ID",
                        "columntype" => "text",
                        "name" => "id",
                        "width" => 80,
                        'copy' => 'id',
                    ],
                    [
                        "label" => "类型",
                        'name' => 'type',
                        "columntype" => "status",
                        'options' => $this->config->sysget('config.consume_types', []),
                        'width' => 150,
                    ],
                    [
                        "label" => "货币",
                        'name' => 'currency',
                        "columntype" => "status",
                        'options' => $this->config->sysget('config.consume_currency', [])
                    ],
                    [
                        "label" => "金额",
                        'name' => 'amount',
                        "columntype" => "button",
                        'bind' => [
                            'type' => 'warning',
                            'size' => 'small',
                            'link' => true
                        ]
                    ],
                    [
                        "label" => "当时余额",
                        'name' => 'balance',
                        "columntype" => "button",
                        'bind' => [
                            'type' => 'info',
                            'size' => 'small',
                            'link' => true
                        ]
                    ],
                    [
                        "label" => "操作时间",
                        "name" => "created_at",
                        "width" => 150,
                    ],
                    [
                        "label" => "详情",
                        "name" => "data",
                        "columntype" => "button",
                        "width" => 65,
                        'fixed' => 'right',
                        'bind' => [
                            'label' => '详情',
                            'size' => 'small',
                        ],
                        'descriptions' => [
                            'title' => '详情',
                            'key' => 'data'
                        ],
                    ],
                ]
            ],
        ]);
    }

    /**
     * 列表
     */
    public function lists() {

        $page = max(1, input('.page/d', 1, 'intval'));
        $limit = min(100, max(1, input('.limit/d', 20, 'intval')));
        $uid = input('uid/d', 0, 'intval');
        $uin = input('uin/d', 0, 'intval');
        $timestamp = input('timestamp/a', []);
        $currency = input('currency/s', '');
        $amount = input('amount/s', '');
        $type = input('type/s', '');

        $model = m('member_consume')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->json(['data'])
            ->order('id desc');


        if ($uid > 0 || $uin > 0) {
            $model->where(function ($query) use ($uid, $uin) {
                $uid && $query->orWhere('uid', $uid);
                $uin && $query->orWhere('uin', $uin);
            });
        }

        if ($timestamp && count($timestamp) >= 2) {
            $startTime = date('Y-m-d 00:00:00', strtotime($timestamp[0]));
            $endTime = date('Y-m-d 23:59:59', strtotime($timestamp[1]));
            $model->whereTime('created_at', 'between',  [$startTime, $endTime]);
        }

        if ($currency) {
            $model->where('currency', $currency);
        }

        if ($amount) {
            $model->where('amount', $amount);
        }

        if ($type && $type != 'all') {
            $model->where('type', $type);
        }

        $list = $model->get()->toArray();
        $count = $model->offset(0)->count();

        $userIds = array_column($list, 'uid');
        $uins = array_column($list, 'uin');
        $users = app('user')->whereIn('uid', $userIds)->pluck('nickname,username,mobile', 'uid')->toArray();

        $ucuser = [];
        if ($uins && app('?uc')) {
            $uins = array_filter($uins);
            $ucuser = m('uc.members')->whereIn('uid', $uins)->pluck('nickname,username,mobile', 'uid')->toArray();
        }


        foreach ($list as &$value) {

            if ($value['uin'] && isset($ucuser[$value['uin']])) {
                $ucObj = $ucuser[$value['uin']];
                $nickname = $ucObj->nickname ?: $ucObj->username;
                $avatar = avatar($value['uin'], 'mini', 1);
            } else {
                $userObj = $users[$value['uid']] ?? null;
                $nickname = $userObj?->nickname ?: $userObj?->username ?: '';
                $avatar = avatar($value['uid'], 'mini');
            }

            $value['avatar'] = $avatar;
            $value['nickname'] = $nickname;
            $value['token'] = encode(array_filter([
                'uid' => $value['uid'],
                'uin' => $value['uin'],
            ]));
        }

        show_json([
            'code' => 200,
            'data' => [
                'limit' => $limit,
                'page' => $page,
                'data' => $list,
                'count' => $count,
            ],
        ]);
    }
}
