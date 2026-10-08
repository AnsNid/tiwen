<?php

namespace App\consume\Controller\Admin;

use App\admin\Authorization;

/**
 * 会员数据表格
 */
class Table extends Authorization {

    /**
     * 获取表格信息
     */
    public function init() {

        $items = $this->config->sysget('config.consume_types', []);
        // 在items前面添加一个全部选项
        array_unshift($items, ['label' => '全部', 'value' => 'all']);

        $count = m('member_consume')
            ->groupBy('type')
            ->where('created_at', 'between', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->pluck('count(*) as count', 'type')
            ->toArray();

        foreach ($items as $key => $value) {
            $items[$key]['num'] = $count[$value['value']] ?? 0;
        }

        show_json([
            'code' => 200,
            'data' => [
                'api' => '/consume/admin/lists',
                'params' => [
                    'type' => 'all',
                    'timestamp' => [
                        date('d') > 7 ? date('Y-m-01') : date('Y-m-d', strtotime('-7 days')),
                        date('Y-m-d'),
                    ]
                ],
                'column' => [
                    [
                        "label" => "ID",
                        "columntype" => "text",
                        "name" => "id",
                        "width" => 80,
                        'copy' => 'id',
                        'showhandle' => '$.id'
                    ],
                    [
                        "label" => "用户",
                        "name" => "avatar",
                        "columntype" => "avatar",
                        "width" => 150,
                        "options" => [
                            "subfield" => "nickname"
                        ]
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
                        'fixed' => 'right',
                        "width" => 65,
                        'bind' => [
                            'label' => '详情',
                            'size' => 'small',
                        ],
                        'descriptions' => [
                            'title' => '详情',
                            'key' => 'data'
                        ],
                    ],
                ],
                'filter' => [
                    [
                        "label" => "用户",
                        "name" => "uid",
                        'width' => 250,
                        "component" => "input",
                        "bind" => [
                            "placeholder" => "输入用户名或手机号 UID 进行查询"
                        ]
                    ],
                    [
                        "label" => "金额",
                        "name" => "amount",
                        "component" => "input",
                        "bind" => [
                            "placeholder" => "输入金额进行查询"
                        ]
                    ],
                    [
                        "label" => "货币",
                        "name" => "currency",
                        "component" => "select",
                        "bind" => [
                            "placeholder" => "请选择货币",
                            "clearable" => true
                        ],
                        'options' => $this->config->sysget('config.consume_currency', [])
                    ],
                    [
                        'label' => '操作时间',
                        'name' => 'timestamp',
                        'component' => 'date',
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
                    'plus' => [
                        // 远程链接
                        'remoteurl' => '/consume/admin/get',
                    ],
                ],
                'key' => 'id',
                'aside' => [
                    'title' => '积分明细',
                    'icon' => 'el-icon-user',
                    'key' => 'type',
                    'items' => $items
                ],
            ],
        ]);
    }
}