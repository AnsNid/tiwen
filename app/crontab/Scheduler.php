<?php

declare(strict_types=1);

namespace App\crontab;

use SplQueue;

class Scheduler {

    public const SUNDAY = 0;

    public const MONDAY = 1;

    public const TUESDAY = 2;

    public const WEDNESDAY = 3;

    public const THURSDAY = 4;

    public const FRIDAY = 5;

    public const SATURDAY = 6;

    protected SplQueue $schedules;

    public function __construct(protected CrontabManager $crontabManager) {
        $this->schedules = new SplQueue();
    }

    public function schedule(): SplQueue {
        foreach ($this->getSchedules() as $schedule) {
            $this->schedules->enqueue($schedule);
        }
        return $this->schedules;
    }

    protected function getSchedules(): array {
        return $this->crontabManager->parse();
    }
}
