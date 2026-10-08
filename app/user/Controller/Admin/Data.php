<?php

namespace App\user\Controller\Admin;

use App\admin\Authorization;

/**
 * 会员数据表格
 */

class Data extends Authorization {

    /**
     * 获取表格信息
     */
    public function get() {

        $token = input('post.token', '', 'decode');

        $uid = intval($token['uid'] ?? 0);
        $uin = intval($token['uin'] ?? 0);

        if (empty($uid) && empty($uin)) {
            show_json([
                'code' => 200,
                'data' => [],
            ]);
        }


        $extendField = $this->container->get('config')->get('user@config.extendField', []); // 扩展字段
        $sectionField = $this->container->get('config')->get('user@config.sectionField', []); // 分组字段
        $allowRepeatedField = $this->container->get('config')->get('user@config.allowRepeatedField', []); // 允许记录多个的字段

        $fields = array_column($extendField, 'name') + array_keys($allowRepeatedField);
        $fields[] = 'lastlogintime';
        $fields[] = 'lastloginip';
        $fields = array_values(array_unique(array_filter($fields)));
        $listdata = m('user.data')
            ->where(function ($query) use ($uin, $uid) {
                if ($uin > 0) {
                    $query->orWhere('uin', '=', $uin);
                }
                if ($uid > 0) {
                    $query->orWhere('uid', '=', $uid);
                }
            })
            ->whereIn('field', $fields)
            ->select('field,value')
            ->get();

        $data = [];

        foreach ($listdata as $value) {
            if (is_string($value->value)) {
                $decoded = json_decode($value->value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value->value = $decoded;
                }
            }
            // 允许记录多个的
            if ($allowRepeatedField && isset($allowRepeatedField[$value->field])) {
                $data[$value->field][] = $value->value;
                continue;
            }
            $data[$value->field] = $value->value;
        }

        $section = [];
        if ($data['lastloginip'] && is_array($data['lastloginip'])) {
            $section[] = [
                'options' => [
                    'title' => '常用IP',
                    'column' => 5,
                    'size' => 'small',
                ],
                'data' => array_map(function ($ip) {
                    return ['value' => $ip];
                }, $data['lastloginip'] ?: [])
            ];
            unset($data['lastloginip']);
        }

        if (app('?consume')) {
            $currency = $this->container->get('config')->sysget('config.consume_currency', []);
            $consumebalance = app('consume')->___balance___([
                'uid' => $uid,
                'uin' => $uin,
            ]);
            if ($currency) {
                foreach ($currency as $key => $value) {
                    $currency[$key]['value'] = $consumebalance[$value['value']] ?? '0';
                }
                $section[] = [
                    'options' => [
                        'title' => '积分货币',
                        'column' => 5,
                    ],
                    'extra' => '',
                    'data' => $currency,
                ];
            }
        }


        if ($sectionField) {
            foreach ($sectionField as $key => $value) {
                $section[$key] = [
                    'options' => is_array($value) ? $value : [
                        'title' => $value,
                        'column' => 5,
                    ],
                    'data' => [],
                ];
            }
        }

        if ($extendField) {
            $extendFielddata = [];
            foreach ($extendField as $key => $value) {
                $name = !empty($value['name']) ? $value['name'] : $key;
                if (!isset($data[$name])) {
                    continue;
                }
                $value = is_array($value) ? $value : ['label' => $value];
                if ($value['section'] && isset($section[$value['section']])) {
                    $section[$value['section']]['data'][] = [
                        'label' => is_array($value) ? $value['label'] : $value,
                        'value' => $data[$name] ?? '',
                        'display' => $value['display'] ?? '',
                    ];
                    continue;
                }
                $extendFielddata[] = [
                    'label' => is_array($value) ? $value['label'] : $value,
                    'value' => $data[$name] ?? '',
                    'display' => $value['display'] ?? '',
                ];
            }

            if ($extendFielddata) {
                $section[] = [
                    'options' => [
                        'title' => '其他信息',
                        'column' => 5,
                    ],
                    'data' => $extendFielddata,
                ];
            }
        }

        show_json([
            'code' => 200,
            'data' => [
                'data' => $data,
                'section' => array_values($section),
                'medals' => app('?medal') ? app('medal')->getUserMedal((int)$uid, (int)$uin) : [],
                'basicInfo' => [
                    'uniqid' => '唯一ID',
                    'username' => '用户名',
                    'nickname' => '昵称',
                    'mobile' => '手机号码',
                    'email' => '邮箱',
                    'regtype' => '用户来源',
                    'regip' => '注册IP',
                ]
            ],
        ]);
    }

    /**
     * 会员数据看板-概览统计
     * 一次条件聚合扫描出全部指标(regdate 为 int 时间戳), 不再逐指标全表扫
     */
    public function dashboard() {

        $todayStart = strtotime(date('Y-m-d 00:00:00'));
        $now = time();
        $yesterdayStart = strtotime(date('Y-m-d 00:00:00', strtotime('-1 day')));
        $yesterdayEnd = $yesterdayStart + 86399;
        $weekStart = strtotime('monday this week 00:00:00');
        $monthStart = strtotime(date('Y-m-01 00:00:00'));

        // 单条 SQL 条件聚合: total/今日/昨日/本周/本月/状态/绑定 全部一次扫描完成
        $row = [];
        try {
            $row = (array) (m('user.api')->selectRaw(implode(', ', [
                'count(*) as total',
                'COALESCE(sum(regdate between ' . (int) $todayStart . ' and ' . (int) $now . '), 0) as today',
                'COALESCE(sum(regdate between ' . (int) $yesterdayStart . ' and ' . (int) $yesterdayEnd . '), 0) as yesterday',
                'COALESCE(sum(regdate between ' . (int) $weekStart . ' and ' . (int) $now . '), 0) as week',
                'COALESCE(sum(regdate between ' . (int) $monthStart . ' and ' . (int) $now . '), 0) as month',
                'COALESCE(sum(status = 1), 0) as status_normal',
                'COALESCE(sum(status = 0), 0) as status_disabled',
                "COALESCE(sum(mobile <> ''), 0) as mobile",
                "COALESCE(sum(email <> ''), 0) as email",
            ]))->get()->toArray()[0] ?? []);
        } catch (\Throwable) {
        }

        // 活跃统计 (lastlogintime 存在于 member_data 扩展字段或 user.trace 行为记录中)
        $countByActive = function ($startTs) {
            try {
                $count = m('user.data')
                    ->where('field', 'lastlogintime')
                    ->where('value', '>=', (string) $startTs)
                    ->count();
                if ($count > 0) {
                    return $count;
                }
            } catch (\Throwable $e) {}

            try {
                if (app('?user.trace')) {
                    $startDate = date('Y-m-d H:i:s', $startTs);
                    return m('user.trace')
                        ->where('updated_at', '>=', $startDate)
                        ->distinct()
                        ->count('uid');
                }
            } catch (\Throwable $e) {}

            return 0;
        };
        $active7d = $countByActive(strtotime(date('Y-m-d 00:00:00', strtotime('-6 days'))));
        $active30d = $countByActive(strtotime(date('Y-m-d 00:00:00', strtotime('-29 days'))));

        show_json([
            'code' => 200,
            'data' => [
                'total_users' => (int) ($row['total'] ?? 0),
                'today_users' => (int) ($row['today'] ?? 0),
                'yesterday_users' => (int) ($row['yesterday'] ?? 0),
                'week_users' => (int) ($row['week'] ?? 0),
                'month_users' => (int) ($row['month'] ?? 0),
                'status_normal' => (int) ($row['status_normal'] ?? 0),
                'status_disabled' => (int) ($row['status_disabled'] ?? 0),
                'mobile_count' => (int) ($row['mobile'] ?? 0),
                'email_count' => (int) ($row['email'] ?? 0),
                'active_7d' => (int) $active7d,
                'active_30d' => (int) $active30d,
            ],
        ]);
    }

    /**
     * 会员数据看板-增长趋势
     * 单次 GROUP BY 出全部天桶(原先按天循环 14~90 次查询)
     */
    public function trend() {
        $days = input('days/d', 14, 'intval');
        if ($days < 7) $days = 7;
        if ($days > 90) $days = 90;

        $start = strtotime(date('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days')));

        $rows = [];
        try {
            $rows = m('user.api')
                ->selectRaw("FROM_UNIXTIME(regdate, '%Y-%m-%d') as d, count(*) as c")
                ->where('regdate', '>=', $start)
                ->groupBy('d')
                ->get()
                ->toArray();
        } catch (\Throwable) {
        }

        $map = [];
        foreach ((array) $rows as $r) {
            $map[(string) $r['d']] = (int) $r['c'];
        }

        $trend = [];
        $periodUsers = 0;
        for ($i = $days - 1; $i >= 0; $i--) {
            $fullDate = date('Y-m-d', strtotime("-{$i} days"));
            $count = $map[$fullDate] ?? 0;
            $periodUsers += $count;
            $trend[] = [
                'date' => date('m-d', strtotime($fullDate)),
                'full_date' => $fullDate,
                'count' => $count,
            ];
        }

        show_json([
            'code' => 200,
            'data' => [
                'trend' => $trend,
                'period_users' => $periodUsers,
                'period_days' => $days,
            ],
        ]);
    }

    /**
     * 会员数据看板-分布统计(用户组/注册渠道/性别)
     */
    public function distributions() {

        // 用户组分布
        $groups = m('user.group')
            ->whereIn('status', [0, 1])
            ->order('id asc')
            ->select('id,title,type')
            ->get()
            ->toArray();

        $groupCounts = m('user.authority')
            ->groupBy('groupid')
            ->pluck('count(*)', 'groupid')
            ->toArray();

        $groupList = [];
        foreach ($groups as $g) {
            $groupList[] = [
                'id' => (int) $g['id'],
                'title' => $g['title'],
                'type' => $g['type'],
                'count' => (int) ($groupCounts[$g['id']] ?? 0),
            ];
        }

        // 注册渠道分布
        $regtypeCounts = m('user.api')
            ->selectRaw("COALESCE(NULLIF(regtype, ''), 'other') as type, count(*) as count")
            ->groupBy('type')
            ->orderBy('count', 'desc')
            ->get()
            ->toArray();

        $regtypes = array_map(function ($item) {
            return [
                'type' => $item['type'],
                'count' => (int) $item['count'],
            ];
        }, $regtypeCounts);

        // 性别分布 (1: 男, 2: 女, 0: 未设置)
        $genderRows = m('user.api')
            ->selectRaw('gender, count(*) as count')
            ->groupBy('gender')
            ->pluck('count', 'gender')
            ->toArray();

        show_json([
            'code' => 200,
            'data' => [
                'groups' => $groupList,
                'regtypes' => $regtypes,
                'genders' => [
                    'male' => (int) ($genderRows[1] ?? 0),
                    'female' => (int) ($genderRows[2] ?? 0),
                    'unknown' => (int) ($genderRows[0] ?? 0),
                ],
            ],
        ]);
    }

    /**
     * 会员数据看板-最新动态(最新注册会员 + 最新行为轨迹)
     */
    public function recent() {

        // 最新注册会员 (前 6 位)
        $recentUsers = m('user.api')
            ->order('uid desc')
            ->limit(6)
            ->field('uid,username,nickname,mobile,email,gender,status,regdate,regtype')
            ->get()
            ->toArray();

        foreach ($recentUsers as &$u) {
            $u['avatar'] = avatar($u['uid'], 'mini');
            $u['nickname'] = $u['nickname'] ?: $u['username'];
        }
        unset($u);

        // 最新行为轨迹 (前 8 条)
        $recentTraces = [];
        if (app('?user.trace')) {
            $traces = m('user.trace')
                ->order('id desc')
                ->limit(8)
                ->get()
                ->toArray();

            $traceUids = array_unique(array_filter(array_column($traces, 'uid')));
            $traceUserMap = [];
            if ($traceUids) {
                $traceUsers = m('user.api')
                    ->whereIn('uid', $traceUids)
                    ->field('uid,username,nickname')
                    ->get()
                    ->toArray();
                foreach ($traceUsers as $tu) {
                    $traceUserMap[$tu['uid']] = $tu['nickname'] ?: $tu['username'];
                }
            }

            foreach ($traces as $tr) {
                $recentTraces[] = [
                    'id' => $tr['id'],
                    'uid' => $tr['uid'],
                    'username' => $traceUserMap[$tr['uid']] ?? "UID {$tr['uid']}",
                    'type' => $tr['type'],
                    'relatedid' => $tr['relatedid'],
                    'time' => $tr['updated_at'] ?? $tr['created_at'] ?? '',
                ];
            }
        }

        show_json([
            'code' => 200,
            'data' => [
                'recent_users' => $recentUsers,
                'recent_traces' => $recentTraces,
            ],
        ]);
    }
}
