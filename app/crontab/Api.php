<?php

declare(strict_types=1);

namespace App\crontab;


class Api {

    /**
     * 注册定时任务
     */
    public function command(string $command, array $arguments = []): Crontab {
        // (new Crontab())->setName('Foo')->setRule('* * * * *')->setCallback([\App\security\Task\Audit::class, 'execute'])->setMemo('这是一个示例的定时任务');
        // Schedule::command('php ./xphp')->setName('Audit')->setRule('* * * * *')->setMemo('这是一个示例的定时任务');
        return Schedule::command($command, $arguments);
    }

    /**
     * 注册定时任务
     */
    public function call(mixed $callable): Crontab {
        return Schedule::call($callable);
    }
}
