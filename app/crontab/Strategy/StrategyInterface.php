<?php

declare(strict_types=1);

namespace App\crontab\Strategy;

use App\crontab\Crontab;

interface StrategyInterface {
    public function dispatch(Crontab $crontab);
}
