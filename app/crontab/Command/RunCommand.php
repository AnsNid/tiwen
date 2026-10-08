<?php

declare(strict_types=1);

namespace App\crontab\Command;

use xphp\Command\Command;
use xphp\Contract\ConfigInterface;
use App\crontab\Crontab;
use App\crontab\Event\CrontabDispatcherStarted;
use App\crontab\Exception\InvalidArgumentException;
use App\crontab\Scheduler;
use App\crontab\Strategy\Executor;
use Psr\Container\ContainerInterface;

class RunCommand extends Command {

    public function __construct(protected ContainerInterface $container) {
        parent::__construct('crontab:run');
    }

    public function handle() {

        $config = $this->container->get(ConfigInterface::class);
        $scheduler = $this->container->get(Scheduler::class);
        $executor = $this->container->get(Executor::class);

        if (! $config->get('crontab@config.enable', false)) {
            throw new InvalidArgumentException('Crontab is already disabled, please enable it first.');
        }

        $this->eventDispatcher?->dispatch(new CrontabDispatcherStarted());

        $this->line('Triggering Crontab', 'info');

        /** @var Crontab[] $crontabs */
        $crontabs = $scheduler->schedule();

        foreach ($crontabs as $crontab) {
            $executor->execute($crontab);
        }

        foreach ($crontabs as $crontab) {
            $crontab->wait();
        }
    }
}
