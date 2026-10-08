<?php

namespace App\user;

/**
 * 会员用户资料
 */
class Data {
    /**
     * 查询某会员数据
     */
    public function get(int|array|string $uid = 0, string|array $field = '', int $uin = 0): array {

        if (is_array($uid)) {
            $uin = intval($uid['uin'] ?? $uin);
            $uid = intval($uid['uid'] ?? 0);
        }

        if (empty($uid) && empty($uin)) {
            return [];
        }

        // 字段名统一去除首尾空白
        $field = is_array($field) ? $field : explode(',', $field);
        $field = array_values(array_filter(array_map('trim', array_map('strval', $field)), 'strlen'));

        // uid / uin 是同一会员的两个标识, 任一匹配即可
        $model = m('user.data')
            ->where(function ($query) use ($uid, $uin) {
                if ($uid) {
                    $query->orWhere('uid', '=', $uid);
                }
                if ($uin) {
                    $query->orWhere('uin', '=', $uin);
                }
            });

        if ($field) {
            $model->whereIn('field', $field);
        }

        // 按 id 升序: pluck 以 field 为键时后行覆盖前行, 保证重复字段取到最新一条
        $listcolumn = $model->orderBy('id', 'asc')->pluck('value', 'field');

        $fields = $field ? $field : array_keys($listcolumn->toArray());


        $data = [];
        foreach ($fields as $item) {

            // 不存在的字段补 null
            if (!isset($listcolumn[$item])) {
                $data[$item] = null;
                continue;
            }

            $value = $listcolumn[$item];

            // JSON 内容还原为数组
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $value = $decoded;
                }
            }

            $data[$item] = $value;
        }
        return $data;
    }

    /**
     * 设置某会员数据
     * @param int|array|string $user 会员 uid, 或 ['uid' => 1, 'uin' => 2]
     * @param array $data 数据
     * @param int $uin $user 为 int 时可单独指定 uin
     * @return bool
     */
    public function set(int|array|string $user, array $data = [], int $uin = 0): bool {

        if (is_array($user)) {
            if (!isset($user['uid']) && !isset($user['uin'])) {
                return false;
            }
            $uid = intval($user['uid'] ?? 0);
            $uin = intval($user['uin'] ?? $uin);
        } else {
            $uid = intval($user);
        }

        if (empty($uid) && empty($uin)) {
            return false;
        }

        if (empty($data)) {
            return false;
        }

        $config = xphp('config')->get('user@config');

        try {
            // 已有数据, 按 id 倒序方便取最新一条
            $userDatalist = m('user.data')
                ->where(function ($query) use ($uid, $uin) {
                    // uid / uin 是同一会员的两个标识, 任一匹配即可
                    if ($uid) {
                        $query->orWhere('uid', '=', $uid);
                    }
                    if ($uin) {
                        $query->orWhere('uin', '=', $uin);
                    }
                })
                ->where(['field' => array_keys($data)])
                ->select('id', 'field', 'value')
                ->orderBy('id', 'desc')
                ->get();
        } catch (\Throwable $th) {
            stdout_logger()->error('user.data 读取失败: ' . $th->getMessage(), [
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);
            return false;
        }

        // 已有数据按 field 分组
        $userData = [];
        foreach ($userDatalist as $value) {
            $userData[$value->field][$value->id] = $value->value;
        }

        $insert = [];
        $update = [];
        $deleteids = [];

        foreach ($data as $field => $value) {

            // 过滤字段
            if (!empty($config['filterField']) && in_array($field, $config['filterField'], true)) {
                continue;
            }

            // 数组内容转为 JSON 存储
            $value = is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;

            // 空值不落库, 0、'0' 等假值正常写入
            if ($value === null || $value === '' || $value === false) {
                continue;
            }

            // 已存在数据
            if (isset($userData[$field])) {

                // 内容一样则跳过
                if (in_array((string) $value, array_map('strval', array_values($userData[$field])), true)) {
                    continue;
                }

                // 当前字段允许多个内容, 0-1 为不限次数
                if (isset($config['allowRepeatedField'][$field])) {
                    $limit = intval($config['allowRepeatedField'][$field]);
                    if ($limit > 1) {
                        // 保留最新 limit-1 条旧数据, 加上本次新增 1 条, 共 limit 条
                        $keys = array_keys($userData[$field]);
                        if (count($keys) > $limit - 1) {
                            $deleteids = array_merge($deleteids, array_slice($keys, $limit - 1));
                        }
                    }
                } else {
                    // 单值字段: 更新最新一条, 删除其余旧数据
                    $deleteids = array_keys($userData[$field]);
                    $newestid = array_shift($deleteids);
                    // 更新数据
                    $update[] = [
                        'id' => $newestid,
                        'value' => $value,
                    ];
                    continue;
                }
            }

            $insert[] = [
                'uid' => $uid,
                'uin' => $uin,
                'field' => $field,
                'value' => $value,
            ];
        }

        try {
            if ($insert) {
                m('user.data')->insert($insert);
            }
            if ($update) {
                m('user.data')->upsert($update, 'id', [
                    'value'
                ]);
            }
            if ($deleteids) {
                m('user.data')->where('id', $deleteids)->delete();
            }
        } catch (\Throwable $th) {
            stdout_logger()->error('user.data 写入失败: ' . $th->getMessage(), [
                'data' => $data,
                'file' => $th->getFile(),
                'line' => $th->getLine(),
            ]);
            return false;
        }

        return true;
    }

    /**
     * 入库
     */
    public function insert(int $uid = 0, array $data = [], int $uin = 0): bool {
        // 入库
        return $this->set(['uid' => $uid, 'uin' => $uin], $data);
    }
}
