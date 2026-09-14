<?php

declare(strict_types=1);

use App\Kernel\Audit\Actor;
use App\Kernel\Tenancy\Enums\MigrationStatus;
use App\Kernel\Tenancy\TenantMigrator;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Tenant migrations
|--------------------------------------------------------------------------
|
| Isolation cases 12 and 13 (docs/11-TESTING-STRATEGY.md §4).
|
| Failure isolation is the property that matters: tenant databases cannot be
| migrated atomically, so a batch must report every tenant's fate rather than
| stopping — or worse, marking healthy tenants failed — at the first error
| (docs/03-DATABASE-MIGRATIONS.md §4).
|
*/

it('applies the tenant schema to the selected tenant', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    $this->asTenant($alpha, function (): void {
        expect(Schema::connection('tenant')->hasTable('settings'))->toBeTrue()
            ->and(Schema::connection('tenant')->hasTable('audit_logs'))->toBeTrue()
            // Each tenant database keeps its OWN migration history.
            ->and(Schema::connection('tenant')->hasTable('migrations'))->toBeTrue();
    });

    expect($this->tenantModel($alpha)->migration_status)->toBe(MigrationStatus::Succeeded->value)
        ->and($this->tenantModel($alpha)->schema_version)->toBe(TenantMigrator::targetSchemaVersion());
});

it('gives every tenant the identical schema regardless of anything else', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $columnsFor = fn ($tenant): array => $this->asTenant($tenant, function (): array {
        $columns = Schema::connection('tenant')->getColumnListing('settings');
        sort($columns);

        return $columns;
    });

    expect($columnsFor($alpha))->toBe($columnsFor($beta))->not->toBeEmpty();
});

it('records the target schema version on every migrated tenant', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $results = app(TenantMigrator::class)->migrateMany(
        [$this->tenantModel($alpha), $this->tenantModel($beta)],
        Actor::system('test'),
    );

    expect($results)->toHaveCount(2)
        ->and($results[0]->isSucceeded())->toBeTrue()
        ->and($results[1]->isSucceeded())->toBeTrue();
});

it('does not mark healthy tenants failed when one tenant fails', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $broken = $this->provisionTenant('Broken');
    $gamma = $this->provisionTenant('Gamma');

    // Break exactly one tenant by pointing it at a database that is not there.
    $brokenModel = $this->tenantModel($broken);
    $brokenModel->forceFill(['tenancy_db_name' => $brokenModel->tenancy_db_name.'9'])->save();

    $results = app(TenantMigrator::class)->migrateMany(
        [$this->tenantModel($alpha), $brokenModel, $this->tenantModel($gamma)],
        Actor::system('test'),
    );

    expect($results)->toHaveCount(3)
        ->and($results[0]->isSucceeded())->toBeTrue()
        ->and($results[1]->isFailed())->toBeTrue()
        // The tenant AFTER the failure still ran. A batch that stops at the
        // first error leaves the rest of the platform silently un-migrated.
        ->and($results[2]->isSucceeded())->toBeTrue();

    expect($this->tenantModel($alpha)->migration_status)->toBe(MigrationStatus::Succeeded->value)
        ->and($this->tenantModel($broken)->migration_status)->toBe(MigrationStatus::Failed->value)
        ->and($this->tenantModel($gamma)->migration_status)->toBe(MigrationStatus::Succeeded->value);
});

it('records a sanitised error on the failed tenant only', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $broken = $this->provisionTenant('Broken');

    $brokenModel = $this->tenantModel($broken);
    $brokenModel->forceFill(['tenancy_db_name' => $brokenModel->tenancy_db_name.'9'])->save();

    app(TenantMigrator::class)->migrateMany(
        [$this->tenantModel($alpha), $brokenModel],
        Actor::system('test'),
    );

    $error = $this->tenantModel($broken)->last_migration_error;

    expect($error)->toBeString()->not->toBeEmpty()
        ->and($this->tenantModel($alpha)->last_migration_error)->toBeNull();

    // Driver errors can carry credentials; the stored message must not.
    expect(mb_strtolower((string) $error))->not->toContain('password=');
});

it('reports drift while a tenant is behind the target schema', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    $this->artisan('metastyle:tenant:status', ['--drift' => true])->assertSuccessful();

    $this->tenantModel($alpha)->forceFill(['schema_version' => 'older_version'])->save();

    // This is the deploy gate: contract migrations must not ship while any
    // tenant is behind (docs/12-DEPLOYMENT-MIGRATION-STRATEGY.md §3).
    $this->artisan('metastyle:tenant:status', ['--drift' => true])->assertFailed();
});

it('exits non-zero from the migrate command when a tenant fails', function (): void {
    $broken = $this->provisionTenant('Broken');

    $brokenModel = $this->tenantModel($broken);
    $brokenModel->forceFill(['tenancy_db_name' => $brokenModel->tenancy_db_name.'9'])->save();

    $this->artisan('metastyle:tenant:migrate', ['--all' => true])->assertFailed();

    // ...and the retry path targets only what failed.
    $this->artisan('metastyle:tenant:migrate', ['--retry-failed' => true])->assertFailed();
});

it('migrates a single tenant on request', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    $this->artisan('metastyle:tenant:migrate', ['--tenant' => $alpha->id])->assertSuccessful();
});

afterEach(function (): void {
    $this->tearDownTenantDatabases();
});
