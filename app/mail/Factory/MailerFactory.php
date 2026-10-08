<?php

declare(strict_types=1);


namespace App\mail\Factory;

use App\mail\Contract\Factory;

class MailerFactory {
    public function __construct(protected readonly Factory $manager) {
    }

    public function __invoke() {
        return $this->manager->mailer();
    }
}
