<?php

declare(strict_types=1);


namespace App\mail\Contract;

use Closure;
use App\mail\Mailable;
use App\mail\PendingMail;
use App\mail\SentMessage;

interface Mailer {
    
    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function to(mixed $users): PendingMail;

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function bcc(mixed $users): ?PendingMail;

    /**
     * Send a new message with only a raw text part.
     */
    public function raw(string $text, mixed $callback): ?SentMessage;

    /**
     * Send a new message using a view.
     */
    public function send(Mailable|string|array $view, array $data = [], Closure|string|null $callback = null): ?SentMessage;
}
