<?php

declare(strict_types=1);

namespace App\queue\Command;

use App\queue\Driver\DriverFactory;
use xphp\Command\Command as XphpCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

class InfoCommand extends XphpCommand {
    
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
        parent::__construct('queue:info');
    }

    public function handle() {
        $name = $this->input->getArgument('name');
        $factory = $this->container->get(DriverFactory::class);
        $driver = $factory->get($name);

        $info = $driver->info();
        foreach ($info as $key => $count) {
            $this->output->writeln(sprintf('<fg=green>%s count is %d.</>', $key, $count));
        }
    }

    protected function configure() {
        $this->setDescription('Get all messages from the queue.');
        $this->addArgument('name', InputArgument::OPTIONAL, 'The name of queue.', 'default');
    }
}
