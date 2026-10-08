<?php

declare(strict_types=1);

namespace App\user\Controller\Admin;

use App\admin\Authorization;

/**
 * 会员数据表格
 */

class Table extends Authorization {
    /**
     * 获取表格信息
     */
    public function init() {

        $list = m('user.group')->order('id desc')->select('id,title,type')->where(['status' => [0, 1]])->get()->toArray();

        $authoritycount = m('user.authority')
            ->alias('a')
            ->join(m('user.api')->getTable() . ' as u', 'a.uid', '=', 'u.uid')
            ->groupBy('a.groupid')
            ->select('count(a.id) as count,a.groupid')
            ->get()
            ->toArray();

        $authoritycount = array_column($authoritycount, 'count', 'groupid');

        $list = $this->container->get('array')->orderby($list, 'type', SORT_DESC, 'anicount', SORT_ASC, 'id', SORT_ASC);

        $item = [
            'system' => ['color' => 'var(--el-color-primary)'],
            'default' => ['color' => 'var(--el-color-warning)'],
            'member' => ['color' => 'var(--el-color-danger)'],
        ];

        $items = [
            'all' => [
                ['value' => 0, 'label' => '全部', 'num' => 0, 'color' => 'var(--el-color-success)']
            ]
        ];

        foreach ($list as $key => $value) {
            $items[$value['type']][] = [
                'value' => $value['id'],
                'label' => $value['title'],
                'color' => $item[$value['type']]['color'],
                'num' => $authoritycount[$value['id']] ?? 0,
            ];
        }

        $regtypeList = $this->container->get('config')->sysget('config.user_regtypes');
        if (empty($regtypeList)) {
            $regtypeList = $this->container->get('config')->get('user@config.regtypeList', []);
        }

        $regtype = [];
        foreach ($regtypeList as $key => $item) {
            if (is_array($item) && isset($item['value'])) {
                $regtype[] = [
                    'value' => (string) $item['value'],
                    'label' => (string) ($item['label'] ?? $item['value']),
                ];
            } else {
                $regtype[] = [
                    'value' => (string) $key,
                    'label' => (string) $item,
                ];
            }
        }

        $listField = [];
        $extendField = $this->container->get('config')->get('user@config.extendField', []);
        if ($extendField) {
            foreach ($extendField as $key => $value) {
                if (isset($value['list']) && $value['list']) {
                    $listField[] = array_merge(["columntype" => 'input', "width" => 90, "name" => $key], $value);
                }
            }
        }

        // 货币
        if (app('?consume')) {
            $currency = $this->container->get('config')->sysget('config.consume_currency', []);
            $types = ['danger', 'warning',  'success', 'primary'];
            foreach ($currency as $key => $value) {
                $listField[] = [
                    "label" => $value['label'],
                    "name" => 'currency_' . $value['value'],
                    'width' => 90,
                    'columntype' => 'text',
                    'defaultValue' => '-',
                    'bind' => [
                        'size' => 'mini',
                        'link' => true,
                        'plain' => true,
                        'type' => $value['type'] ?? $types[$key % 4],
                    ],
                    'table' => [
                        'remoteurl' => 'consume/admin/detail',
                        'name' => '查看' . $value['label'] . '明细',
                        'params' => [
                            'currency' => $value['value'],
                            'uid' => ':uid',
                            'uin' => ':uin',
                        ],
                    ],
                    'refresh' => [
                        'url' => 'consume/admin/balance',
                        'name' => '刷新用户' . $value['label'] . '余额',
                        'params' => [
                            'uid' => ':uid',
                            'uin' => ':uin',
                            'currency' => $value['value']
                        ],
                    ],
                ];
            }
        }

        $defaultgroup = m('user.group')->where(['type' => 'default', 'status' => 1])->field('id as value,title as label')->select();
        $showhandle = [];
        foreach ($defaultgroup as $key => $value) {
            $showhandle[] = '$.groupid == ' . $value['value'];
        }
        $showhandle = implode(' || ', $showhandle);

        show_json([
            'code' => 200,
            'data' => [
                'api' => '/user/index/lists',
                'params' => [
                    'groupid' => 0,
                    'verifystatus' => 1
                ],
                'column' => array_merge([
                    [
                        "label" => "UID",
                        "columntype" => "text",
                        "name" => "uid",
                        "width" => 80,
                        'copy' => 'uid',
                        "component" => "text",
                        'showhandle' => '$.uid'
                    ],
                    [
                        "label" => "UIN",
                        "columntype" => "text",
                        "name" => "uin",
                        "width" => 80,
                        'copy' => 'uin',
                        "component" => "input",
                        'hide' => $this->container->get('config')->sysget('config.open_user_uin', 0) ? false : true,
                        'message' => '会员卡号 ID, 如论坛账号 UID',
                        'showhandle' => $this->container->get('config')->sysget('config.open_user_uin', 0) ? 'true' : 'false',
                    ],
                    [
                        "label" => "用户",
                        "name" => "avatar",
                        "columntype" => "avatar",
                        "width" => 160,
                        "options" => [
                            "subfield" => "nickname"
                        ]
                    ],
                    [
                        "label" => "用户名",
                        'name' => 'username',
                        'hide' => true,
                        'span' => 12,
                        "component" => "input",
                        'bind' => [
                            'autocomplete' => '---user',
                            'placeholder' => "用户名,不可为空.须唯一",
                        ]
                    ],
                    [
                        "label" => "用户昵称",
                        'name' => 'nickname',
                        'hide' => true,
                        'span' => 12,
                        "component" => "input",
                    ],
                    [
                        "label" => "密码",
                        'name' => 'password',
                        'hide' => true,
                        'span' => 12,
                        "component" => "input",
                        'bind' => [
                            'type' => 'password',
                            'placeholder' => "请输入密码",
                            'show-password' => true
                        ],
                        'message' => '如需修改密码请填写'
                    ],
                    [
                        "label" => "确认密码",
                        'name' => 'confirmpassword',
                        'hide' => true,
                        'span' => 12,
                        "component" => "input",
                        'bind' => [
                            'type' => 'password',
                            'placeholder' => "请输入密码",
                            'show-password' => true
                        ],
                        'showhandle' => '$.password'
                    ],
                    [
                        "label" => "手机",
                        'name' => 'mobile',
                        "component" => "input",
                        'columntype' => 'input',
                        'width' => 110,
                        'span' => 14,
                        'hide' => $this->container->get('config')->sysget('config.user_admin_show_mobile', 0) ? false : true,
                        'bind' => [
                            'placeholder' => '请输入手机号',
                            'clearable' => true,
                        ],
                    ],
                    [
                        "label" => "邮箱",
                        'name' => 'email',
                        'width' => 130,
                        'span' => 10,
                        'hide' => $this->container->get('config')->sysget('config.user_admin_show_email', 0) ? false : true,
                        "columntype" => "input",
                        "component" => "input",
                        'bind' => [
                            'placeholder' => '请输入邮箱',
                            'clearable' => true,
                            'icon' => 'el-icon-message'
                        ],
                    ],
                    [
                        "label" => "用户组",
                        'name' => 'groupid',
                        'hide' => true,
                        "component" => "selectgroup",
                        'span' => 8,
                        'options' => [
                            [
                                'label' => '普通用户组',
                                'options' => [
                                    [
                                        'value' => -1,
                                        'label' => '普通用户组(根据积分自动清算)'
                                    ],
                                ]
                            ],
                            [
                                'label' => '自定义用户组',
                                'options' => $defaultgroup ?? [],
                            ],
                        ],
                        'message' => '请选择用户组'
                    ],
                    [
                        "label" => "用户组有效期",
                        "name" => "groupexpiry",
                        "component" => "date",
                        'span' => 14,
                        'hide' => true,
                        'showhandle' => $showhandle,
                        'bind' => [
                            'type' => 'datetime',
                            'placeholder' => '不设置为永久有效',
                            'format' => 'YYYY-MM-DD HH:mm:ss',
                            'value-format' => 'YYYY-MM-DD HH:mm:ss',
                        ],
                        'message' => '用户组有效期, 到期后自动恢复为普通用户组, 默认为永久有效'
                    ],
                    [
                        "label" => "系统组",
                        'name' => 'authoritygroupid',
                        "component" => "xuserselect",
                        'hide' => true,
                        'bind' => [
                            'type' => 2,
                            'useAll' => true,
                            'config' => [
                                'column' => [
                                    [
                                        'label' => 'ID',
                                        'name' => 'id',
                                        'component' => 'text',
                                        'width' => 90,
                                    ],
                                    [
                                        'label' => '用户组名称',
                                        'name' => 'title',
                                        'component' => 'text',
                                    ],
                                    [
                                        'label' => '有效期',
                                        'name' => 'expiration',
                                        'component' => 'date',
                                        'bind' => [
                                            'type' => 'datetime',
                                            'placeholder' => '不设置为永久有效',
                                            'format' => 'YYYY-MM-DD HH:mm:ss',
                                            'value-format' => 'YYYY-MM-DD HH:mm:ss',
                                        ],
                                    ]
                                ]
                            ]
                        ]
                    ],
                    [
                        "label" => "用户组",
                        "name" => "group",
                        "columntype" => "input",
                        "minWidth" => 200,
                    ],
                ], $listField ?: [], [
                    [
                        "label" => "注册时间",
                        "name" => "regdate",
                        "columntype" => "time",
                        "width" => 108,
                    ],
                    [
                        "label" => "注册来源",
                        "name" => "regtype",
                        "columntype" => "button",
                        "width" => 130,
                        'options' => $regtype ?? [],
                        'bind' => [
                            'size' => 'mini',
                            'type' => 'info',
                            'plain' => true,
                            'link' => true,
                        ]
                    ],
                    [
                        "label" => "性别",
                        "name" => "gender",
                        "width" => 50,
                        "columntype" => "select",
                        "component" => "select",
                        'span' => 12,
                        "options" => [
                            ['value' => 1, 'label' => '男'],
                            ['value' => 2, 'label' => '女'],
                            ['value' => 0, 'label' => '保密'],
                        ]
                    ],
                    [
                        "label" => "状态",
                        "name" => "status",
                        "columntype" => "status",
                        "width" => 80,
                        'style' => [
                            'cursor' => 'pointer',
                        ],
                        "component" => "radio",
                        "options" => [
                            [
                                "type" => "success",
                                "value" => 1,
                                "label" => "正常",
                                'http' => [
                                    'url' => 'user/index/status',
                                    'key' => 'token',
                                    'tips' => '确定要下架锁定该用户',
                                    'type' => 'info',
                                    'params' => [
                                        'status' => 0
                                    ]
                                ],
                            ],
                            [
                                "type" => "info",
                                "value" => 0,
                                "label" => "待激活",
                                'http' => [
                                    'url' => 'user/index/status',
                                    'key' => 'token',
                                    'tips' => '确定要解锁该用户',
                                    'params' => [
                                        'status' => 1
                                    ]
                                ],
                            ]
                        ]
                    ],
                ]),
                'filter' => [
                    [
                        "label" => "用户 UID",
                        "name" => "uid",
                        "component" => "input",
                        "bind" => [
                            "placeholder" => "多个可以使用,隔开"
                        ]
                    ],
                    [
                        'label' => '注册时间',
                        'name' => 'regdate',
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
                    [
                        "label" => "最后登录",
                        "name" => "lastlogintime",
                        "component" => "date",
                        "operator" => "between",
                        "bind" => [
                            'placeholder' => '请选择时间',
                            'clearable' => true,
                            'type' => 'daterange',
                            'format' => "YYYY-MM-DD",
                            'value-format' => "YYYY-MM-DD"
                        ]
                    ],
                    [
                        "label" => "用户性别",
                        "name" => " gender",
                        "width" => 200,
                        "component" => "select",
                        "bind" => [
                            "placeholder" => "用户性别",
                        ],
                        "options" => [
                            ['value' => 1, 'label' => '男'],
                            ['value' => 2, 'label' => '女'],
                            ['value' => 0, 'label' => '保密'],
                        ]
                    ],
                    [
                        "label" => "手机号",
                        "name" => "mobile",
                        "component" => "input",
                        'width' => 400,
                        "bind" => [
                            "placeholder" => "输入用户手机号;如想查询 139 开头输入 139*"
                        ]
                    ],
                    [
                        "label" => "邮箱",
                        "name" => "email",
                        "component" => "input",
                        'width' => 400,
                        "bind" => [
                            "placeholder" => "模糊查询 如想查询 qq.com 后缀输入 *@qq.com"
                        ]
                    ],
                    [
                        "label" => "用户名",
                        "name" => "keyword",
                        "component" => "input",
                        'width' => 400,
                        "bind" => [
                            "placeholder" => "用户名或昵称;模糊搜索使用*号代替"
                        ]
                    ],
                    [
                        "label" => "注册来源",
                        "name" => "regtype",
                        "selected" => true,
                        "component" => "select",
                        "bind" => [
                            "placeholder" => "注册来源",
                        ],
                        "options" => $regtype ?? []
                    ],
                    [
                        "label" => "认证状态",
                        "name" => "verifystatus",
                        "selected" => true,
                        "width" => 180,
                        "component" => "switch",
                        "bind" => [
                            'active-text' => '已认证',
                            'inactive-text' => '未认证',
                            'active-value' => 1,
                            'inactive-value' => 0,
                            'inline-prompt' => true,
                        ]
                    ],
                ],
                'operation' => [
                    'edit' => [
                        'remoteurl' => '/user/index/updateTable',
                        'url' => '/user/index/submit',
                        'key' => 'token',
                    ],
                    'plus' => [
                        'url' => '/user/index/register',
                        'label' => '添加会员',
                        'data' => [
                            'status' => 1,
                            'authoritygroupid' => []
                        ]
                    ],
                    // 'export' => [],
                    'width' => 75
                ],
                'batch' => [
                    [
                        'url' => '/user/index/status',
                        'label' => '锁定',
                        'type' => 'danger',
                        'icon' => 'el-icon-Delete',
                        'data' => [
                            'status' => 0
                        ]
                    ],
                ],
                'key' => 'uid',
                'aside' => [
                    'title' => '会员列表',
                    'icon' => 'el-icon-user',
                    'key' => 'groupid',
                    'items' => $items
                ],
            ],
        ]);
    }
}
