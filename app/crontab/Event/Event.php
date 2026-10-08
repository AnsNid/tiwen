<?php

declare(strict_types=1);


namespace App\crontab\Event;

use App\crontab\Crontab;

abstract class Event {
    public function __construct(public Crontab $crontab) {
    }
}
