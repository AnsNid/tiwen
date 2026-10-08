<?php

declare(strict_types=1);


namespace App\mail\Event;

use Symfony\Component\Mime\Email;

class MessageSending {
    public bool $shouldSend = true;

    public function __construct(public Email $message, public array $data = []) {
    }

    public function setShouldSend(bool $shouldSend): void {
        $this->shouldSend = $shouldSend;
    }

    public function shouldSend(): bool {
        return $this->shouldSend;
    }
}
