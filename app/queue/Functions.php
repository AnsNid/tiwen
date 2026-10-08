<?php

declare(strict_types=1);


namespace App\queue;

use App\queue\Driver\DriverFactory;
use xphp\Context\ApplicationContext;

function queue(JobInterface $job, ?int $delay = null, ?int $maxAttempts = null, ?string $pool = null, ?int $handleTimeout = null): bool {

    if (is_int($maxAttempts)) {
        $job->setMaxAttempts($maxAttempts);
    }

    if (is_int($handleTimeout) && method_exists($job, 'setHandleTimeout')) {
        $job->setHandleTimeout($handleTimeout);
    }

    return ApplicationContext::getContainer()
        ->get(DriverFactory::class)
        ->get($pool ?? 'default')
        ->push($job, $delay ?? 0);
}
