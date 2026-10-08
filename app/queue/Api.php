<?php

/**
 *
 */

namespace App\queue;

use App\queue\Driver\DriverFactory;
use xphp\Context\ApplicationContext;
use App\queue\JobInterface;


class Api {

    public function push(JobInterface $job, ?int $delay = null, ?int $maxAttempts = null, ?string $pool = null, ?int $handleTimeout = null): bool {

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

    public function pushAll(array $jobs, ?int $delay = null, ?string $pool = null): bool {
        return ApplicationContext::getContainer()
            ->get(DriverFactory::class)
            ->get($pool ?? 'default')
            ->pushAll($jobs, $delay ?? 0);
    }
}
