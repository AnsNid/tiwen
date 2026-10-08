<?php

/**
 * 会员用户组
 */

namespace App\user;


class Group {

    /**
     * Get all member groups
     */
    public function type_all() {

        $list = m('user.group')
            ->orderBy('sort', 'desc')
            ->orderBy('id', 'asc')
            ->select('id,type,title,message,anicount,aid')
            ->get();

        $groups = [
            'member' => [],
            'default' => [],
            'system' => [],
        ];

        foreach ($list as $value) {
            $groups[$value->type][] = $value->toArray();
        }
        return $groups;
    }


    public function update($user = []) {

        $user = is_numeric($user) ? ['uid' => $user] : $user;


        if ((int) $user['uid'] === 0) {
            return [];
        }

        if (!isset($user['groupid']) && !isset($user['groupexpiry'])) {
            $user = app('user')->where(['uid' => $user['uid']])->field('uid,groupid,groupexpiry')->one();
        }


        $groupList = $this->type_all();
        $defaultGroupid = array_column($groupList['default'], 'id');
        $lneed = array_column($groupList['member'], 'anicount', 'id');
        $experience = (float) event('user.group@experience', $user);


        if ($user['groupid'] && in_array($user['groupid'], $defaultGroupid)) {
            if ((int) $user['groupexpiry'] === 0 || $user['groupexpiry'] > time()) {
                return event('user.group@update', [
                    'groupid' => $user['groupid'],
                    'experience' => $experience,
                    'groupexpiry' => $user['groupexpiry'],
                ]);
            }
        }

        if (empty($lneed)) {
            return event('user.group@update', [
                'groupid' => 0,
                'experience' => $experience,
                'groupexpiry' => 0,
            ]);
        }

        arsort($lneed);
        $groupid = 0;
        foreach ($lneed as $key => $lowneed) {
            if ((int) $experience >= $lowneed) {
                $groupid = $key;
                break;
            }
        }

        return event('user.group@update', [
            'groupid' => $groupid,
            'experience' => $experience,
            'groupexpiry' => 0,
        ]);
    }

    /**
     * Get a member group
     */
    public function get($groupid, $callback = null) {
        $groupid = intval($groupid);
        if (empty($groupid)) {
            return false;
        }

        $info = m('user.group')->where(['id' => $groupid])->one();
        if ($callback && is_callable($callback)) {
            return call_user_func($callback, $info);
        }

        return $info;
    }

    /**
     * Delete a member group
     */
    public function delete($groupid = 0) {

        return $this->get($groupid, function ($info) {
            if (empty($info)) {
                return false;
            }

            if (m('user.group')->where(['id' => $info['id']])->delete()) {
                app('user')->where(['groupid' => $info['id']])->update(['groupid' => 0]);
                m('user.authority')->where(['groupid' => $info['id']])->delete();
            }
            return $info;
        });
    }

    /**
     * Cache user group levels
     */
    public function cache() {
        return true;
    }
}
