<?php

declare(strict_types=1);


namespace App\crontab\Mutex;

use App\crontab\Crontab;

interface TaskMutex {
    /**
     * Attempt to obtain a task mutex for the given crontab.
     */
    public function create(Crontab $crontab): bool;

    /**
     * Determine if a task mutex exists for the given crontab.
     */
    public function exists(Crontab $crontab): bool;

    /**
     * Clear the task mutex for the given crontab.
     */
    public function remove(Crontab $crontab);
}
