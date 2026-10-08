<?php

declare(strict_types=1);


namespace App\queue;

use Throwable;

interface JobInterface {


    public function fail(Throwable $e): void;

    /**
     * Handle the job.
     */
    public function handle();

    public function setMaxAttempts(int $maxAttempts): static;

    public function getMaxAttempts(): int;
}
