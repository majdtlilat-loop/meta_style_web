<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy;

/**
 * The outcome of migrating one tenant.
 *
 * A value object rather than an exception, because a batch migration must
 * report every tenant's fate — including the failures — instead of stopping at
 * the first one (docs/03-DATABASE-MIGRATIONS.md §4).
 */
final readonly class TenantMigrationResult
{
    private function __construct(
        public string $tenantId,
        public string $outcome,
        public ?string $error = null,
    ) {}

    public static function succeeded(string $tenantId): self
    {
        return new self($tenantId, 'succeeded');
    }

    public static function failed(string $tenantId, string $error): self
    {
        return new self($tenantId, 'failed', $error);
    }

    /** Another process holds the lock for this tenant. Not a failure. */
    public static function skipped(string $tenantId, string $reason): self
    {
        return new self($tenantId, 'skipped', $reason);
    }

    public function isSucceeded(): bool
    {
        return $this->outcome === 'succeeded';
    }

    public function isFailed(): bool
    {
        return $this->outcome === 'failed';
    }

    public function isSkipped(): bool
    {
        return $this->outcome === 'skipped';
    }
}
