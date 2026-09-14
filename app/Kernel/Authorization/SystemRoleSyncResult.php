<?php

declare(strict_types=1);

namespace App\Kernel\Authorization;

use App\Kernel\Tenancy\TenantMigrationResult;

/**
 * What a system-role synchronisation did to one tenant.
 *
 * A value object rather than an exception, because a run across every tenant
 * must report each one's fate instead of stopping at the first failure — the
 * same reason {@see TenantMigrationResult} exists
 * (docs/03-DATABASE-MIGRATIONS.md §4).
 */
final readonly class SystemRoleSyncResult
{
    private function __construct(
        public string $tenantId,
        public string $outcome,
        public int $rolesCreated = 0,
        public int $permissionsAdded = 0,
        public ?string $error = null,
    ) {}

    public static function synced(string $tenantId, int $rolesCreated, int $permissionsAdded): self
    {
        return new self($tenantId, 'synced', $rolesCreated, $permissionsAdded);
    }

    public static function failed(string $tenantId, string $error): self
    {
        return new self($tenantId, 'failed', error: $error);
    }

    public static function skipped(string $tenantId, string $reason): self
    {
        return new self($tenantId, 'skipped', error: $reason);
    }

    public function isSynced(): bool
    {
        return $this->outcome === 'synced';
    }

    public function isFailed(): bool
    {
        return $this->outcome === 'failed';
    }

    public function isSkipped(): bool
    {
        return $this->outcome === 'skipped';
    }

    /** Did anything actually change? */
    public function changedAnything(): bool
    {
        return $this->rolesCreated > 0 || $this->permissionsAdded > 0;
    }
}
