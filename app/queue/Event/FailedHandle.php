<?php

declare(strict_types=1);


namespace App\queue\Event;

use App\queue\MessageInterface;
use Throwable;

class FailedHandle extends Event {
    
    public function __construct(MessageInterface $message, protected Throwable $throwable) {
        parent::__construct($message);
    }

    public function getThrowable(): Throwable {
        return $this->throwable;
    }
}
