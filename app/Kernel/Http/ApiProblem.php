<?php

declare(strict_types=1);

namespace App\Kernel\Http;

/**
 * An exception that already knows which API error it is.
 *
 * The Kernel may not import business modules (docs/04-MODULE-BOUNDARIES.md §2),
 * so {@see ApiExceptionRenderer} cannot name a module's exception class in a
 * `match` arm. It matches this interface instead — the module declares its own
 * error code, and a new module's refusals render correctly without the Kernel
 * learning anything about it.
 *
 * Implement it for a refusal a CLIENT should branch on. A generic failure has
 * no business inventing a code; it belongs in the 500 bucket where it is
 * logged.
 */
interface ApiProblem
{
    public function errorCode(): ApiErrorCode;

    /**
     * Extra machine-readable context, merged into `error.details`.
     *
     * Never anything private. These fields reach clients that may be
     * unauthenticated (docs/08-AUDIT-SECURITY.md §19).
     *
     * @return array<string, mixed>
     */
    public function errorDetails(): array;
}
