<?php

namespace App\user\Controller\Admin;

use App\admin\Authorization;

/**
 * 会员相关
 */

class Index extends Authorization {
    /**
     * 同步会员, 并打开表单
     * @log
     *
     */
    public function syncMember() {
        $token = input('token', '', 'decode');

        $uin = intval($token['uin'] ?? 0);

        if (empty($token) || empty($uin)) {
            show_json([
                'code' => 201,
                'message' => '参数错误',
                'data' => $token,
            ]);
        }

        // 如果已经有 uid, 则直接返回
        if (!empty($token['uid'])) {
            show_json([
                'code' => 201,
                'message' => '用户已存在',
            ]);
        }
        // 查询 uin是否已存在
        $isUid = app('user')->where('uin', $uin)->value('uid');
        if ($isUid) {
            show_json([
                'code' => 201,
                'message' => '用户已存在,已被绑定到 uid:' . $isUid,
            ]);
        }

        if (!app('?uc')) {
            showmsg('uc 模块未安装, 请先安装 uc 模块');
        }

        $openUinLogin = $this->container->get('config')->sysget('config.open_user_uin', 0);
        if (!$openUinLogin) {
            show_json([
                'code' => 201,
                'message' => '未开启 uin 登录, 请先开启 uin 登录',
            ]);
        }

        // 查询 UC 是否有该用户
        $ucUser = m('uc.members')->where('uid', $uin)->one();
        if (!$ucUser) {
            show_json([
                'code' => 201,
                'message' => 'UC 中不存在该用户',
            ]);
        }

        // 准备开始同步
        $data = array_filter([
            'uniqid' => id(['uc', (int)$ucUser['uid']]),
            'uin' => $ucUser['uid'],
            'username' => $ucUser['username'] ?? '',
            'nickname' => $ucUser['nickname'] ?? '',
            'mobile' => $ucUser['mobile'] ?? '',
            'email' => $ucUser['email'] ?? '',
            'password' => null,
            'regip' => $ucUser['regip'] ?? '',
            'regdate' => $ucUser['regdate'] ?? 0,
            'regtype' => $ucUser['regtype'] ?? 0,
            'status' => 1
        ]);

        $uniqiduser = app('user')->where('uniqid', $data['uniqid'])->value('uid');
        if ($uniqiduser) {
            show_json([
                'code' => 201,
                'message' => 'uniqid 已存在,已被绑定到 uid:' . $uniqiduser,
            ]);
        }

        if (!($uid = app('user')->insertGetId($data))) {
            show_json([
                'code' => 201,
                'message' => '注册失败',
            ]);
        }

        app('user.data')->set(['uid' => $uid, 'uin' => $data['uin']], array_filter([
            'lastloginip' => $ucUser['lastloginip'] ?? '',
            'lastlogintime' => $ucUser['lastlogintime'] ?? 0,
        ]));
        show_json([
            'code' => 200,
            'message' => '注册成功',
            'data' => [
                'uid' => $uid,
                'uin' => $data['uin'],
                'uniqid' => $data['uniqid'],
                'token' => encode(['uid' => $uid, 'uin' => $data['uin']]),
            ],
        ]);
    }



    /**
     * 列表及配置
     */
    public function typeList() {

        show_json([
            'code' => 200,
            'data' => [],
        ]);
    }

    /**
     * 验证密码强度
     */
    public function validatePasswordStrength($password) {
        // // 检查密码长度
        // if (strlen($password) < 8) {
        //     exception("密码长度必须至少为8个字符。");
        // }

        // // 检查是否包含大写字母
        // if (!preg_match('/[A-Z]/', $password)) {
        //     exception("密码必须包含至少一个大写字母。");
        // }

        // // 检查是否包含小写字母
        // if (!preg_match('/[a-z]/', $password)) {
        //     exception("密码必须包含至少一个小写字母。");
        // }

        // // 检查是否包含数字
        // if (!preg_match('/[0-9]/', $password)) {
        //     exception("密码必须包含至少一个数字。");
        // }

        // // 检查是否包含特殊字符
        // if (!preg_match('/[\W]/', $password)) {
        //     exception("密码必须包含至少一个特殊字符。");
        // }
    }


    /**
     * 注册新用户
     * @log
     */
    public function register() {

        $info = input('post.info', []);
        try {
            $rule = [];
            $messages = [
                'uin.regex' => 'UIN必须为数字',
                'username.between' => '用户名长度须在2到50个字符之间',
                'nickname.max' => '昵称长度不能大于100个字符',
                'mobile.regex' => '手机号格式不正确',
                'email.regex' => '邮箱格式不正确',
                'password.min' => '密码长度不能小于6个字符',
                'password.max' => '密码长度不能大于32个字符',
            ];

            if (!empty($info['uin'])) {
                $rule['uin'] = 'regex:/^\d+$/';
            }
            if (!empty($info['username'])) {
                $rule['username'] = 'between:2,50';
            }
            if (!empty($info['nickname'])) {
                $rule['nickname'] = 'max:100';
            }
            if (!empty($info['mobile'])) {
                $rule['mobile'] = 'regex:/^1[3456789]\d{9}$/';
            }
            if (!empty($info['email'])) {
                $rule['email'] = 'regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
            }
            if (!empty($info['password'])) {
                $rule['password'] = 'min:6|max:32';
            }

            if (!empty($rule)) {
                $validator = $this->container->get('validation')->make($info, $rule, $messages);
                if ($validator->fails()) {
                    show_json([
                        'code' => 201,
                        'message' => $validator->errors()->first(),
                    ]);
                }
            }

            // 验证用户名是否存在
            if (!empty($info['username'])) {
                if (app('user')->where('username', trim($info['username']))->count()) {
                    exception('用户名已存在,请更换!');
                }
            }

            // 验证手机号是否存在
            if (!empty($info['mobile'])) {
                if (app('user')->where('mobile', trim($info['mobile']))->count()) {
                    exception('手机号已被占用!');
                }
            }

            // 验证邮箱是否存在
            if (!empty($info['email'])) {
                if (app('user')->where('email', trim($info['email']))->count()) {
                    exception('邮箱已被占用!');
                }
            }

            // 验证密码强度
            if (!empty($info['password'])) {
                $this->validatePasswordStrength($info['password']);
            }
        } catch (\Throwable $th) {
            isJsonException($th);
            show_json([
                'code' => 201,
                'message' => $th->getMessage(),
                'data' => [],
            ]);
        }

        // 若未提供用户名，自动生成一个全局唯一的默认账号
        $username = !empty($info['username']) ? trim($info['username']) : ('u_' . strtolower(substr(uniqid(), -8)));
        while (app('user')->where('username', $username)->count() > 0) {
            $username = 'u_' . strtolower(substr(uniqid() . mt_rand(10, 99), -8));
        }

        $data = [
            'uin' => !empty($info['uin']) ? (int)$info['uin'] : 0,
            'username' => $username,
            'nickname' => !empty($info['nickname']) ? trim($info['nickname']) : '',
            'mobile' => !empty($info['mobile']) ? trim($info['mobile']) : null,
            'email' => !empty($info['email']) ? trim($info['email']) : null,
            'gender' => isset($info['gender']) ? (int)$info['gender'] : 0,
            'status' => isset($info['status']) ? ($info['status'] ? 1 : 0) : 1,
            'regtype' => !empty($info['regtype']) ? trim($info['regtype']) : 'admin',
            'password' => !empty($info['password']) ? $info['password'] : null,
            'groupid' => isset($info['groupid']) ? (int)$info['groupid'] : -1,
            'groupexpiry' => !empty($info['groupexpiry']) ? strtotime($info['groupexpiry']) : 0,
        ];

        $uid = app('user')->register($data);

        if ($uid) {
            // 管理组
            if (!empty($info['authoritygroupid']) && is_array($info['authoritygroupid'])) {
                $authorityInsert = [];
                foreach ($info['authoritygroupid'] as $value) {
                    if (!empty($value['id'])) {
                        $authorityInsert[] = [
                            'uid' => $uid,
                            'groupid' => $value['id'],
                            'expiration' => !empty($value['expiration']) ? strtotime($value['expiration']) : 0,
                        ];
                    }
                }
                $authorityInsert && m('user.authority')->insert($authorityInsert, true);
            }

            // 更新用户组
            if ($data['groupid'] > 0) {
                $groupdata = app('user.group')->update([
                    'uid' => $uid,
                    'groupid' => $data['groupid'],
                    'groupexpiry' => $data['groupexpiry'],
                ]);

                if ($groupdata) {
                    $data = array_merge($data, $groupdata ?: []);
                }
            }

            app('user.data')->set($uid, $this->container->get('array')->only(array_filter($data), ['username', 'nickname', 'mobile', 'email']));
            show_json([
                'code' => 200,
                'message' => '操作成功',
                'data' => [
                    'uid' => $uid,
                ],
            ]);
        }
        show_json([
            'code' => 201,
            'message' => '操作失败',
        ]);
    }

    /**
     * 用户资料编辑提交
     * @log
     */
    public function submit() {

        $token = input('post.token', '', 'decode');
        $info = input('post.info', []);

        if (empty($info) || !is_array($info)) {
            show_json([
                'code' => 201,
                'message' => '参数错误',
            ]);
        }

        $uid = (int) ($token['uid'] ?? $info['uid'] ?? input('post.uid', 0));
        $model = app('user')->where(['uid' => $uid]);
        $userinfo = $model->one();
        if (empty($userinfo)) {
            show_json([
                'code' => 201,
                'message' => '用户不存在',
                'data' => [],
            ]);
        }

        if (empty($info['editpassword'])) {
            $info['password'] = '';
        }

        $data = [
            'uin' => (int)$info['uin'],
            'username' => $info['username'],
            'nickname' => trim($info['nickname']),
            'mobile' => trim($info['mobile']),
            'email' => trim($info['email']),
            'gender' => (int)$info['gender'],
            'status' => $info['status'] ? 1 : 0,
            'groupid' => (int)$info['groupid'],
            'groupexpiry' => $info['groupexpiry'] ? strtotime($info['groupexpiry']) : 0,
        ];


        try {
            $rule = [
                // 正则整数
                'uin' => 'regex:/^\d+$/',
                'username' => 'required|between:3,50',
                'nickname' => 'max:100|min:2',
                'mobile' => 'regex:/^1[3456789]\d{9}$/',
                'email' => 'regex:/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/',
                'password' => 'min:6|max:32|confirmed',
                'status' => 'required|in:0,1',
            ];

            $validator = $this->container->get('validation')
                ->make(array_merge($info, $data), $rule, [
                    'uin.regex' => 'UIN格式错误',
                    'username.required' => '用户名不能为空',
                    'username.alpha_dash' => '用户名只能包含字母、数字、下划线和破折号',
                    'username.between' => '用户名长度必须在3到50个字符之间',
                    'nickname.max' => '昵称长度不能大于100个字符',
                    'nickname.min' => '昵称长度不能小于2个字符',
                    'mobile.regex' => '手机号格式不正确',
                    'email.regex' => '邮箱格式不正确',
                    'password.min' => '密码长度不能小于6个字符',
                    'password.max' => '密码长度不能大于32个字符',
                    'password.confirmed' => '密码不一致',
                ]);

            if ($validator->fails()) {
                show_json([
                    'code' => 201,
                    'message' => $validator->errors()->first(),
                    'data' => [
                        'errors' => $validator->errors(),
                        'data' => $data
                    ]
                ]);
            }


            // 如果更改密码, 验证密码是否为强密码
            if ($info['password']) {
                $this->validatePasswordStrength($info['password']);
            }
        } catch (\Throwable $th) {


            isJsonException($th);

            show_json([
                'code' => 201,
                'message' => $th->getMessage(),
                'data' => [],
            ]);
        }



        // 是否修改密码
        if ($info['password']) {
            $data['password'] = app('user')->password($info['password'], $userinfo['uniqid']);
        }


        $authority = $info['authoritygroupid'] ?: [];
        $authoritygroupid = array_column($authority, 'id');
        $authoritylist = m('user.authority')->where(['uid' => $info['uid']])->pluck('groupid,expiration', 'id')->toArray();

        // 清掉权限
        if ($authoritylist) {
            $removed = [];
            foreach ($authoritylist as $id => $row) {
                $groupid = is_array($row) ? ($row['groupid'] ?? null) : ($row->groupid ?? null);
                if ($groupid !== null && !in_array($groupid, $authoritygroupid ?: [], true)) {
                    $removed[] = $id;
                }
            }
            $removed && m('user.authority')->where(['id' => $removed])->delete();
        }

        // 增加权限
        if ($authority) {
            $authorityInsert = [];
            foreach ($authority as $value) {
                $authorityInsert[] = [
                    'uid' => $info['uid'],
                    'groupid' => $value['id'],
                    'expiration' => $value['expiration'] ? strtotime($value['expiration']) : 0,
                ];
            }
            $authorityInsert && m('user.authority')->upsert($authorityInsert, 'id', [
                'expiration'
            ]);
        }

        // 更新用户组
        $groupdata = app('user.group')->update([
            'uid' => $userinfo['uid'],
            'groupid' => $data['groupid'],
            'groupexpiry' => $data['groupexpiry'],
        ]);

        if ($groupdata) {
            $data = array_merge($data, $groupdata ?: []);
        }

        $updateData = $this->container->get('array')->only($data, array_keys($userinfo));
        // 更新用户信息
        if ($updateData) {
            $model->update($updateData);
        }

        $extendField = $this->container->get('config')->get('user@config.extendField', []);
        // 扩展字段中允许编辑的字段
        $editableField = [];
        foreach ($extendField as $key => $value) {
            if (!isset($value['edit']) || !$value['edit']) {
                continue;
            }
            $editableField[] = $value['name'];
        }

        $dataField = ['username', 'nickname', 'mobile', 'email'];

        if ($editableField) {
            $dataField = array_merge($dataField, $editableField);
            foreach ($editableField as $key) {
                $data[$key] = $info[$key] ?: '';
            }
        }

        app('user.data')->set($userinfo['uid'], $this->container->get('array')->only($data, $dataField), $userinfo['uin'] ?: 0);

        show_json([
            'code' => 200,
            'message' => '操作成功',
            'data' => [
                'data' => $data,
                'dataField' => $dataField,
            ],
        ]);
    }

    /**
     * 获取相关字段
     */
    public function updateTable() {

        $token = input('token', '', 'decode');
        if (empty($token) || empty($token['uid'])) {
            $uid = (int) input('uid', 0);
            if ($uid > 0) {
                $token = ['uid' => $uid];
            } else {
                show_json([
                    'code' => 201,
                    'message' => '参数错误',
                ]);
            }
        }

        $model = app('user')->where(['uid' => $token['uid']]);
        $info = $model->one();
        $defaultGroup = m('user.group')->where(['type' => 'default', 'status' => 1])->select('id as value,title as label')->get()->toArray();


        $extendField = $this->container->get('config')->get('user@config.extendField', []);

        $editableField = [];
        foreach ($extendField as $key => $value) {
            if (!isset($value['edit']) || !$value['edit']) {
                continue;
            }

            if ($value['name'] == 'authority') {
                $value['options'] = app('user.permissions')->getPermission();
                if (empty($value['options'])) {
                    continue;
                }
            }
            $editableField[] = $value;
        }
        if ($editableField) {
            $editableFieldkey = array_column($editableField, 'name');
            $data = app('user.data')->get($info['uid'], $editableFieldkey, $info['uin']);
            foreach ($editableField as $value) {
                if (!isset($data[$value['name']])) {
                    $data[$value['name']] = $value['value'] ?? '';
                    continue;
                }
                if (is_string($data[$value['name']]) && json_validate($data[$value['name']])) {
                    $data[$value['name']] = json_decode($data[$value['name']], true);
                }
            }
        }

        $column = [
            [
                "label" => "UID",
                "name" => "uid",
                "width" => 80,
                "component" => "text",
            ],
            [
                "label" => "UIN",
                "name" => "uin",
                "width" => 80,
                'span' => 8,
                "component" => "input",
                'message' => '会员卡号 ID, 如论坛账号 UID',
            ],
            [
                "label" => "用户名",
                'name' => 'username',
                "component" => "input",
                'message' => '可用于登录,只能唯一',
                'span' => 8,
                'bind' => [
                    'autocomplete' => 'off',
                    'placeholder' => "用户名,不可为空.须唯一",
                ]
            ],
            [
                "label" => "用户昵称",
                'name' => 'nickname',
                "component" => "input",
                'span' => 8
            ],
            [
                "label" => "手机",
                'name' => 'mobile',
                "component" => "input",
                'span' => 8
            ],
            [
                "label" => "邮箱",
                'name' => 'email',
                "component" => "input",
                'span' => 8
            ],
            [
                "label" => "性别",
                "name" => "gender",
                "component" => "select",
                'span' => 8,
                "options" => [
                    ['value' => 1, 'label' => '男'],
                    ['value' => 2, 'label' => '女'],
                    ['value' => 0, 'label' => '保密'],
                ]
            ],
            [
                "label" => "用户组",
                'name' => 'groupid',
                "component" => "selectgroup",
                'span' => 10,
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
                        'options' => $defaultGroup
                    ],
                ],
                'message' => '用户组设置, 默认为普通用户组'
            ],
            [
                "label" => "有效期",
                "name" => "groupexpiry",
                "component" => "date",
                'span' => 14,
                'showhandle' => '$.groupid != -1',
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
                'bind' => [
                    'type' => 'system,default',
                    'useAll' => true,
                    'config' => [
                        'column' => [
                            [
                                'label' => 'ID',
                                'name' => 'id',
                                'component' => 'text',
                                'width' => 50,
                            ],
                            [
                                'label' => '类型',
                                'name' => 'type',
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
                                'width' => 235,
                                'bind' => [
                                    'fixed' => 'right',
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
                "label" => "状态",
                "name" => "status",
                "component" => "switch",
                'bind' => [
                    'active-value' => 1,
                    'active-text' => '启用',
                    'inactive-value' => 0,
                    'inactive-text' => '禁用',
                    'inline-prompt' => true,
                    'style' => '--el-switch-off-color: #ccc; --el-switch-on-color: #13ce66',
                ],
                'span' => 6,
                'message' => '用户状态'
            ],
            [
                "label" => "修改密码",
                "name" => "editpassword",
                "component" => "switch",
                'bind' => [
                    'active-value' => 1,
                    'inactive-value' => 0,
                    'active-text' => '是',
                    'inactive-text' => '否',
                    'inline-prompt' => true,
                ],
                'span' => 12,
                'message' => '如需修改密码请勾选'
            ],
            [
                "label" => "密码",
                'name' => 'password',
                'span' => 12,
                "component" => "input",
                'showhandle' => '$.editpassword',
                'bind' => [
                    'type' => 'text',
                    'placeholder' => "请输入密码",
                    'show-password' => true,
                    'autocomplete' => 'off',
                    'spellcheck' => false
                ],
                'message' => '如需修改密码请填写',
                'span' => 12
            ],
            [
                "label" => "确认密码",
                'name' => 'password_confirmation',
                'span' => 12,
                "component" => "input",
                'bind' => [
                    'type' => 'text',
                    'placeholder' => "请输入密码",
                    'show-password' => true
                ],
                'showhandle' => '$.password && $.editpassword',
                'span' => 12,
                'message' => '请输入确认密码'
            ],
        ];

        if ($editableField) {
            $column = array_merge($column, $editableField);
        }

        $authoritylist = m('user.authority')
            ->where(['uid' => $info['uid']])
            ->select('groupid as id,expiration')
            ->get();
        $authority = [];
        foreach ($authoritylist as $value) {
            $authority[] = [
                'id' => $value->id,
                'expiration' => $value->expiration == 0 ? '' : date('Y-m-d H:i:s', $value->expiration),
            ];
        }

        show_json([
            'code' => 200,
            'data' => [
                'config' => [
                    'column' => $column,
                    'url' => '/user/index/submit',
                    'name' => '编辑用户'
                ],
                'data' => array_merge($data ?: [], [
                    'uid' => $info['uid'],
                    'uin' => $info['uin'],
                    'username' => $info['username'],
                    'groupid' => in_array($info['groupid'], array_column($defaultGroup, 'value')) ? $info['groupid'] : -1,
                    'nickname' => $info['nickname'],
                    'groupexpiry' => in_array($info['groupid'], array_column($defaultGroup, 'value')) && $info['groupexpiry'] ? date('Y-m-d H:i:s', $info['groupexpiry']) : '',
                    'mobile' => $info['mobile'],
                    'email' => $info['email'],
                    'gender' => $info['gender'],
                    'status' => $info['status'],
                    'authoritygroupid' => $authority ?: [],
                    // 'avatar' => avatar($info['uid']),
                    'editpassword' => 0,
                    'password' => '',
                    'password_confirmation' => '',
                ]),
                'token' => encode([
                    'uid' => $info['uid'],
                    'uin' => $info['uin'],
                ]),
            ],
        ]);
    }

    /**
     * 更新标签
     * @log
     */
    public function tagsUpdate() {

        $token = input('post.token', '', 'decode');
        $tags = input('post.tags');
        if (empty($token)) {
            show_json([
                'code' => 201,
                'message' => 'Token Error',
            ]);
        }

        if (!app('?tag')) {
            show_json([
                'code' => 201,
                'message' => '没有安装标签应用',
            ]);
        }

        $tags = app('tag.user')->insert($tags, $token['uid'], $token['uin'], true);
        show_json([
            'code' => 200,
            'message' => '操作成功',
            'data' => [
                'tags' => $tags
            ],
        ]);
    }

    /**
     * 查询会员
     */
    public function get() {

        $uid = input('uid/d', 0, 'intval');
        $uin = input('uin/d', 0, 'intval');

        if ($uin > 0 && app('?uc') && $this->container->get('config')->sysget('config.open_user_uin', 0)) {
            $model = m('uc.members')->where(['uid' => $uin]);
            $info = $model->one();
            if ($info) {
                $user = app('user')->where(['uin' => $info['uid']])->order('uid desc')->one();
                if ($user) {
                    $info = array_merge($info, [
                        'uin' => $info['uid'],
                        'uid' => (int) $user['uid'],
                        'groupid' => (int) $user['groupid'],
                        'gender' => (int) $user['gender'],
                        'groupexpiry' => $user['groupexpiry'],
                        'regtype' => $user['regtype'],
                        'regdate' => $user['regdate'],
                        'uniqid' => $user['uniqid'],
                        'avatar' => avatar($info['uid'] ?: $user['uid'], 'big', $info['uid'] ? 1 : 0, id()),
                    ]);
                } else {
                    $info = array_merge($info, [
                        'uin' => $info['uid'],
                        'uid' => 0,
                        'groupid' => 0,
                        'avatar' => avatar($info['uid'], 'big', 1, id()),
                    ]);
                }
            }
        } else {
            $model = app('user')->where(['uid' => $uid]);
            $info = $model->one();
            if ($info) {
                $info['avatar'] = avatar($info['uid'], 'big', 0, id());
            }
        }

        if (empty($info)) {
            show_json([
                'code' => 201,
                'message' => '没有找到相关用户',
            ]);
        }

        // 统计表
        $stat = $this->container->get('event')->dispatch('user.admin@stat', [
            'uid' => $info['uid'],
            'uin' => $info['uin']
        ]);

        $tags = app('?tag') ? app('tag.user')->get($info['uid'], $info['uin']) : [];

        $menuList = [
            ['icon' => 'el-icon-postcard', 'title' => '账号信息', 'component' => 'account'],
            ['icon' => 'el-icon-operation', 'title' => '行为轨迹', 'component' => 'trace'],
            ['icon' => 'x-icon-Organization', 'title' => '可能认识', 'component' => 'may'],
        ];

        if (app('?message')) {
            $menuList[] = ['icon' => 'el-icon-ChatDotSquare', 'title' => '消息列表', 'component' => 'message'];
        }

        $menuData = [];

        if (app('?consume')) {
            $menuData[] = [
                'icon' => 'x-icon-consume',
                'title' => '积分明细',
                'component' => 'xtable',
                'tag' => $info['uid'] ? m('consume.api')->where(['uid' => $info['uid']])->count() : 0,
                'config' => [
                    'api' => 'consume/admin/detail',
                    'params' => [
                        'uid' => $info['uid'],
                        'uin' => $info['uin'],
                    ],
                ]
            ];
        }

        if (app('?comment')) {
            $menuData[] = [
                'icon' => 'el-icon-Comment',
                'title' => '评论管理',
                'component' => 'xtable',
                'tag' => m('comment')->where(function ($query) use ($info) {
                    if ($info['uid'] > 0) {
                        $query->orWhere(['uid' => $info['uid']]);
                    }
                    if ($info['uin'] > 0) {
                        $query->orWhere(['uin' => $info['uin']]);
                    }
                })->count(),
                'config' => [
                    'api' => 'comment/admin/detail',
                    'params' => [
                        'uid' => $info['uid'],
                        'uin' => $info['uin'],
                    ],
                ]
            ];
        }

        if (app('?filesystem')) {
            $menuData[] = [
                'icon' => 'el-icon-FolderOpened',
                'title' => '附件管理',
                'component' => 'xtable',
                'tag' => m('filesystem.attachmentuser')
                    ->alias('u')
                    ->join(m('filesystem.attachment')->getTable() . ' as a', 'a.id', '=', 'u.aid')
                    ->where(function ($query) use ($info) {
                        if ($info['uid'] > 0) {
                            $query->orWhere(['u.uid' => $info['uid']]);
                        }
                        if ($info['uin'] > 0) {
                            $query->orWhere(['u.uin' => $info['uin']]);
                        }
                    })
                    ->count(),
                'config' => [
                    'api' => 'filesystem/admin/detail',
                    'params' => [
                        'uid' => $info['uid'],
                        'uin' => $info['uin'],
                    ],
                ]
            ];
        }

        if (app('?circle')) {

            $topiccount = m('circle.topic')->where(function ($query) use ($info) {
                if ($info['uid'] > 0) {
                    $query->orWhere(['uid' => $info['uid']]);
                }
                if ($info['uin'] > 0) {
                    $query->orWhere(['uin' => $info['uin']]);
                }
            })->count();

            $menuData[] = [
                'icon' => 'el-icon-ChatDotSquare',
                'title' => '主题帖子',
                'component' => 'xtable',
                'tag' => $topiccount,
                'config' => [
                    'api' => 'circle/topic/detail',
                    'params' => [
                        'uid' => $info['uid'],
                        'uin' => $info['uin'],
                    ],
                ]
            ];
        }

        if (app('?order')) {
            $menuData[] = [
                'icon' => 'el-icon-school',
                'title' => '订单管理',
                'component' => 'xtable',
                'tag' => m('order.api')->where(function ($query) use ($info) {
                    if ($info['uid'] > 0) {
                        $query->orWhere(['uid' => $info['uid']]);
                    }
                    if ($info['uin'] > 0) {
                        $query->orWhere(['uin' => $info['uin']]);
                    }
                })->count(),
                'config' => [
                    'api' => 'order/admin/detail',
                    'params' => [
                        'uid' => $info['uid'],
                        'uin' => $info['uin'],
                    ],
                ]
            ];
        }

        $groupInfo = $this->container->get('cache')->remember('group_info', function () {
            return m('user.group')->pluck('aid,title', 'id')->toArray();
        }, 86400);


        $authority = $info['uid'] ? m('user.authority')->order('id desc')->where(['uid' => $info['uid']])->select('groupid,expiration')->get()->toArray() : [];
        $authorityData = [];
        foreach ($authority as $auth) {
            if (isset($groupInfo[$auth['groupid']]) == false) {
                continue;
            }
            $authorityData[] = $groupInfo[$auth['groupid']]->title . ($auth['expiration'] ? '(' . date('Y-m-d H:i:s', $auth['expiration']) . ')' : '');
        }


        show_json([
            'code' => 200,
            'data' => [
                'user' => [
                    'uid' => $info['uid'],
                    'uin' => $info['uin'],
                    'uniqid' => $info['uniqid'],
                    'username' => $info['username'],
                    'email' => $info['email'],
                    'mobile' => $info['mobile'],
                    'gender' => $info['gender'],
                    'groupid' => $info['groupid'],
                    'groupimage' => isset($groupInfo[$info['groupid']]) && $groupInfo[$info['groupid']]->aid ? get_img($groupInfo[$info['groupid']]->aid) : '',
                    'grouptitle' => $groupInfo[$info['groupid']]->title ?? '',
                    'groupexpiry' => $info['groupexpiry'] ? date('Y-m-d H:i:s', $info['groupexpiry']) : '',
                    'authority' => $authorityData,
                    'nickname' => $info['nickname'],
                    'regdate' => $info['regdate'],
                    'regip' => $info['regip'],
                    'regtype' => $info['regtype'],
                    'status' => $info['status'],
                    'avatar' => $info['avatar'],
                ],
                'tags' => array_values($tags ?: []),
                'token' => encode(['uid' => $info['uid'] ?? 0, 'uin' => $info['uin'] ?? 0]),
                'avatarupload' => app('?avatar') ? 'avatar/admin/upload' : '',
                'stat' => $stat ?: [],
                'menu' => [
                    [
                        'title' => '基本设置',
                        'list' => $menuList,
                    ],
                    [
                        'title' => '数据管理',
                        'list' => $menuData,
                    ],
                ],
            ],
        ]);
    }

    /**
     * 列表
     */
    public function lists() {

        $page = input('page/d', 1, 'intval');
        $limit = input('limit/d', 20, 'intval');
        $groupid = input('groupid/d', 0, 'intval');
        $uid = input('uid', '', 'trim');
        $gender = input('gender/d', 0, 'intval');
        $mobile = input('mobile/s', '', 'trim');
        $keyword = input('keyword/s', '', 'trim');
        $regtype = input('regtype/s', '', 'trim');
        $regdate = input('regdate', []);
        if (empty($regdate)) {
            $allParams = request()->all();
            if (isset($allParams['regdate[0]']) || isset($allParams['regdate[1]'])) {
                $regdate = [$allParams['regdate[0]'] ?? '', $allParams['regdate[1]'] ?? ''];
            }
        }
        $lastlogintime = input('lastlogintime', []);
        $verifystatus = input('verifystatus/d', 0, 'intval');
        $status = input('status', '');

        $model = app('user')
            ->alias('u')
            ->order('u.regdate desc')
            ->field('u.*')
            ->limit($limit)
            ->offset(($page - 1) * $limit);

        $groups = m('user.group')->order('id desc')->pluck('title,type', 'id')->toArray();
        if ($groupid) {
            if (isset($groups[$groupid]) && in_array($groups[$groupid]->type, ['system', 'default'])) {
                $model->join(m('user.authority')->getTable() . ' as a', 'a.uid', '=', 'u.uid')
                    ->where('a.groupid', '=', $groupid);
            } else {
                $model->where('u.groupid', '=', $groupid);
            }
        }


        if ($uid) {
            $uids = explode(',', str_replace('，', ',', $uid));
            $model->where(function ($query) use ($uids) {
                $query->orWhere('u.uid', 'in', $uids);
                $query->orWhere('u.uin', 'in', $uids);
            });
        }


        $gender && $model->where('u.gender', '=', $gender);
        $mobile && $model->where('u.mobile', 'like', str_replace('*', '%', $mobile));

        if ($status !== '' && $status !== null && in_array((string)$status, ['0', '1'], true)) {
            $model->where('u.status', '=', (int)$status);
        }

        if ($keyword) {
            $keyword = str_replace('*', '%', $keyword);
            $model->where(function ($query) use ($keyword) {
                $query->orWhere('u.username', 'like', $keyword);
                $query->orWhere('u.nickname', 'like', $keyword);
            });
        }

        $regtype && $model->where('u.regtype', 'like', $regtype);

        if ($verifystatus) {
            $model->where(function ($query) {
                $query->orWhere('u.uid', '>', 0);
                // 或者有手机号
                $query->orWhere('u.mobile', '<>', '');
            });
        }


        if ($regdate) {
            if (is_string($regdate)) {
                $regdate = explode(',', $regdate);
            }
            if (is_array($regdate)) {
                $startVal = !empty($regdate[0]) ? trim((string) $regdate[0]) : '';
                $endVal = !empty($regdate[1]) ? trim((string) $regdate[1]) : '';

                $startTs = null;
                if ($startVal !== '') {
                    $startTs = is_numeric($startVal) ? (int) $startVal : strtotime($startVal . ' 00:00:00');
                }

                $endTs = null;
                if ($endVal !== '') {
                    $endTs = is_numeric($endVal) ? (int) $endVal : strtotime($endVal . ' 23:59:59');
                }

                if ($startTs && $endTs) {
                    $model->whereBetween('u.regdate', [$startTs, $endTs]);
                } elseif ($startTs) {
                    $model->where('u.regdate', '>=', $startTs);
                } elseif ($endTs) {
                    $model->where('u.regdate', '<=', $endTs);
                }
            }
        }

        if ($lastlogintime) {
            $lastlogintime[0] = $this->container->get('date')->make($lastlogintime[0])->format('Y-m-d 00:00:00');
            $lastlogintime[1] = $this->container->get('date')->make($lastlogintime[1])->format('Y-m-d 23:59:59');
            $model->whereTime('u.lastlogintime', 'between', $lastlogintime);
        }

        $list = $model->get()->toArray();

        if ($list) {
            $authority = m('user.authority')->order('id desc')->where(['uid' => array_column($list, 'uid')])->field('uid,groupid,expiration')->get()->toArray();
            $authorityData = [];
            foreach ($authority as $auth) {
                if (isset($groups[$auth['groupid']]) == false) {
                    continue;
                }
                $authorityData[$auth['uid']][] = $groups[$auth['groupid']]->title;
            }
        }

        $uids = array_column($list, 'uid');

        $extendField = $this->config->get('user@config.extendField', []);
        // 允许出现在列表中
        $listField = [];
        foreach ($extendField as $key => $value) {
            if (isset($value['list']) && $value['list']) {
                $listField[] = isset($value['name']) && $value['name'] ? $value['name'] : $key;
            }
        }

        // 货币
        if (app('?consume')) {
            $currency = $this->container->get('config')->sysget('config.consume_currency', []);
            foreach ($currency as $val) {
                $listField[] = 'currency_' . $val['value'];
            }
        }



        if ($listField) {
            $userdatalist = m('user.data')->where(['uid' => $uids, 'field' => $listField])->get();
            $userdata = [];
            foreach ($userdatalist as $ud) {
                if (is_string($ud->value) && json_validate($ud->value)) {
                    $ud->value = json_decode($ud->value, true);
                }
                $userdata[$ud->uid][$ud->field] = $ud->value;
            }
        }

        // 是否安装 UC
        $isUC = app('?uc');

        if ($isUC) {
            $uins = array_values(array_filter(array_unique(array_column($list, 'uin'))));
            $ucmember = m('uc.members')->where(['uid' => $uins])->pluck('nickname,username', 'uid')->toArray();
        }

        foreach ($list as &$value) {
            unset($value['password']);

            $group = isset($authorityData[$value['uid']]) ? $authorityData[$value['uid']] : [];
            if (isset($groups[$value['groupid']]) && in_array($groups[$value['groupid']]->type, ['member', 'default'])) {
                $group[] = $groups[$value['groupid']]->title;
            }

            $nickname = $value['nickname'] ?: $value['username'];
            $value['avatar'] = avatar($value['uid'], 'mini');
            if ($isUC && isset($ucmember[$value['uin']])) {
                $nickname = $ucmember[$value['uin']]->nickname ?: $ucmember[$value['uin']]->username;
                $value['avatar'] = avatar($value['uin'], 'mini', 1);
            }
            $value['nickname'] = $nickname;
            $value['group'] = $group;
            $value['token'] = encode(['uid' => $value['uid']]);

            // 扩展字段
            if ($listField) {
                foreach ($listField as $val) {
                    // 优先显示 $value
                    if (isset($value[$val]) && $value[$val] != '') {
                        continue;
                    }
                    $value[$val] = isset($userdata[$value['uid']][$val]) ? $userdata[$value['uid']][$val] : '';
                }
            }
        }

        show_json([
            'code' => 200,
            'data' => [
                'limit' => $limit,
                'page' => $page,
                'data' => $list,
                'count' => $model->offset(0)->count(),
            ],
        ]);
    }

    /**
     * 会员状态变更
     * @log
     */
    public function status() {

        $token = input('post.token', '', 'decode');
        $status = input('post.status/d', '', 'intval');

        $user = $this->request->getAttribute('admin');

        if (empty($token)) {
            show_json([
                'code' => 201,
                'message' => 'Token 错误',
            ]);
        }
        $model = app('user')->where(['uid' => $token['uid']]);
        $info = $model->one();
        if (empty($info)) {
            show_json([
                'code' => 201,
                'message' => '没有找到相关的用户',
            ]);
        }

        if ($info['uid'] == $user['uid']) {
            show_json([
                'code' => 201,
                'message' => '安全起见,不能操作本人',
            ]);
        }

        if ($info['status'] == $status) {
            show_json([
                'code' => 201,
                'message' => '状态未发生改变',
            ]);
        }
        $model->update(['status' => $status ? 1 : 0]);
        show_json([
            'code' => 200,
            'message' => '操作成功',
        ]);
    }

    /**
     * 会员数据看板
     */
    public function dashboard() {
        return $this->container->get(\App\user\Controller\Admin\Data::class)->dashboard();
    }

    /**
     * 获取注册类型列表
     */
    public function getRegtypes() {
        $defaultTypes = [
            ['value' => 'mobile', 'label' => '手机注册'],
            ['value' => 'email', 'label' => '邮箱注册'],
            ['value' => 'weixin', 'label' => '微信生态'],
            ['value' => 'wechat', 'label' => '微信注册'],
            ['value' => 'qq', 'label' => 'QQ登录'],
            ['value' => 'admin', 'label' => '后台录入'],
            ['value' => 'system', 'label' => '系统内置'],
            ['value' => 'import', 'label' => '批量导入'],
            ['value' => 'other', 'label' => '默认/其他'],
        ];

        $configured = $this->config->sysget('config.user_regtypes');
        if (empty($configured)) {
            $dbVal = db('config')->where('name', 'user_regtypes')->value('value');
            if (!empty($dbVal)) {
                $configured = is_string($dbVal) ? json_decode($dbVal, true) : $dbVal;
            }
        }

        $list = [];
        if (!empty($configured) && is_array($configured)) {
            foreach ($configured as $k => $v) {
                if (is_array($v) && isset($v['value'])) {
                    $list[] = [
                        'value' => (string) $v['value'],
                        'label' => (string) ($v['label'] ?? $v['value']),
                    ];
                } elseif (is_string($k) && !is_numeric($k)) {
                    $list[] = [
                        'value' => (string) $k,
                        'label' => (string) $v,
                    ];
                }
            }
        }

        if (empty($list)) {
            $list = $defaultTypes;
        }

        show_json([
            'code' => 200,
            'data' => $list,
        ]);
    }

    /**
     * 保存注册类型配置
     */
    public function saveRegtypes() {
        $types = input('post.types', []);
        if (empty($types) && is_string($types)) {
            $types = json_decode($types, true);
        }

        if (!is_array($types)) {
            show_json([
                'code' => 201,
                'message' => '参数格式错误',
            ]);
        }

        $formatted = [];
        $exists = [];
        foreach ($types as $item) {
            $val = '';
            $lbl = '';
            if (is_array($item)) {
                $val = trim((string) ($item['value'] ?? ''));
                $lbl = trim((string) ($item['label'] ?? $val));
            } elseif (is_string($item)) {
                $val = trim($item);
                $lbl = $val;
            }
            if ($val !== '' && !isset($exists[$val])) {
                $exists[$val] = true;
                $formatted[] = [
                    'value' => $val,
                    'label' => $lbl ?: $val,
                ];
            }
        }

        if (empty($formatted)) {
            show_json([
                'code' => 201,
                'message' => '请至少保留或添加一个注册类型',
            ]);
        }

        // 保存至 config 表与系统缓存
        db('config')->updateOrInsert(
            ['name' => 'user_regtypes'],
            ['value' => json_encode($formatted, JSON_UNESCAPED_UNICODE)]
        );

        $allConfig = cache_read('config') ?: [];
        $allConfig['user_regtypes'] = $formatted;
        cache_write('config', $allConfig);
        $this->config->set('user_regtypes', $formatted);

        show_json([
            'code' => 200,
            'message' => '注册类型保存成功',
            'data' => $formatted,
        ]);
    }
}
