<?php

namespace App\user\Model;

use xphp\DbConnection\Model\Model;

/**
 * 用户行为轨迹记录
 */

class Trace extends Model {

    // 需要入库的数据
    protected ?string $table = 'member_trace';
}
