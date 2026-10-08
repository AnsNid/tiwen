<?php

declare(strict_types=1);


namespace App\queue\Process;

use App\queue\Driver\DriverFactory;
use App\queue\Driver\DriverInterface;
use xphp\Process\AbstractProcess;
use Psr\Container\ContainerInterface;

class ConsumerProcess extends AbstractProcess {

    protected string $queue = 'default';

    protected DriverInterface $driver;

    protected array $config;

    public function __construct(ContainerInterface $container) {
        parent::__construct($container);

        $factory = $this->container->get(DriverFactory::class);
        $this->driver = $factory->get($this->queue);
        $this->config = $factory->getConfig($this->queue);

        $this->name = "queue.{$this->queue}";
        $this->nums = $this->config['processes'] ?? 1;
    }

    public function handle(): void {
        $this->driver->consume();
    }
}
