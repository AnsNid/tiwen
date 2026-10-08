<?php

declare(strict_types=1);

namespace App\crontab\Model;

use xphp\DbConnection\Model\Model;

class CrontabTask extends Model {
    protected ?string $table = 'crontab_task';
    protected string $primaryKey = 'id';
}
