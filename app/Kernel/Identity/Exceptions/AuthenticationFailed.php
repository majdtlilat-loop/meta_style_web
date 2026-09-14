<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Exceptions;

use RuntimeException;

/**
 * Credentials were rejected.
 *
 * Deliberately one exception with one message for every cause — unknown
 * identifier, wrong password, inactive account, never-activated account. A
 * caller must not be able to tell which, or the endpoint becomes a way to
 * discover who works at a center (docs/02-TENANCY.md §2.3).
 */
final class AuthenticationFailed extends RuntimeException
{
    public static function invalidCredentials(): self
    {
        return new self('Those credentials do not match our records.');
    }
}
