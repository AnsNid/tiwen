<?php

declare(strict_types=1);


namespace App\queue\Event;

use App\queue\MessageInterface;

class Event {
    protected ?string $channel = null;

    public function __construct(protected MessageInterface $message) {
    }

    public function getMessage(): MessageInterface {
        return $this->message;
    }

    public function setChannel(?string $channel): static {
        $this->channel = $channel;

        return $this;
    }

    public function getChannel(): ?string {
        return $this->channel;
    }
}
