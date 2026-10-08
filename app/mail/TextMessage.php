<?php

declare(strict_types=1);


namespace App\mail;

use App\mail\Contract\Attachable;
use xphp\Support\Traits\ForwardsCalls;

/**
 * @mixin Message
 */
class TextMessage {

    use ForwardsCalls;

    public function __construct(protected Message $message) {
    }

    /**
     * Dynamically pass missing methods to the underlying message instance.
     * @param mixed $method
     * @param mixed $parameters
     */
    public function __call($method, $parameters) {
        $result = $this->forwardCallTo($this->message, $method, $parameters);
        return $result === $this->message ? $this : $result;
    }

    /**
     * Embed a file in the message and get the CID.
     */
    public function embed(string|Attachable|Attachment $file): string {
        return '';
    }

    /**
     * Embed in-memory data in the message and get the CID.
     */
    public function embedData(mixed $data, string $name, ?string $contentType = null): string {
        return '';
    }
}
