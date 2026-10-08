<?php

namespace App\user\Controller\Admin;

use App\admin\Authorization;

/**
 * 可能认识的人
 */

class May extends Authorization {
    /**
     * 可能认识的人
     */
    public function lists() {

        $token = input('.token', '', 'decode');
        if (empty($token) || (empty($token['uid']) && empty($token['uin']))) {
            show_json([
                'code' => 200,
                'message' => '缺少参数',
                'data' => []
            ]);
        }

        // 查找 IP 以及机器码
        $list = m('user.data')
            ->where(function ($query) use ($token) {
                $token['uid'] && $query->orWhere('uid', $token['uid']);
                $token['uin'] && $query->orWhere('uin', $token['uin']);
            })
            ->where(['field' => ['lastloginip', 'browserfingerprint']])
            ->limit(100)
            ->get();

        if (empty($list)) {
            show_json([
                'code' => 200,
                'message' => '数据不存在',
                'data' => []
            ]);
        }

        $data = [];
        foreach ($list as $key => $value) {
            $data[$value->field][] = $value->value;
        }


        // 查找可能认识的人
        $list = m('user.data')
            ->where(function ($query) use ($data) {
                if ($data['lastloginip']) {
                    $query->orWhere(function ($query) use ($data) {
                        $query->where('field', 'lastloginip')->whereIn('value', $data['lastloginip']);
                    });
                }
                if ($data['browserfingerprint']) {
                    $query->orWhere(function ($query) use ($data) {
                        $query->where('field', 'browserfingerprint')->whereIn('value', $data['browserfingerprint']);
                    });
                }
            })
            ->limit(100)
            ->select('uid,uin')
            ->get();


        if (empty($list)) {
            show_json([
                'code' => 200,
                'message' => '数据不存在',
                'data' => []
            ]);
        }

        $userlist = [];
        foreach ($list as $key => $value) {
            if ($token['uid'] && $value->uid == $token['uid']) {
                continue;
            }

            if ($token['uin'] && $value->uin == $token['uin']) {
                continue;
            }

            if ($value->uid > 0) {
                $userlist['uid'][] = $value->uid;
            }

            if ($value->uin > 0) {
                $userlist['uin'][] = $value->uin;
            }
        }

        if (empty($userlist)) {
            show_json([
                'code' => 200,
                'message' => '数据不存在',
                'data' => []
            ]);
        }

        $data = [];

        $data = app('user')
            ->select('uid,uin,username,nickname,regip,regdate')
            ->where(function ($query) use ($userlist) {
                $userlist['uid'] && $query->orWhere(['uid' => $userlist['uid']]);
                $userlist['uin'] && $query->orWhere(['uin' => $userlist['uin']]);
            })
            ->limit(100)
            ->get();

        foreach ($data as $key => $value) {
            // 判断regip是否为全数字 如果是 long2ip 转换
            if (is_numeric($value['regip'])) {
                $data[$key]['regip'] = long2ip($value['regip']);
            }
            $data[$key]['avatar'] = avatar($value['uin'] ?: $value['uid'], '', $value['uin'] ? 1 : 0);
        }

        show_json([
            'code' => 200,
            'data' => $data,
        ]);
    }
}
