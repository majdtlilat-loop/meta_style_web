<?php

declare(strict_types=1);

namespace App\Kernel\Observability;

use Illuminate\Support\Str;

/**
 * The correlation id for the current request, job, or command.
 *
 * Bound as a scoped singleton and injected — not a static or a global. Every
 * log line, error response, and (from Phase 2) audit entry and queued job
 * carries it, so one identifier ties a user-visible failure to everything the
 * system did about it.
 *
 * See docs/08-AUDIT-SECURITY.md §3 and docs/10-API-FOUNDATION.md §3.
 */
final class RequestId
{
    private string $value;

    public function __construct(?string $value = null)
    {
        $this->value = self::sanitize($value) ?? (string) Str::uuid();
    }

    public function value(): string
    {
        return $this->value;
    }

    public function set(?string $value): void
    {
        $this->value = self::sanitize($value) ?? $this->value;
    }

    /**
     * Client-supplied ids are echoed for correlation, so they are untrusted
     * input: bounded length, safe characters only. An id is a label, never a
     * lookup key or an authorization input.
     */
    private static function sanitize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || strlen($value) > 128) {
            return null;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $value) === 1 ? $value : null;
    }
}
