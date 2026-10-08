<?php

declare(strict_types=1);


namespace App\queue\Annotation;

use Attribute;
use xphp\Di\Annotation\AbstractAnnotation;

/**
 * Don't call the methods with this annotation in async queue environment.
 * Because the execution or delivery of a message depends on whether it is currently in an async queue environment,
 * re delivery in an async queue environment will be treated as a direct execution of the message.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
class AsyncQueueMessage extends AbstractAnnotation {
    public function __construct(public string $pool = 'default', public int $delay = 0, public int $maxAttempts = 0) {
    }
}
