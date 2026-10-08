<?php

namespace App\consume;

use xphp\DbConnection\Db;

/**
 * 积分明细
 */
class Api {
    /**
     * 根据规则记录消费或积分变化
     *
     * @param array|string $type 规则类型，可以是数组形式的规则数据或规则类型字符串
     * @param int $uid 用户UID
     * @param array $data 额外的数据，默认为空数组; $data['expire'] 可覆盖规则配置的有效期
     * @param string $uniqid 唯一标识符，默认为空字符串
     * @return array|mixed 返回记录结果，如果不符合条件则返回空数组
     */

    public function rule(array|string $type = '', ?int $uid = 0, array $data = [], $uniqid = '') {

        $uin = intval($data['uin'] ?? 0);
        $uid = intval($uid);
        if ((empty($uid) && empty($uin)) || empty($type)) {
            return [];
        }

        $rule = app('consume.rule')->getRule($type);
        // getRule 对未知标识返回 ['restrict' => ['date' => []]], 是非空数组, 故用 empty 而非 !$rule
        if (empty($rule['amount']) || empty($rule['currency'])) {
            return [];
        }
        $rule['type'] = !empty($rule['type']) ? $rule['type'] : $type;
        // 事件钩子
        xphp('event')->dispatch('consume.' . $type . '@rule', $rule, $uid, $uin);

        // 处理 amount(1-10:取随机数, [1,10,90]:随机取一个, 1:固定)
        $amount = app('consume.rule')->handleRuleAmount($rule['amount']);
        if (!$amount) {
            throw new \Exception('金额格式错误');
        }

        // 有效期: 调用方 $data['expire'] 覆盖规则配置; 取出后不再随 data 落库, 免得同一件事记两遍
        $expire = $data['expire'] ?? ($rule['expire'] ?? null);
        unset($data['expire']);
        $expiredAt = app('consume.rule')->convertExpire($expire);

        return Db::transaction(function () use ($rule, $uid, $uin, $amount, $uniqid, $data, $expiredAt) {
            $this->lockBalance($rule['currency'], $uid, $uin);

            $restrict = $rule['restrict'] ?? [];
            if (!empty($restrict) && (!empty($restrict['amount']) || !empty($restrict['count']))) {
                $dateRange = $restrict['date'] ?? [];
                unset($restrict['date']);

                $consumeModel = m('member_consume')
                    ->where([
                        'type' => $rule['type'],
                        'currency' => $rule['currency'],
                    ])
                    ->where(function ($query) use ($uid, $uin) {
                        if ($uid) {
                            $query->orWhere('uid', $uid);
                        }
                        if ($uin) {
                            $query->orWhere('uin', $uin);
                        }
                    });

                if (!empty($dateRange) && is_array($dateRange) && count($dateRange) >= 2) {
                    $consumeModel->where('created_at', 'between', [
                        date('Y-m-d 00:00:00', strtotime($dateRange[0])),
                        date('Y-m-d 23:59:59', strtotime($dateRange[1])),
                    ]);
                }

                $consume = $consumeModel->select([
                    'sum(amount) as amount',
                    'count(*) as count',
                ])->one();

                foreach ($restrict as $key => $limit) {
                    if (isset($consume[$key]) && $consume[$key] >= $limit) {
                        return [];
                    }
                }
            }

            // 整份规则配置可由标识反查, 逐行复制进 text 列是高频账本表的主要存储开销
            $extra = ['rule' => $rule['type']];
            if (!empty($rule['remark'])) {
                $extra['remark'] = $rule['remark'];
            }

            return $this->record($rule['currency'], $uid, $amount, $rule['type'], $uniqid ?: ($rule['uniqid'] ?? ''), array_merge($data, $extra), null, $expiredAt);
        }, 3);
    }

    /**
     * 记录
     * @param string $currency 货币类型
     * @param int $uid 用户ID
     * @param float $amount 金额
     * @param string $type 类型
     * @param string|null $uniqid 唯一标识符
     * @param array $data 内容
     * @param callable|null $callback 回调函数
     * @param string|null $expiredAt 到期时间, null 表示永久有效
     * @return array|false 记录结果或false
     */
    public function record(?string $currency = 'money', ?int $uid = 0, ?float $amount = 0, ?string $type = 'system', $uniqid = '', array $data = [], $callback = null, ?string $expiredAt = null) {

        $currency = trim((string) $currency);
        $type = trim((string) $type);
        $amount = round((float) $amount, 2);
        $uin = intval($data['uin'] ?? 0);
        $uid = intval($uid);

        if ((!$uid && !$uin) || !$amount || !is_finite($amount) || $currency === '' || $type === '') {
            return false;
        }

        if ($uniqid) {
            $uniqid = id(join([$uid, $uin, $type, $currency, $uniqid]));
        }

        $result = Db::transaction(function () use ($currency, $uid, $uin, $amount, $type, $uniqid, $data, $expiredAt) {
            if ($uniqid) {
                $consume = m('member_consume')->where(['uniqid' => $uniqid])->lockForUpdate()->one();
                if ($consume) {
                    return ['record' => $consume, 'created' => false];
                }
            }

            $balance = $this->lockBalance($currency, $uid, $uin) + $amount;

            if ($uniqid) {
                $consume = m('member_consume')->where(['uniqid' => $uniqid])->lockForUpdate()->one();
                if ($consume) {
                    return ['record' => $consume, 'created' => false];
                }
            }

            $record = [
                'uid' => $uid,
                'uin' => $uin,
                'amount' => $amount,
                'uniqid' => $uniqid ?: id(),
                'balance' => $balance,
                'type' => $type,
                'currency' => $currency,
                'data' => $data,
            ];
            if ($expiredAt !== null) {
                $record['expired_at'] = $expiredAt;
            }
            $eventData = xphp('event')->dispatch('consume.' . $currency . '@before', $record);
            if (is_array($eventData)) {
                $record['data'] = array_merge($record['data'], $eventData);
            }

            $record['id'] = m('member_consume')->insertGetId($record);

            return ['record' => $record, 'created' => true];
        }, 3);

        $record = $result['record'];
        if ($result['created']) {
            xphp('event')->dispatch('consume.' . $record['currency'] . '@after', $record);
        }

        if (is_callable($callback)) {
            return call_user_func($callback, $record);
        }
        return $record;
    }

    /**
     * 到期冲销
     *
     * 余额仍然是 SUM(amount), 热路径与全部读取方都不用改: 这里给每笔到期的赠送补一条
     * 等额负向流水, 让求和自然把它减掉。冲销额取 min(该笔赠送额, 当前余额),
     * 用户已经花掉的部分不再追回, 账本也不会被冲成负数。
     *
     * 这不是严格 FIFO: 账本不记录用户花掉的是哪一笔钱, 同时持有永久积分与到期积分时,
     * 冲销会把剩余余额都算作到期那笔的钱, 可能比严格 FIFO 多冲。
     *
     * 读余额用 lockBalance 而不是 ___count___: 冲销要让 SUM(amount) 落到正确值, 就得按
     * 账本自己的口径计量(与 record() 写 balance 列同源), 且必须先锁住最新一行,
     * 否则并发的消费会挤在读与写之间, 冲销完变成透支。
     *
     * 冲销流水与 expired_settled 标记同处一个事务, 且流水在前: 反过来的话写入失败会让
     * 这笔到期额度静默消失。uniqid 取自原流水 id, 重跑不会重复入账。
     *
     * 单轮最多处理 $limit 笔, 积压时靠后续轮次继续消化。
     */
    public function settleExpired(int $limit = 500): array {

        $grants = m('member_consume')
            ->select(['id', 'uid', 'uin', 'currency', 'type', 'amount', 'expired_at'])
            ->where('expired_settled', 0)
            ->whereNotNull('expired_at')
            ->where('expired_at', '<=', date('Y-m-d H:i:s'))
            ->where('amount', '>', 0)
            ->order('expired_at asc')
            ->limit($limit)
            ->get()
            ->toArray();

        $result = ['scanned' => count($grants), 'settled' => 0, 'skipped' => 0, 'failed' => 0, 'amount' => 0.0];

        foreach ($grants as $grant) {
            try {
                Db::transaction(function () use ($grant, &$result) {

                    $currency = (string) $grant['currency'];
                    $uid = (int) $grant['uid'];
                    $balance = $this->lockBalance($currency, $uid, (int) $grant['uin']);
                    $amount = -round(min((float) $grant['amount'], max($balance, 0)), 2);

                    if ($amount < 0) {
                        $this->record($currency, $uid, $amount, 'expire', 'expire:' . $grant['id'], [
                            'uin' => (int) $grant['uin'],
                            'source_id' => (int) $grant['id'],
                            'source_type' => $grant['type'],
                            'granted' => (float) $grant['amount'],
                            'expired_at' => $grant['expired_at'],
                        ]);
                        $result['settled']++;
                        $result['amount'] += $amount;
                    } else {
                        // 余额已归零, 无可冲销额度; 同样要标记, 否则每轮都会重新扫到它
                        $result['skipped']++;
                    }

                    m('member_consume')->where('id', $grant['id'])->update(['expired_settled' => 1]);
                }, 3);
            } catch (\Throwable $th) {
                // 单笔失败不能挡住整批: 按 expired_at 升序消费时, 卡住的那笔每轮都排在最前,
                // 会让后面的到期额度永远轮不到。回滚后不标记, 下轮自动重试
                $result['failed']++;
                logger()->warning('consume expire #' . $grant['id'] . ': ' . $th->getMessage());
            }
        }

        $result['amount'] = round($result['amount'], 2);
        return $result;
    }

    /**
     * 查询用户所有货币余额
     * @param $data int|array
     * @return array
     */
    public function ___balance___(int|array $data) {

        $data = is_numeric($data) ? ['uid' => $data] : $data;
        $uid = intval($data['uid'] ?? 0);
        $uin = intval($data['uin'] ?? 0);

        if ($uid === 0 && $uin === 0) {
            return [];
        }

        $eventdata = xphp('event')->dispatch('consume@balance', $uid, $uin);
        if ($eventdata) {
            return $eventdata;
        }

        $result = m('member_consume')
            ->groupBy('currency')
            ->where(function ($query) use ($uid, $uin) {
                $uid && $query->orWhere(['uid' => $uid]);
                $uin && $query->orWhere(['uin' => $uin]);
            })
            ->select([
                'currency',
                'sum(amount) as amount',
            ])
            ->get()
            ->pluck('amount', 'currency')
            ->toArray();

        return $result;
    }


    /**
     *查询数量
     */
    public function ___count___($currency = 'money', $uid = 0, $uin = 0): float {

        if (empty($currency)) {
            return 0;
        }

        $uid = intval($uid);
        $uin = intval($uin);
        if ($uid === 0 && $uin === 0) {
            return 0;
        }

        $eventdata = xphp('event')->dispatch('consume.' . $currency . '@count', $uid, $uin);
        if (is_numeric($eventdata)) {
            return $eventdata;
        }
        $currency = array_values(array_filter(array_unique(array_map('trim', explode(';', str_replace([';', ',', '@', '|', '&'], ';', $currency))))));
        $amount = m('member_consume')
            ->where(['currency' => $currency])
            ->where(function ($query) use ($uid, $uin) {
                $uid && $query->orWhere(['uid' => $uid]);
                $uin && $query->orWhere(['uin' => $uin]);
            })
            ->sum('amount');
        return (float) $amount;
    }

    /**
     * 动态调用
     * @param string $type
     * @param array  $parameters
     * @return mixed
     */
    public function __call($method, $data) {

        $__function__ = 'record';
        // 问号开头
        if (0 === strpos($method, '?')) {
            $method = substr($method, 1);
            $__function__ = '___count___';
        }

        // get 开头且后面还有值
        if (0 === strpos($method, 'get') && strlen($method) > 3) {
            $method = substr($method, 3);
            // 首字字母改为小写,其它不动
            $method = lcfirst($method);
            $__function__ = '___count___';
        }
        array_unshift($data, $method);
        return call_user_func_array([$this, $__function__], $data);
    }

    /**
     * 锁定并返回该用户在指定币种下的余额
     *
     * 串行化依赖 InnoDB 在 REPEATABLE READ 下的间隙锁: 用户在该币种下尚无流水时,
     * 锁定读扫不到记录但仍会锁住该索引区间, 从而阻塞并发的首次写入。少了这一层,
     * rule() 的 restrict 次数/额度校验会被并发请求同时通过, 造成重复发放。
     *
     * 加锁不能复用 balanceQuery 的 uid OR uin: OR 会走 index_merge, 空区间下的间隙锁不可靠。
     * 故按身份逐个锁定确定性的单索引区间, 并固定 uid 先于 uin 的顺序以避免死锁环。
     * 也不能改成 SUM(amount) ... FOR UPDATE: 那会锁住该用户全部历史流水,
     * 在追加式账本上是 O(n) 的锁足迹与死锁放大器; 这里只锁最新一行, 是 O(1)。
     */
    private function lockBalance(string $currency, int $uid, int $uin): float {
        $identities = array_filter([
            $uid ? ['uid' => $uid] : [],
            $uin ? ['uin' => $uin] : [],
        ]);

        foreach ($identities as $identity) {
            m('member_consume')
                ->where(['currency' => $currency])
                ->where($identity)
                ->order('id desc')
                ->lockForUpdate()
                ->value('id');
        }

        return (float) $this->balanceQuery($currency, $uid, $uin)->sum('amount');
    }

    private function balanceQuery(string $currency, int $uid, int $uin) {
        return m('member_consume')
            ->where(['currency' => $currency])
            ->where(function ($query) use ($uid, $uin) {
                if ($uid) {
                    $query->orWhere(['uid' => $uid]);
                }
                if ($uin) {
                    $query->orWhere(['uin' => $uin]);
                }
            });
    }
}
