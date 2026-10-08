<?php

declare(strict_types=1);

namespace App\crontab\Mutex;

interface ServerNodeInterface {
    /**
     * Return the unique name for the server node.
     */
    public function getName(): string;
}
