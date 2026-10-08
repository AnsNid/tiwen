<?php

declare(strict_types=1);


namespace App\queue\Listener;

use App\queue\Event\QueueLength;
use xphp\Contract\StdoutLoggerInterface;
use xphp\Event\Contract\ListenerInterface;

class ReloadChannelListener implements ListenerInterface {
    
    /**
     * @var string[]
     */
    protected $channels = [
        'timeout',
    ];

    public function __construct(protected StdoutLoggerInterface $logger) {
    }

    public function listen(): array {
        return [
            QueueLength::class,
        ];
    }

    /**
     * @param QueueLength $event
     */
    public function process(object $event): void {

        if (! $event instanceof QueueLength) {
            return;
        }

        if (! in_array($event->key, $this->channels)) {
            return;
        }

        if ($event->length == 0) {
            return;
        }

        $event->driver->reload($event->key);

        $this->logger->info(sprintf('%s channel reload %d messages to waiting channel success.', $event->key, $event->length));
    }
}
