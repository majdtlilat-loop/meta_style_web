<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Exceptions;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Two trusted resolution sources named different tenants.
 *
 * This is never resolved by preferring one source. Disagreement means either a
 * bug or an attack, and both are answered the same way: reject the request and
 * audit it (docs/02-TENANCY.md §2.2).
 *
 * Carries its own 403 so BOTH surfaces refuse cleanly. The API renderer maps it
 * to TENANT.RESOLUTION_CONFLICT; web routes would otherwise fall through to a
 * 500, which turns a security refusal into what looks like an outage — and, in
 * a misconfigured environment, prints the two tenant ids in a stack trace.
 */
final class TenantResolutionConflict extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(
        public readonly string $firstSource,
        public readonly string $firstTenantId,
        public readonly string $secondSource,
        public readonly string $secondTenantId,
    ) {
        parent::__construct(sprintf(
            'Tenant resolution conflict: [%s] resolved tenant %s but [%s] resolved tenant %s. Refusing to guess.',
            $firstSource,
            $firstTenantId,
            $secondSource,
            $secondTenantId,
        ));
    }

    public function getStatusCode(): int
    {
        return 403;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
