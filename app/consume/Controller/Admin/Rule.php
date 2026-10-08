<?php

namespace App\consume\Controller\Admin;

use App\admin\Authorization;

/**
 *  积分规则管理
 */

class Rule extends Authorization {

    /**
     * 初始化积分规则
     */
    public function init() {

        $config = app('consume.rule')->getConfig();
        $consume_currency = $this->config->sysget('config.consume_currency', []);
        showmsg('积分规则初始化成功', 200, [
            'consume_currency' => $consume_currency,
            'rule' => $config,
            'api' => '/consume/rule/submit',
        ]);
    }

    /**
     * 提交积分规则
     * @log
     */
    public function submit() {
        $rule = input('post.rule', []);

        foreach ($rule as $key => $value) {

            $name = $value['remark'] ?? $key;
            // 必填项
            if (empty($key)) {
                showmsg('请填写规则标识,或删除多余的空数据');
            }

            // key 只能是以字母开头，后面可以是字母、数字或下划线
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $key)) {
                showmsg("规则标识{$key}格式错误，只能以字母开头，后面可以是字母、数字或下划线");
            }

            // 必填项
            if (empty($value['currency'])) {
                showmsg("请选择{$name}货币类型");
            }

            // 必填项
            if (empty($value['amount'])) {
                showmsg("请填写{$name}奖励积分");
            }
        }
        cache_write('consumerule.php', $rule);
        showmsg('积分规则设置成功', 200);
    }
}
