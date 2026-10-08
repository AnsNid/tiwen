<?php

/**
 * 会员用户组
 */

namespace App\user\Model;

use xphp\DbConnection\Model\Model;

class Group extends Model {

    protected ?string $table = 'member_groups';
}
