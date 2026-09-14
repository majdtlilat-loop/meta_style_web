<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * No tenant could be identified for the request.
 *
 * There is deliberately no fallback and no default tenant: guessing is how a
 * request ends up reading someone else's data (docs/02-TENANCY.md §2.2).
 *
 * Answered as a plain 404 on both surfaces. "Not found" rather than "no tenant
 * here" because a distinguishable response would let anyone enumerate which
 * centers exist by trying hostnames.
 */
final class TenantNotResolved extends RuntimeException implements HttpExceptionInterface
{
    public static function forHost(string $host): self
    {
        return new self("No tenant is registered for host [{$host}].");
    }

    public static function forKey(string $key): self
    {
        return new self("No active tenant matches the supplied key [{$key}].");
    }

    public function getStatusCode(): int
    {
        return 404;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
