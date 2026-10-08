<?php

declare(strict_types=1);


namespace App\queue;

use xphp\Context\Context;

class Environment {

    public function isAsyncQueue(): bool {
        return (bool) Context::get($this->getKey(), false);
    }

    public function setAsyncQueue(bool $asyncQueue): static {
        Context::set($this->getKey(), $asyncQueue);
        return $this;
    }

    protected function getKey(): string {
        return self::class . '::isAsyncQueue';
    }
}
