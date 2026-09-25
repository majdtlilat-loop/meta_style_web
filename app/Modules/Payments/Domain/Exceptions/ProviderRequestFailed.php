<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

use RuntimeException;

/**
 * A provider call did not produce a usable answer: a timeout, a refused
 * connection, a status code the contract does not describe, a body that is not
 * what the documentation says.
 *
 * The message is a SAFE code chosen by the adapter — `fib.token_failed`,
 * `fib.status_unreadable` — never the provider's body, a header or a token. The
 * Action translates it into {@see PaymentFailed::providerUnavailable()} for the
 * caller; nothing about the payment changes.
 */
final class ProviderRequestFailed extends RuntimeException
{
    public static function because(string $safeCode): self
    {
        return new self($safeCode);
    }
}
