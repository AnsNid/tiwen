<?php

namespace App\user;

use xphp\DbConnection\Db;
use xphp\Redis\RedisFactory;

/**
 * 用户行为轨迹记录
 */

class Trace {

    /**
     *  入库记录
     * @param string $type 记录类型
     * @param int    $uid 用户ID
     * @param mixed  $relatedid 相关ID
     * @param int    $uin 用户标识
     * @param bool   $forced, 不缓存直接写入
     * @return bool
     */
    public function record($type, $uid = 0, $relatedid = '', $uin = 0, $forced = false) {

        $data = $this->prepareData($type, $uid, $relatedid, $uin);

        if (empty($data['uid']) && empty($data['uin'])) {
            return false;
        }

        $key = $this->gettraceKey($data['type']);
        $uniqid = $this->generateUniqid($data, $key);

        if ($forced === true || $this->isDirectWriteEnabled()) {
            $this->storage([$uniqid => $data]);
            return true;
        }

        $config = xphp('config')->get('user@config', []);
        $traceConfig = $config['trace_redis'] ?? [];
        $baseKey = $traceConfig['key'] ?? 'user:trace';
        $shards = (int) ($traceConfig['shards'] ?? 4);
        $basis = ($data['type'] ?? '') . ':' . ($data['relatedid'] ?? '');
        $key = $shards > 1 ? ($baseKey . ':sh' . (crc32($basis) % $shards)) : $baseKey;

        $payload = $data;
        $payload['uniqid'] = $uniqid;

        $redis = xphp(RedisFactory::class)->get('default');
        $redis->rPush($key, json_encode($payload, JSON_UNESCAPED_UNICODE));
        return true;
    }

    /**
     * 获取Key
     */
    private function gettraceKey($type) {
        $config = xphp('config')->get('user@trace', []);
        if (isset($config[$type])) {
            $key = $config[$type];
            if ($key instanceof \Closure) {
                $key = $key();
            }
            if ($key === true) {
                $key = date('Ymd');
            }
            return $key;
        }
        return '';
    }


    /**
     * 准备数据
     */
    private function prepareData($type, $uid, $relatedid, $uin) {
        return [
            'type' => $type,
            'uid' => (int)$uid,
            'uin' => (int)$uin,
            'relatedid' => $relatedid,
            'num' => 1,
        ];
    }

    /**
     * 生成Uniqid
     */
    private function generateUniqid($data, $key) {
        return md5(join([
            $data['type'],
            $data['uid'],
            $data['uin'],
            $data['relatedid'],
            $key,
        ]));
    }

    /**
     * 检查是否启用直接写入
     */
    private function isDirectWriteEnabled() {
        return xphp('config')->get('user@config.trace_direct_write', false);
    }

    /**
     * 动态调用
     * @param string $type
     * @param array  $parameters
     * @return mixed
     */
    public function __call($method, $parameters) {
        return $this->record($method, ...$parameters);
    }

    /**
     * 入库操作
     * */
    public function storage($data = []): void {

        if (empty($data)) {
            return;
        }

        $insert = [];
        $uniqids = array_values(array_filter(array_column($data, 'uniqid')));
        $isExist = m('user.trace')->whereIn('uniqid', $uniqids)->pluck('id', 'uniqid')->toArray();
        foreach ($data as $uniqid => $item) {

            if (empty($uniqid) || !is_string($uniqid)) {
                continue;
            }

            // 更新存在的记录
            if (isset($isExist[$uniqid])) {
                m('user.trace')->where('id', $isExist[$uniqid])->increment('num', (int)$item['num']);
                continue;
            }

            $insert[] = array_merge($item, [
                'uniqid' => $uniqid,
            ]);
        }
        // 插入不存在的记录
        $insert && m('user.trace')->insertOrIgnore($insert);
    }
}
