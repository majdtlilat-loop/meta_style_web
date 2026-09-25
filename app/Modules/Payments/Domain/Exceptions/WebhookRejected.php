<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use RuntimeException;

/**
 * A callback that is not believable: unparseable, missing its reference, or
 * failing signature verification. The answer to the caller is a bare refusal
 * with no reason — nothing that would help somebody forge the next one
 * (docs/19-PAYMENTS.md §§21, 84). The safe code is for our own records.
 */
final class WebhookRejected extends RuntimeException
{
    public static function because(string $safeCode): self
    {
        return new self($safeCode);
    }
}
