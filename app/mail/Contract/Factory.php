<?php

declare(strict_types=1);

namespace App\mail\Contract;

interface Factory {
    /**
     * Get a mailer instance by name.
     */
    public function mailer(?string $name = null): Mailer;
}
