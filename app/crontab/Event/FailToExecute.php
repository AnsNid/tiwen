<?php

declare(strict_types=1);


namespace App\crontab\Event;

use App\crontab\Crontab;
use Throwable;

class FailToExecute extends Event {

    public function __construct(Crontab $crontab, public Throwable $throwable) {
        parent::__construct($crontab);
    }

    public function getThrowable(): Throwable {
        return $this->throwable;
    }
}
