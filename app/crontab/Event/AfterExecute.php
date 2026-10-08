<?php

declare(strict_types=1);


namespace App\crontab\Event;

use App\crontab\Crontab;

class AfterExecute extends Event {

    public function __construct(Crontab $crontab, public mixed $result = null) {
        parent::__construct($crontab);
    }
}
