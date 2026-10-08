<?php

// +----------------------------------------------------------------------
// | 后台设置
// +----------------------------------------------------------------------

return [
    'settingList' => [
        'init' => '全局',
        'filter' => '关键词过滤',
        'user' => '用户',
    ],
    'setting' => [
        [
            'name' => 'site_name',
            'component' => 'input',
            'label' => '网站标题',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请填写网站标题'
            ]
        ],
        [
            'name' => 'meta_title',
            'component' => 'input',
            'label' => '网站副标题',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请填写网站副标题'
            ]
        ],
        [
            'name' => 'site_url',
            'component' => 'input',
            'label' => '网站地址',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请填写网站地址'
            ]
        ],
        [
            'name' => 'viewstaticurl',
            'component' => 'input',
            'label' => '静态资源URL',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请填写静态资源URL'
            ],
            'message' => '当没有对页面设置关键词时，将使用本配置',
        ],
        [
            'name' => 'meta_keywords',
            'component' => 'input',
            'label' => 'Meta 关键词',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请填写Meta 关键词'
            ],
            'message' => '当没有对页面设置关键词时，将使用本配置',
        ],
        [
            'name' => 'meta_description',
            'component' => 'input',
            'label' => 'Meta 描述',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请填写Meta 描述'
            ],
            'message' => '当没有对页面设置描述时，将使用本配置',
        ],
        [
            'name' => 'copyright',
            'component' => 'input',
            'label' => '版权信息 (支持html)',
            'parent' => 'init',
            'bind' => [
                'type' => 'textarea',
                'placeholder' => '请填写版权信息',
                'autosize' => [
                    'minRows' => 2,
                    'maxRows' => 6
                ]
            ]
        ],
        [
            'name' => 'icp',
            'component' => 'input',
            'label' => ' ICP备案信息',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请填写ICP备案信息'
            ],

        ],
        [
            'name' => 'stat_code',
            'component' => 'input',
            'label' => ' 统计代码',
            'parent' => 'init',
            'bind' => [
                'type' => 'textarea',
                'placeholder' => '请填写统计代码',
                'autosize' => [
                    'minRows' => 2,
                    'maxRows' => 6
                ]
            ]
        ],
        [
            'name' => 'adminhostdomain',
            'label' => '后台访问域名',
            'component' => 'input',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请输入域名 HOST, 例如: admin.x-php.com',
            ],
            'message' => '请输入域名 HOST或前缀, 例如: admin.x-php.com, 留空为当前域名, 多个域名请用逗号隔开',
        ],
        [
            'name' => 'adminhostpath',
            'label' => '后台访问路径',
            'component' => 'input',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请输入路径, 例如: admin',
            ],
            'message' => '请输入路径, 例如: admin, 只有在开启域名时才允许留空',
            'value' => 'admin'
        ],
        [
            'name' => 'adminstaticpath',
            'component' => 'input',
            'label' => '后台静态资源路径',
            'parent' => 'init',
            'bind' => [
                'placeholder' => '请输入路径, 例如: admin/static',
            ],
            'message' => '请输入路径, 例如: admin/static, 只有在开启域名时才允许留空',
            'value' => ''
        ],
        [
            'name' => 'show_scan_login',
            'component' => 'switch',
            'label' => '后台启用扫码登录',
            'parent' => 'init',
            'bind' => [
                'active-value' => 1,
                'inactive-value' => 0,
                'active-text' => '开启',
                'inactive-text' => '关闭',
                'inline-prompt' => true,
            ],
            'message' => '是否显示扫码登录',
            'value' => 0
        ],
        [
            'name' => 'scan_login_weixin_appid',
            'component' => 'input',
            'label' => '微信扫码登录AppID',
            'parent' => 'init',
            'showhandle' => '$.show_scan_login',
            'bind' => [
                'placeholder' => '请填写微信扫码登录AppID'
            ],
        ],
    ],
    'authority' => [
        'config' => [],
    ],
    // 默认菜单栏选项
    'menu' => [
        'home' => [
            'meta' => [
                'title' => '控制台',
                'hideTags' => true,
                'icon' => 'el-icon-home-filled',
                'parenttitle' => '首页', // 当前子类的时候显示父级名称
            ],
            'sort' => 9999,
            'path' => 'dashboard',
            'component' => 'home/index',
        ],
        'system' => ['meta' => ['title' => '系统', 'icon' => 'el-icon-setting'], 'sort' => 9998],
        'operation' => ['meta' => ['title' => '运营', 'icon' => 'el-icon-orange'], 'sort' => 9997],
        'user' => ['meta' => ['title' => '用户', 'icon' => 'el-icon-user-filled'], 'sort' => 9996],
    ],
    'router' => [
        [
            'path' => 'system/setting',
            'component' => 'system/setting',
            'meta' => [
                'title' => '系统设置',
                'icon' => 'x-icon-setting',
                'active' => 'system/setting',
            ],
            'name' => 'setting',
            'sort' => 1000,
        ],
        [
            'path' => 'system/application',
            'component' => 'system/application',
            'meta' => [
                'title' => '应用插件管理',
                'icon' => 'x-icon-Application',
            ],
            'name' => 'application',
            'sort' => 999,
        ],
    ],
];
