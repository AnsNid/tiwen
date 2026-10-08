<?php

declare(strict_types=1);

namespace App\mail\Contract;

use App\mail\Attachment;

interface Attachable {
    /**
     * Get an attachment instance for this entity.
     */
    public function toMailAttachment(): Attachment;
}
