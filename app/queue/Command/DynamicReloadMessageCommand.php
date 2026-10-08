<?php

declare(strict_types=1);


namespace App\queue\Command;

use App\queue\AnnotationJob;
use App\queue\Driver\ChannelConfig;
use App\queue\Driver\DriverFactory;
use App\queue\Driver\RedisDriver;
use App\queue\JobInterface;
use App\queue\JobMessage;
use xphp\Codec\Json;
use xphp\Command\Command as XphpCommand;
use xphp\Redis\RedisProxy;
use xphp\Stringable\Str;
use xphp\Support\Reflection\ClassInvoker;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class DynamicReloadMessageCommand extends XphpCommand {
    protected ContainerInterface $container;

    public function __construct(ContainerInterface $container) {
        $this->container = $container;
        parent::__construct('queue:dynamic-reload');
    }

    public function handle() {
        $name = $this->input->getArgument('name');
        $queue = $this->input->getOption('queue');
        $job = $this->input->getOption('job');
        $limit = (int) $this->input->getOption('limit');
        $reload = $this->input->getOption('reload');

        $factory = $this->container->get(DriverFactory::class);
        $driver = $factory->get($name);
        if (! $driver instanceof RedisDriver) {
            $this->error("Don't support driver " . $driver::class);
            return 0;
        }

        $ref = new ClassInvoker($driver);
        /** @phpstan-ignore-next-line */
        $redis = $ref->redis;
        /** @phpstan-ignore-next-line */
        $channel = $ref->channel;

        if (! $reload) {
            $this->show($channel, $redis, $queue, $limit, $job);
            return 0;
        }

        $this->reload($channel, $redis, $queue, $limit, $job);
    }

    public function reload(ChannelConfig $channel, RedisProxy $redis, string $queue, int $limit, ?string $jobName = null): void {
        $index = 0;
        $key = $channel->get($queue);
        if (! $limit) {
            $limit = (int) $redis->llen($key);
        }

        while (true) {
            $data = $redis->rPop($key);
            ++$index;
            if (! $data) {
                break;
            }

            /** @var JobMessage $jobMessage */
            $jobMessage = unserialize($data);
            $job = $jobMessage->job();

            if ($job instanceof AnnotationJob) {
                $name = $job->class . '::' . $job->method;
            } else {
                $name = $job::class;
            }

            if ($jobName === null || $name === $jobName) {
                $redis->lPush($channel->getWaiting(), $data);
                $this->output->writeln('Reload Job: ' . $name);
            } else {
                $redis->lPush($key, $data);
                $this->output->writeln('RePush Job: ' . $name);
            }

            if ($index >= $limit) {
                return;
            }
        }
    }

    public function show(ChannelConfig $channel, RedisProxy $redis, string $queue, int $limit, ?string $jobName = null) {
        $key = $channel->get($queue);
        $index = 0;
        while (true) {
            $data = $redis->lIndex($key, $index);
            ++$index;
            if (! $data) {
                break;
            }

            /** @var JobMessage $jobMessage */
            $jobMessage = unserialize($data);
            /** @var AnnotationJob|JobInterface $job */
            $job = $jobMessage->job();
            if ($job instanceof AnnotationJob) {
                $name = $job->class . '::' . $job->method;
                $params = Json::encode($job->params);
            } else {
                $name = $job::class;
                $params = Json::encode(get_object_vars($job));
            }

            if (! $jobName || $jobName === $name) {
                $this->output->writeln('Job: ' . $name . ' [' . Str::limit($params, 1000) . ']');
            }

            if ($limit > 0 && $index >= $limit) {
                return;
            }
        }
    }

    protected function configure() {
        $this->setDescription('Reload all failed message into waiting queue.');
        $this->addArgument('name', InputArgument::OPTIONAL, 'The name of queue.', 'default');
        $this->addOption('queue', 'Q', InputOption::VALUE_OPTIONAL, 'The channel name of queue.', 'failed');
        $jobHelp = 'If you use job which implements JobInterface, you can input class name like `App\Job\FooJob`' . PHP_EOL;
        $jobHelp .= 'If you use annotation `App\queue\Annotation\AsyncQueueMessage`, you can input `class::method` like `App\Service\FooService::handleJob`' . PHP_EOL;
        $jobHelp .= 'If you don\'t input job, the command only show the messages.';
        $this->addOption('job', 'J', InputOption::VALUE_OPTIONAL, 'The job name which will be reloaded to queue. ' . PHP_EOL . $jobHelp);
        $this->addOption('limit', 'L', InputOption::VALUE_OPTIONAL, 'The number of retrieved messages.');
        $this->addOption('reload', 'R', InputOption::VALUE_NONE, 'Whether to reload the message queue.');
    }
}
