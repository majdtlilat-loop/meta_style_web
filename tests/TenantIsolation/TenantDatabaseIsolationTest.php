<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Tenant;
use App\Kernel\Tenancy\TenantConnectionGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Database isolation
|--------------------------------------------------------------------------
|
| Isolation cases 1, 2, 4, 5 (docs/11-TESTING-STRATEGY.md §4).
|
| Two real, fully provisioned tenants. Everything here goes through the real
| TenantContext, so a regression in the tenancy adapter fails these tests
| rather than hiding behind a fixture.
|
*/

function writeSetting(string $key, string $value): void
{
    DB::connection(TenantConnectionGuard::CONNECTION)->table('settings')->updateOrInsert(
        ['key' => $key],
        ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()],
    );
}

function readSetting(string $key): ?string
{
    $row = DB::connection(TenantConnectionGuard::CONNECTION)->table('settings')->where('key', $key)->value('value');

    return $row === null ? null : (string) json_decode((string) $row, false, 512, JSON_THROW_ON_ERROR);
}

it('returns only the querying tenant\'s data', function (): void {
    $alpha = $this->provisionTenant('Barbershop Alpha');
    $beta = $this->provisionTenant('Laser Center Beta');

    $this->asTenant($alpha, fn () => writeSetting('center_label', 'ALPHA'));
    $this->asTenant($beta, fn () => writeSetting('center_label', 'BETA'));

    expect($this->asTenant($alpha, fn (): ?string => readSetting('center_label')))->toBe('ALPHA')
        ->and($this->asTenant($beta, fn (): ?string => readSetting('center_label')))->toBe('BETA');
});

it('gives each tenant its own database', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    expect($alpha->databaseName)->not->toBe($beta->databaseName)
        ->and($alpha->databaseName)->toEndWith('000001')
        ->and($beta->databaseName)->toEndWith('000002');

    // Data written by one is not merely filtered out of the other's queries —
    // it is not in the other's database at all.
    $this->asTenant($alpha, fn () => writeSetting('only_in_alpha', 'yes'));

    expect($this->asTenant($beta, fn (): ?string => readSetting('only_in_alpha')))->toBeNull();
});

it('does not leak tenant A into tenant B when A\'s context ends', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $this->asTenant($alpha, fn () => writeSetting('marker', 'from-alpha'));

    // Immediately after A's context ends, B must see a clean connection.
    $seenFromBeta = $this->asTenant($beta, fn (): ?string => readSetting('marker'));

    expect($seenFromBeta)->toBeNull();
});

it('resets the connection when switching between tenants repeatedly', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $this->asTenant($alpha, fn () => writeSetting('who', 'alpha'));
    $this->asTenant($beta, fn () => writeSetting('who', 'beta'));

    $observed = [];

    // Sequential switching is where a stale connection shows up: the second
    // and later switches are the ones that silently reuse the first tenant.
    foreach ([$alpha, $beta, $alpha, $beta, $alpha] as $tenant) {
        $observed[] = $this->asTenant($tenant, fn (): ?string => readSetting('who'));
    }

    expect($observed)->toBe(['alpha', 'beta', 'alpha', 'beta', 'alpha']);
});

it('returns to the control plane after tenant context ends', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    $this->asTenant($alpha, function (): void {
        expect(config('database.default'))->toBe('tenant');
    });

    expect(config('database.default'))->toBe('control')
        ->and(app(TenantContext::class)->isBound())->toBeFalse()
        ->and(app(TenantConnectionGuard::class)->isInitialized())->toBeFalse();
});

it('restores the previous tenant when contexts are nested', function (): void {
    $alpha = $this->provisionTenant('Alpha');
    $beta = $this->provisionTenant('Beta');

    $this->asTenant($alpha, function (Tenant $outer): void {
        $this->asTenant($this->provisionTenant('Gamma'), fn () => null);

        // The inner context must hand control back to Alpha, not to nothing.
        expect(app(TenantContext::class)->id())->toBe($outer->id);
    });

    expect(app(TenantContext::class)->isBound())->toBeFalse();
    unset($beta);
});

it('never writes tenant data into the control database', function (): void {
    $alpha = $this->provisionTenant('Alpha');

    $this->asTenant($alpha, fn () => writeSetting('control_leak_check', 'nope'));

    expect(Schema::connection('control')->hasTable('settings'))->toBeFalse();
});

afterEach(function (): void {
    $this->tearDownTenantDatabases();
});
