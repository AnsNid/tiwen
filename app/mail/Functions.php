<?php

declare(strict_types=1);

namespace App\mail;

use BackedEnum;
use xphp\Support\HtmlString;
use xphp\ViewEngine\Contract\Htmlable;

/**
 * Encode HTML special characters in a string.
 *
 * @param Htmlable|BackedEnum|string|int|float|null $value
 * @param bool $doubleEncode
 * @return string
 */
function e($value, $doubleEncode = true) {
    if ($value instanceof Htmlable) {
        return $value->toHtml();
    }
    if ($value instanceof HtmlString) {
        return $value->toHtml();
    }

    if ($value instanceof BackedEnum) {
        $value = $value->value;
    }

    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', $doubleEncode);
}
