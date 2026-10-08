<?php

declare(strict_types=1);


namespace App\mail\Mailable;

class Address {
    /**
     * @param string $address the recipient's email address
     * @param string|null $name the recipient's name
     */
    public function __construct(public string $address, public ?string $name = null) {
    }
}
