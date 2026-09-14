<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy\Infrastructure;

use App\Kernel\Tenancy\Enums\MigrationStatus;
use App\Kernel\Tenancy\Enums\ProvisioningStatus;
use App\Kernel\Tenancy\Enums\TenantStatus;
use App\Kernel\Tenancy\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * The control-plane Eloquent model for a tenant.
 *
 * This class is INSIDE the tenancy infrastructure layer, which is the only
 * place `Stancl\*` may be referenced (ADR-018, enforced by an architecture
 * test). Application code receives the immutable
 * {@see Tenant} value object instead — see toValueObject().
 *
 * @property string $id
 * @property int $sequence
 * @property string $name
 * @property string|null $public_key
 * @property int $entitlements_version
 * @property int|null $trial_days_override
 * @property string $status
 * @property string $provisioning_status
 * @property string|null $tenancy_db_name
 * @property string|null $db_host
 * @property string|null $schema_version
 * @property string $migration_status
 * @property Carbon|null $last_migration_at
 * @property string|null $last_migration_error
 * @property Carbon|null $provisioned_at
 */
final class TenantModel extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    /**
     * Real table columns. Anything not listed here would be silently folded
     * into the `data` JSON column by the package's virtual-column support,
     * which is exactly the kind of surprise we do not want on lifecycle state.
     *
     * @return list<string>
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'sequence',
            'public_key',
            'name',
            'status',
            'provisioning_status',
            'tenancy_db_name',
            'db_host',
            'schema_version',
            'entitlements_version',
            'trial_days_override',
            'migration_status',
            'last_migration_at',
            'last_migration_error',
            'provisioned_at',
            'suspended_at',
            'archived_at',
            // Without these, the virtual-column trait folds the timestamps
            // into the `data` JSON blob instead of the real columns.
            'created_at',
            'updated_at',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'entitlements_version' => 'integer',
            'trial_days_override' => 'integer',
            'last_migration_at' => 'datetime',
            'provisioned_at' => 'datetime',
            'suspended_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Translates the persistence model into the value object the rest of the
     * application sees.
     */
    public function toValueObject(): Tenant
    {
        return new Tenant(
            id: $this->id,
            sequence: $this->sequence,
            name: $this->name,
            status: TenantStatus::from($this->status),
            databaseName: (string) $this->tenancy_db_name,
            provisioningStatus: ProvisioningStatus::from($this->provisioning_status),
            migrationStatus: MigrationStatus::from($this->migration_status),
            schemaVersion: $this->schema_version,
            publicKey: $this->public_key,
        );
    }

    /**
     * Tenants eligible for a migration run: those that actually have a
     * database. A tenant still provisioning, or already archived, is skipped
     * rather than counted as failed.
     *
     * @param  Builder<TenantModel>  $query
     * @return Builder<TenantModel>
     */
    public function scopeMigratable(Builder $query): Builder
    {
        return $query
            ->whereIn('status', array_map(
                fn (TenantStatus $status): string => $status->value,
                array_filter(TenantStatus::cases(), fn (TenantStatus $s): bool => $s->isMigratable()),
            ))
            ->where('provisioning_status', ProvisioningStatus::Completed->value);
    }
}
