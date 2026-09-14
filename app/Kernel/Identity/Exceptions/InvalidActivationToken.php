<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Exceptions;

use RuntimeException;

/**
 * An activation token was missing, expired, already used, or revoked.
 *
 * One message for all four, for the same reason as AuthenticationFailed.
 */
final class InvalidActivationToken extends RuntimeException
{
    public static function unusable(): self
    {
        return new self('This activation link is no longer valid.');
    }
}
