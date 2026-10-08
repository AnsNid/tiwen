<?php

namespace App\user\Model;

use xphp\DbConnection\Model\Model;

/**
 * 会员权限
 */
class Authority extends Model {

    protected ?string $table = 'member_authority';
}
