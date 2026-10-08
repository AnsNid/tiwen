<?php

declare(strict_types=1);


namespace App\queue\Event;

use App\queue\Driver\DriverInterface;

class QueueLength {
    public function __construct(public DriverInterface $driver, public string $key, public int $length) {
    }
}
