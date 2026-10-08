<?php

declare(strict_types=1);


namespace App\queue\Command;

use App\queue\Driver\DriverFactory;
use xphp\Command\Command as XphpCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class ReloadFailedMessageCommand extends XphpCommand {
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
        parent::__construct('queue:reload');
    }

    public function handle() {

        $name = $this->input->getArgument('name');
        $queue = $this->input->getOption('queue');

        $factory = $this->container->get(DriverFactory::class);
        $driver = $factory->get($name);

        $num = $driver->reload($queue);

        $this->output->writeln(sprintf('<fg=green>Reload %d failed message into waiting queue.</>', $num));
    }

    protected function configure() {
        $this->setDescription('Reload all failed message into waiting queue.');
        $this->addArgument('name', InputArgument::OPTIONAL, 'The name of queue.', 'default');
        $this->addOption('queue', 'Q', InputOption::VALUE_OPTIONAL, 'The channel name of queue.');
    }
}
