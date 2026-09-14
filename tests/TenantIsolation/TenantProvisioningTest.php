<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\PlatformAuditLog;
use App\Kernel\Tenancy\Enums\OperationStatus;
use App\Kernel\Tenancy\Enums\OperationType;
use App\Kernel\Tenancy\Enums\ProvisioningStatus;
use App\Kernel\Tenancy\Enums\TenantStatus;
use App\Kernel\Tenancy\Exceptions\InvalidTenantDatabaseName;
use App\Kernel\Tenancy\Exceptions\TenantProvisioningFailed;
use App\Kernel\Tenancy\Infrastructure\TenantOperation;
use App\Kernel\Tenancy\TenantDatabaseName;
use App\Kernel\Tenancy\TenantProvisioningService;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestDatabaseManager;

/*
|--------------------------------------------------------------------------
| Provisioning
|--------------------------------------------------------------------------
|
| Isolation case 14 (docs/11-TESTING-STRATEGY.md §4) and the pipeline in
| docs/02-TENANCY.md §8.2.
|
| The property under test is not "provisioning works" but "provisioning never
| lies": a tenant that failed at any step must not look ready, and must be
| retryable.
|
*/

it('provisions a tenant end to end', function (): void {
    $tenant = $this->provisionTenant('Barbershop Alpha', ['alpha.metastyle.test']);

    expect($tenant->status)->toBe(TenantStatus::Active)
        ->and($tenant->provisioningStatus)->toBe(ProvisioningStatus::Completed)
        ->and($tenant->isProvisioned())->toBeTrue()
        ->and($tenant->databaseName)->toBe(TenantDatabaseName::forSequence(1));

    // The seeded system data proves the database was created, migrated,
    // seeded and is readable through tenant context.
    $settings = $this->asTenant($tenant, fn (): array => DB::connection('tenant')
        ->table('settings')->pluck('value', 'key')->all());

    expect(array_keys($settings))
        ->toContain('tenant_key', 'tenant_name', 'schema_version', 'provisioned_at');
});

it('derives the database name from the internal sequence, never the center name', function (): void {
    // A hostile display name must not influence a SQL identifier.
    $tenant = $this->provisionTenant("Salon'; DROP DATABASE meta_style_control; --");

    expect($tenant->databaseName)->toBe(TenantDatabaseName::forSequence($tenant->sequence))
        ->and(TenantDatabaseName::isValid($tenant->databaseName))->toBeTrue()
        ->and($tenant->databaseName)->not->toContain('DROP');
});

it('gives sequential tenants distinct databases', function (): void {
    $first = $this->provisionTenant('First');
    $second = $this->provisionTenant('Second');

    expect($second->sequence)->toBe($first->sequence + 1)
        ->and($first->databaseName)->not->toBe($second->databaseName);
});

it('does not mark a tenant ready when provisioning fails', function (): void {
    $service = app(TenantProvisioningService::class);

    // Provision, then corrupt the database name and re-run: the pipeline must
    // refuse the invalid identifier and leave the tenant visibly broken.
    $tenant = $this->provisionTenant('Will Break');
    $model = $this->tenantModel($tenant);

    $model->forceFill([
        'tenancy_db_name' => 'not_a_valid_name',
        'status' => TenantStatus::Provisioning->value,
        'provisioning_status' => ProvisioningStatus::Pending->value,
    ])->save();

    expect(fn () => $service->retry($model))->toThrow(TenantProvisioningFailed::class);

    $model->refresh();

    // Never Active. A half-provisioned tenant that looks ready is worse than
    // one that is plainly broken.
    expect($model->status)->toBe(TenantStatus::Failed->value)
        ->and($model->provisioning_status)->toBe(ProvisioningStatus::Failed->value);
});

it('makes a provisioning failure observable and retryable', function (): void {
    $tenant = $this->provisionTenant('Retryable');
    $model = $this->tenantModel($tenant);
    $goodName = (string) $model->tenancy_db_name;

    $model->forceFill([
        'tenancy_db_name' => 'not_a_valid_name',
        'status' => TenantStatus::Provisioning->value,
        'provisioning_status' => ProvisioningStatus::Pending->value,
    ])->save();

    try {
        app(TenantProvisioningService::class)->retry($model);
    } catch (TenantProvisioningFailed) {
        // expected
    }

    $failed = TenantOperation::query()
        ->where('tenant_id', $tenant->id)
        ->where('type', OperationType::Provision->value)
        ->where('status', OperationStatus::Failed->value)
        ->first();

    expect($failed)->not->toBeNull()
        ->and($failed->error)->toBeString()->not->toBeEmpty()
        ->and(ProvisioningStatus::from($model->refresh()->provisioning_status)->isRetryable())->toBeTrue();

    // Fix the cause and retry: it resumes rather than starting over.
    $model->forceFill(['tenancy_db_name' => $goodName])->save();

    $recovered = app(TenantProvisioningService::class)->retry($model);

    expect($recovered->status)->toBe(TenantStatus::Active)
        ->and($recovered->provisioningStatus)->toBe(ProvisioningStatus::Completed);

    // The attempt counter shows both tries — the history is not overwritten.
    expect(TenantOperation::query()
        ->where('tenant_id', $tenant->id)
        ->where('type', OperationType::Provision->value)
        ->count())->toBeGreaterThanOrEqual(2);
});

it('audits the tenant lifecycle', function (): void {
    $tenant = $this->provisionTenant('Audited');

    $actions = PlatformAuditLog::query()
        ->where('tenant_id', $tenant->id)
        ->pluck('action')
        ->all();

    expect($actions)->toContain(
        'tenancy.tenant.created',
        'tenancy.database.provision_attempted',
        'tenancy.database.provision_succeeded',
        'tenancy.tenant.migration_attempted',
        'tenancy.tenant.migration_succeeded',
        'tenancy.tenant.provisioned',
    );
});

it('audits a provisioning failure as critical', function (): void {
    $tenant = $this->provisionTenant('Failing');
    $model = $this->tenantModel($tenant);

    $model->forceFill([
        'tenancy_db_name' => 'not_a_valid_name',
        'provisioning_status' => ProvisioningStatus::Pending->value,
    ])->save();

    try {
        app(TenantProvisioningService::class)->retry($model);
    } catch (TenantProvisioningFailed) {
        // expected
    }

    $entry = PlatformAuditLog::query()
        ->where('tenant_id', $tenant->id)
        ->where('action', 'tenancy.database.provision_failed')
        ->first();

    expect($entry)->not->toBeNull()
        ->and($entry->severity)->toBe('critical')
        ->and($entry->meta['step'])->toBeString();
});

it('refuses a database name outside the naming policy', function (): void {
    TenantDatabaseName::assertValid('mysql');
})->throws(InvalidTenantDatabaseName::class);

it('refuses to manage a database outside the test prefix', function (): void {
    TestDatabaseManager::drop('meta_style_control');
})->throws(InvalidArgumentException::class);

afterEach(function (): void {
    $this->tearDownTenantDatabases();
});
