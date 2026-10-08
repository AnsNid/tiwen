<?php

namespace App\user\Controller\Admin;

use App\admin\Authorization;

/**
 * 会员足迹
 */

class Trace extends Authorization {

    /**
     * 获取类型
     */
    public function getTypes() {

        $keyword = input('keyword');
        $types = [
            'login',
            'register',
            'recharge',
        ];

        $user_trace_types = $this->config->sysget('config.user_trace_types', []);
        $user_trace_types = array_column($user_trace_types ?: [], 'value');

        $data = [];
        foreach ($types as $value) {
            // 已设置的过滤掉
            if ($user_trace_types && in_array($value, $user_trace_types)) {
                continue;
            }

            $data[] = [
                'value' => $value,
            ];
        }

        if ($keyword) {
            $data = array_filter($data, function ($value) use ($keyword) {
                return strpos($value['value'], $keyword) !== false;
            });
        }

        show_json([
            'code' => 200,
            'data' => array_values($data),
        ]);
    }


    /**
     * 构造函数
     */
    public function lists() {

        $type = input('type');
        $uid = input('uid');
        $date = input('date');
        $page = input('page', 1);
        $limit = input('limit', 20);
        $export = input('export', 0);
        $uin = input('uin');
        $relatedid = input('relatedid');

        $query = m('user.trace')->query();

        if ($type && $type != 'all') {
            $query->where(['type' => $type]);
            if ($relatedid) {
                $query->where('relatedid', $relatedid);
            }
        }

        if ($uid > 0 || $uin > 0) {
            $query->where(function ($query) use ($uid, $uin) {
                $uid > 0 && $query->orWhere('uid', (int)$uid);
                $uin > 0 && $query->orWhere('uin', (int)$uin);
            });
        }


        if ($date) {
            $date = is_array($date) ? $date : explode(',', $date);
            $date = array_map(function ($item) {
                return date('Y-m-d H:i:s', strtotime($item));
            }, $date);
            $date[1] = xphp('date')->make($date[1])->format('Y-m-d 23:59:59');
            $query->whereTime('updated_at', 'between', $date);
        }

        $query->limit($limit)->offset(($page - 1) * $limit);

        $data = $query
            ->order('id desc')
            ->get()
            ->toArray();

        $uins = array_column($data, 'uin');
        $user = app('user')->where(['uid' => array_column($data, 'uid')])->pluck('nickname,username', 'uid')->toArray();

        if ($uins && app('?uc')) {
            $uins = array_unique($uins);
            $ucuser = m('uc.members')->where(['uid' => $uins])->pluck('nickname,username', 'uid')->toArray();
        }

        $itmes = $this->config->sysget('config.user_trace_types', []);
        $itmes = array_column($itmes, 'event', 'value');

        $typeIds = [];
        foreach ($data as $value) {
            $event = $itmes[$value['type']] ?? $value['type'];
            $typeIds[$event][] = $value['relatedid'];
        }

        $typeData = [];
        foreach ($typeIds as $type => $value) {
            $typeData[$type] = $this->container->get('event')->dispatch('user.trace.' . $type . '@getInfo', $value);
        }

        foreach ($data as $key => &$value) {

            $nickname = $user[$value['uid']]->nickname ?: $user[$value['uid']]->username;
            if ($value['uin'] && isset($ucuser[$value['uin']])) {
                $nickname = $ucuser[$value['uin']]->nickname ?: $ucuser[$value['uin']]->username;
            }
            $data[$key]['nickname'] = $nickname ?: '未知用户';
            $data[$key]['avatar'] = avatar($value['uin'] ?: $value['uid'], 'small', $value['uin'] ? 1 : 0);
            $event = $itmes[$value['type']] ?? $value['type'];
            $value['content'] = $typeData[$event][$value['relatedid']]['content'] ?? '---';
            $value['url'] = $typeData[$event][$value['relatedid']]['url'] ?? '';
        }

        // 导出
        if ($export) {
            $header = [
                ['name' => 'ID', 'rowspan' => 1],
                ['name' => '用户UID', 'rowspan' => 1],
                ['name' => '用户UIN', 'rowspan' => 1],
                ['name' => '用户', 'rowspan' => 1],
                ['name' => '类型', 'rowspan' => 1],
                ['name' => '相关ID', 'rowspan' => 1],
                ['name' => '内容', 'rowspan' => 1],
                ['name' => '链接', 'rowspan' => 1],
                ['name' => '时间', 'rowspan' => 1],
            ];
            $body = [];
            foreach ($data as $key => $value) {
                $body[] = [
                    $value['id'],
                    $value['uid'],
                    $value['uin'],
                    $value['nickname'],
                    $value['type'],
                    $value['relatedid'],
                    $value['content'],
                    $value['url'],
                    $value['updated_at']
                ];
            }
            return app('excel')->export('会员足迹', $header, $body);
        }

        show_json([
            'code' => 200,
            'data' => [
                'data' => $data,
                'page' => $page,
                'limit' => $limit,
                'typeIds' => $typeIds,
                'count' => $query->count(),
            ],
        ]);
    }


    /**
     * 用户行为日志
     */
    public function log() {

        $token = input('token', '', 'decode');
        $uid = intval($token['uid'] ?? 0);
        $uin = intval($token['uin'] ?? 0);

        if (empty($uid) && empty($uin)) {
            show_json([
                'code' => 201,
                'message' => '参数错误',
            ]);
        }

        $list = m('user.trace')
            ->where(function ($q) use ($uid, $uin) {
                $uid && $q->orWhere('uid', $uid);
                $uin && $q->orWhere('uin', $uin);
            })
            ->limit(500)
            ->order('id desc')
            ->get()
            ->toArray();

        $itmes = $this->config->sysget('config.user_trace_types', []);
        $itmes = array_column($itmes, null, 'value');

        $typeIds = [];
        foreach ($list as $value) {
            $value['type'] = $itmes[$value['type']]['event'] ?? $value['type'];
            $typeIds[$value['type']][] = $value['relatedid'];
        }

        $typeData = [];
        foreach ($typeIds as $type => $value) {
            $typeData[$type] = $this->container->get('event')->dispatch('user.trace.' . $type . '@getInfo', $value);
        }

        foreach ($list as $key => $value) {
            $list[$key]['time'] = date('H:i a', strtotime($value['updated_at']));
            $list[$key]['date'] = date('Y-m-d', strtotime($value['updated_at']));
            $list[$key]['type'] = $itmes[$value['type']]['label'] ?? $value['type'];
            $event = $itmes[$value['type']]['event'] ?? $value['type'];
            $list[$key]['content'] = $typeData[$event][$value['relatedid']]['content'] ?? '---';
            $list[$key]['url'] = $typeData[$event][$value['relatedid']]['url'] ?? '';
        }

        show_json([
            'code' => 200,
            'data' => $list,
        ]);
    }


    /**
     * 获取表格信息
     */
    public function table() {

        $itmes = $this->config->sysget('config.user_trace_types', []);
        if (empty($itmes)) {
            $itmes = [
                ['value' => 'login', 'label' => '登录', 'event' => 'user.trace.login'],
                ['value' => 'register', 'label' => '注册', 'event' => 'user.trace.register'],
                ['value' => 'recharge', 'label' => '充值', 'event' => 'user.trace.recharge'],
            ];
        }

        $count = m('user.trace')
            ->where('updated_at', '>=', date('Y-m-d 00:00:00', strtotime('-7 day')))
            ->selectRaw('type, count(*) as count')
            ->groupBy('type')
            ->pluck('count', 'type')
            ->toArray();

        foreach ($itmes as $key => $value) {
            $itmes[$key]['num'] = (int) ($count[$value['value']] ?? 0);
        }

        $relatedid = input('relatedid', 0, 'intval');
        $type = input('type', '', 'trim');

        show_json([
            'code' => 200,
            'data' => [
                'api' => '/user/trace/lists',
                'params' => [
                    'type' => $type,
                    'relatedid' => $relatedid,
                    'date' => $relatedid ? [] : [
                        date('Y-m-d', strtotime('-1 month')),
                        date('Y-m-d'),
                    ],
                ],
                'column' => [
                    [
                        "label" => "ID",
                        "columntype" => "text",
                        "name" => "id",
                        "width" => 90,
                        'copy' => 'id',
                    ],
                    [
                        "label" => "用户",
                        "name" => "avatar",
                        "columntype" => "avatar",
                        "width" => 150,
                    ],
                    [
                        "label" => "类型",
                        'name' => 'type',
                        "columntype" => "status",
                        'width' => 150,
                        'options' => $itmes
                    ],
                    [
                        "label" => "相关ID",
                        "name" => "relatedid",
                        "columntype" => "text",
                        'width' => 110,
                        'copy' => 'relatedid',
                    ],
                    [
                        'label' => '内容',
                        'name' => 'content',
                        "columntype" => "input",
                        'open' => [
                            'name' => 'url',
                            'target' => '_blank',
                        ]
                    ],
                    [
                        "label" => "次数",
                        "name" => "num",
                        "columntype" => "text",
                        "width" => 90
                    ],
                    [
                        "label" => "时间",
                        "name" => "updated_at",
                        "width" => 160
                    ]
                ],
                'filter' => [
                    [
                        'label' => '类型',
                        'name' => 'type',
                        'component' => 'select',
                        'width' => '100%',
                        'bind' => [
                            'placeholder' => '请选择类型,可多选',
                            'clearable' => true,
                            'multiple' => true,
                            'filterable' => true,
                            'collapse-tags' => true,
                            'max-collapse-tags' => 5,
                        ],
                        'options' => $itmes
                    ],
                    [
                        "label" => "用户",
                        "name" => "uid",
                        'width' => 300,
                        "component" => "input",
                        "bind" => [
                            "placeholder" => "输入 UID 进行查询"
                        ]
                    ],
                    [
                        'label' => '操作时间',
                        'name' => 'date',
                        'component' => 'date',
                        'width' => 350,
                        'bind' => [
                            'placeholder' => '请选择时间',
                            'clearable' => true,
                            'type' => 'daterange',
                            'format' => "YYYY-MM-DD",
                            'value-format' => "YYYY-MM-DD"
                        ]
                    ],
                ],
                'operation' => [
                    'export' => [
                        'api' => '/user/trace/lists',
                        'showhandle' => '$.type.length>0 && $.date.length>0',
                        'blob' => true,
                        'showData' => true,
                        'limit' => 1000,
                        'filename' => '会员足迹',
                        'data' => [
                            'export' => 1
                        ]
                    ]
                ],
                'key' => 'id',
            ],
        ]);
    }

    /**
     * 会员行为足迹 - 专属独立全景数据看板
     * 路由: /admin/api/user/Trace/dashboard
     */
    public function dashboard() {
        $days = input('days', 7, 'intval');
        if (!in_array($days, [1, 7, 30, 90])) {
            $days = 7;
        }

        $todayStart = date('Y-m-d 00:00:00');
        if ($days === 1) {
            $startDate = $todayStart;
        } else {
            $startDate = date('Y-m-d 00:00:00', strtotime("-{$days} day"));
        }

        // 系统配置的类型映射
        $configuredTypes = $this->config->sysget('config.user_trace_types', []);
        if (empty($configuredTypes)) {
            $configuredTypes = [
                ['value' => 'login', 'label' => '用户登录', 'event' => 'user.trace.login'],
                ['value' => 'register', 'label' => '新用户注册', 'event' => 'user.trace.register'],
                ['value' => 'recharge', 'label' => '账户充值', 'event' => 'user.trace.recharge'],
            ];
        }
        $typeMap = [];
        foreach ($configuredTypes as $item) {
            if (!empty($item['value'])) {
                $typeMap[$item['value']] = $item;
            }
        }

        // 1. 核心 KPI 指标
        $periodSummary = m('user.trace')
            ->where('updated_at', '>=', $startDate)
            ->selectRaw('count(*) as total_events, count(distinct uid) as unique_uids')
            ->first();

        $totalPeriodEvents = (int) ($periodSummary->total_events ?? 0);
        $uniqueUidsCount = (int) ($periodSummary->unique_uids ?? 0);

        // 今日事件发生量
        $todayCount = (int) m('user.trace')
            ->where('updated_at', '>=', $todayStart)
            ->count();

        // 历史沉淀总记录数
        $totalAllRecords = (int) m('user.trace')->count();

        // 2. 行为事件热度排行榜 (按 type 分组聚合，统计所选时间窗口内发生量与去重会员数)
        $dbTypeStats = m('user.trace')
            ->where('updated_at', '>=', $startDate)
            ->whereNotNull('type')
            ->where('type', '<>', '')
            ->selectRaw('type, count(*) as num, count(distinct uid) as users, max(updated_at) as last_time')
            ->groupBy('type')
            ->orderByDesc('num')
            ->get()
            ->toArray();

        $labelMap = [
            'login' => '用户登录',
            'register' => '新用户注册',
            'recharge' => '账户充值',
            'order' => '商城下单',
            'pay' => '支付交易',
            'view' => '浏览访问',
            'publish' => '内容发布',
            'comment' => '发表评论',
            'like' => '点赞互动',
            'share' => '分享扩散',
            'sign' => '每日签到',
            'download' => '附件下载',
            'search' => '关键词检索',
        ];

        $mergedTypes = [];
        $seenValues = [];

        foreach ($dbTypeStats as $row) {
            $val = (string) $row['type'];
            $seenValues[$val] = true;
            $cfg = $typeMap[$val] ?? null;
            $num = (int) ($row['num'] ?? 0);
            $users = (int) ($row['users'] ?? 0);
            $pct = $totalPeriodEvents > 0 ? round(($num / $totalPeriodEvents) * 100, 1) : 0;

            $label = $cfg['label'] ?? '';
            if (empty($label)) {
                $label = $labelMap[strtolower($val)] ?? ucfirst($val);
            }

            $mergedTypes[] = [
                'value' => $val,
                'label' => $label,
                'event' => $cfg['event'] ?? "user.trace.{$val}",
                'num' => $num,
                'users' => $users,
                'pct' => $pct,
                'last_time' => $row['last_time'] ?? null,
            ];
        }

        // 补充配置中存在但在该时间窗口没有记录的类型 (置 0 展示)
        foreach ($typeMap as $val => $cfg) {
            if (!isset($seenValues[$val])) {
                $mergedTypes[] = [
                    'value' => $val,
                    'label' => $cfg['label'] ?? ucfirst($val),
                    'event' => $cfg['event'] ?? "user.trace.{$val}",
                    'num' => 0,
                    'users' => 0,
                    'pct' => 0,
                    'last_time' => null,
                ];
            }
        }

        // 3. 趋势走势 (按天或今日按小时)
        $dailyTrend = [];
        if ($days === 1) {
            $trendRaw = m('user.trace')
                ->where('updated_at', '>=', $startDate)
                ->selectRaw("DATE_FORMAT(updated_at, '%H:00') as log_date, count(*) as count, count(distinct uid) as users")
                ->groupBy('log_date')
                ->orderBy('log_date')
                ->get()
                ->keyBy('log_date')
                ->toArray();

            $currentHour = (int) date('H');
            for ($h = 0; $h <= 23; $h++) {
                $hStr = sprintf('%02d:00', $h);
                $dailyTrend[] = [
                    'date' => $hStr,
                    'count' => (int) ($trendRaw[$hStr]['count'] ?? 0),
                    'users' => (int) ($trendRaw[$hStr]['users'] ?? 0),
                    'is_future' => $h > $currentHour,
                ];
            }
        } else {
            $trendRaw = m('user.trace')
                ->where('updated_at', '>=', $startDate)
                ->selectRaw("DATE_FORMAT(updated_at, '%Y-%m-%d') as log_date, count(*) as count, count(distinct uid) as users")
                ->groupBy('log_date')
                ->orderBy('log_date')
                ->get()
                ->keyBy('log_date')
                ->toArray();

            for ($i = $days - 1; $i >= 0; $i--) {
                $dStr = date('Y-m-d', strtotime("-{$i} day"));
                $dailyTrend[] = [
                    'date' => $dStr,
                    'count' => (int) ($trendRaw[$dStr]['count'] ?? 0),
                    'users' => (int) ($trendRaw[$dStr]['users'] ?? 0),
                    'is_future' => false,
                ];
            }
        }

        // 4. 最新行为动态
        $latestTraces = m('user.trace')
            ->order('id desc')
            ->limit(6)
            ->get()
            ->toArray();

        if (!empty($latestTraces)) {
            $uids = array_unique(array_filter(array_column($latestTraces, 'uid')));
            $users = [];
            if (!empty($uids)) {
                $users = app('user')->where(['uid' => $uids])->pluck('nickname,username', 'uid')->toArray();
            }
            foreach ($latestTraces as &$lt) {
                $nick = $users[$lt['uid']]->nickname ?? $users[$lt['uid']]->username ?? '会员#' . $lt['uid'];
                $lt['nickname'] = $nick;
                $lt['avatar'] = avatar($lt['uin'] ?: $lt['uid'], 'small', $lt['uin'] ? 1 : 0);
                $lt['label'] = $typeMap[$lt['type']]['label'] ?? ($labelMap[strtolower($lt['type'])] ?? ucfirst($lt['type']));
            }
        }

        show_json([
            'code' => 200,
            'data' => [
                'kpi' => [
                    'total_period' => $totalPeriodEvents,
                    'total_7d' => $totalPeriodEvents,
                    'total_all' => $totalAllRecords,
                    'today_count' => $todayCount,
                    'unique_users_period' => $uniqueUidsCount,
                    'unique_users_7d' => $uniqueUidsCount,
                    'types_count' => count(array_filter($mergedTypes, fn($t) => $t['num'] > 0)) ?: count($mergedTypes),
                ],
                'ranking' => $mergedTypes,
                'ranking_7d' => $mergedTypes,
                'trend' => $dailyTrend,
                'daily_trend' => $dailyTrend,
                'latest_activities' => $latestTraces,
                'days' => $days,
            ],
        ]);
    }
}
