<?php

namespace App\consume\Controller\Admin;

use App\admin\Authorization;

/**
 * 补全
 * Class Autocomplete
 */
class Autocomplete extends Authorization {

    /**
     * 货币
     */
    public function currency() {
        $keyword = input('.keyword');

        $types = m('member_consume')
            ->groupBy('currency')
            ->pluck('currency')
            ->toArray();

        $types = array_unique(array_merge($types ?: [], [
            'money',
            'score',
            'point',
            'diamond',
        ]));

        $consume_currency = $this->config->sysget('config.consume_currency', []);
        $consume_currency = array_column($consume_currency, 'value');

        $data = [];
        foreach ($types as $value) {
            // 已设置的过滤掉
            if ($consume_currency && in_array($value, $consume_currency)) {
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
     * 操作类型
     */
    public function types() {

        $keyword = input('.keyword');

        $types = m('member_consume')
            ->groupBy('type')
            ->pluck('type')
            ->toArray();

        $types = array_unique(array_merge($types ?: [], [
            'system',
            'recharge',
        ]));

        $consume_types = $this->config->sysget('config.consume_types', []);
        $consume_types = array_column($consume_types, 'value');

        $data = [];
        foreach ($types as $value) {
            // 已设置的过滤掉
            if ($consume_types && in_array($value, $consume_types)) {
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
}