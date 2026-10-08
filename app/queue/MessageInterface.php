<?php

declare(strict_types=1);


namespace App\queue;

interface MessageInterface {

    public function job(): JobInterface;

    /**
     * Whether the queue can be handle again.
     */
    public function attempts(): bool;

    /**
     * The current attempt count.
     */
    public function getAttempts(): int;

    public function setAttempts(int $attempts): void;

    /**
     * The unique id of the message, generated on push.
     */
    public function getUid(): string;
}
