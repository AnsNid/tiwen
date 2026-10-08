<?php

declare(strict_types=1);

namespace App\crontab\Mutex;

use App\crontab\Crontab;

interface ServerMutex {
    /**
     * Attempt to obtain a server mutex for the given crontab.
     */
    public function attempt(Crontab $crontab): bool;

    /**
     * Get the server mutex for the given crontab.
     */
    public function get(Crontab $crontab): string;
}
