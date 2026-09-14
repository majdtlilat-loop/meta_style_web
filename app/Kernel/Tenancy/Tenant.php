<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy;

use App\Kernel\Tenancy\Enums\MigrationStatus;
use App\Kernel\Tenancy\Enums\ProvisioningStatus;
use App\Kernel\Tenancy\Enums\TenantStatus;

/**
 * What the application knows about the current tenant.
 *
 * An immutable value object, deliberately NOT the Eloquent model and not the
 * tenancy package's model. Business modules depend on this; only
 * App\Kernel\Tenancy\Infrastructure touches the package (ADR-018).
 *
 * It carries identity and lifecycle state — never credentials, and never the
 * connection configuration.
 */
final readonly class Tenant
{
    public function __construct(
        public string $id,
        public int $sequence,
        public string $name,
        public TenantStatus $status,
        public string $databaseName,
        public ProvisioningStatus $provisioningStatus,
        public MigrationStatus $migrationStatus,
        public ?string $schemaVersion = null,
        /**
         * The opaque identifier a client presents to say which center it means
         * (ADR-027), and the one the public menu is addressed by (ADR-036).
         *
         * Identity, not a credential: it authorises nothing on its own, it is
         * revocable, and it is never the internal sequence.
         */
        public ?string $publicKey = null,
    ) {}

    /**
     * The key used for storage prefixes, cache tags and log context.
     *
     * Always the UUID, never the sequence: the sequence leaks how many centers
     * exist and the order they signed up.
     */
    public function key(): string
    {
        return $this->id;
    }

    public function isActive(): bool
    {
        return $this->status === TenantStatus::Active;
    }

    public function isProvisioned(): bool
    {
        return $this->provisioningStatus === ProvisioningStatus::Completed;
    }

    /**
     * Safe for log lines and audit payloads.
     *
     * @return array<string, string|int|null>
     */
    public function toLogContext(): array
    {
        return [
            'tenant_id' => $this->id,
            'tenant_sequence' => $this->sequence,
            'tenant_status' => $this->status->value,
        ];
    }
}
