<?php

declare(strict_types=1);


namespace App\mail\Contract;

use App\mail\SentMessage;

interface Mailable {
    
    /**
     * Send the message using the given mailer.
     */
    public function send(Factory|Mailer $mailer): ?SentMessage;

    /**
     * Set the recipients of the message.
     */
    public function cc(object|array|string $address, ?string $name = null): static;

    /**
     * Set the recipients of the message.
     */
    public function bcc(object|array|string $address, ?string $name = null): static;

    /**
     * Set the recipients of the message.
     */
    public function to(object|array|string $address, ?string $name = null): static;

    /**
     * Set the locale of the message.
     */
    public function locale(string $locale): static;

    /**
     * Set the name of the mailer that should be used to send the message.
     */
    public function mailer(string $mailer): static;
}
