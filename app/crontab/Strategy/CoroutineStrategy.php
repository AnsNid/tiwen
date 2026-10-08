<?php

declare(strict_types=1);


namespace App\crontab\Strategy;

use xphp\Coroutine\Coroutine;
use App\crontab\Crontab;

class CoroutineStrategy extends AbstractStrategy {
    public function dispatch(Crontab $crontab) {
        Coroutine::create(function () use ($crontab) {
            $executor = $this->container->get(Executor::class);
            $executor->execute($crontab);
        });
    }
}
