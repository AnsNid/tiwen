<?php

declare(strict_types=1);

namespace App\crontab;

class PipeMessage {
    public function __construct(public string $type, public array $callable, public Crontab $data) {
    }
}
