<?php

declare(strict_types=1);

namespace App\consume\Crontab;

use App\crontab\Annotation\Crontab;

/**
 * 积分到期冲销
 * 每 10 分钟扫一次已到期但尚未冲销的赠送流水, 补一条等额负向流水使其从余额中失效
 */
#[Crontab(name: "ConsumeExpire", rule: "*/10 * * * *", callback: "execute", memo: "积分到期冲销(把过期的赠送从余额中扣除)")]
class Expire {
    /**
     * 执行冲销
     */
    public function execute() {
        $result = app('consume')->settleExpired();
        // 扫到却一笔没冲(余额早已归零)同样是异常信号, 故按 scanned 而非 settled 记日志
        if ($result['scanned'] > 0) {
            logger()->info('consume expire: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        }
        return $result;
    }
}
