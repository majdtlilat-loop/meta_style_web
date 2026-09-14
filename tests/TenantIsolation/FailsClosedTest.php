<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Exceptions\TenantConnectionNotInitialized;
use App\Kernel\Tenancy\Exceptions\TenantNotResolved;
use App\Kernel\Tenancy\TenantConnectionGuard;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Fail-closed tenant protection
|--------------------------------------------------------------------------
|
| Isolation case 3 (docs/11-TESTING-STRATEGY.md §4), and the guarantee in
| docs/02-TENANCY.md §4.
|
| Tenant data access without an initialised tenant must FAIL. It must never
| fall back to the control database, never inherit the previous tenant, and
| never bind an arbitrary one. This is the worst failure this architecture can
| have, so it is tested from more than one angle.
|
*/

it('reports no tenant bound by default', function (): void {
    expect(app(TenantConnectionGuard::class)->isInitialized())->toBeFalse()
        ->and(app(TenantConnectionGuard::class)->boundDatabase())->toBeNull()
        ->and(app(TenantContext::class)->isBound())->toBeFalse()
        ->and(app(TenantContext::class)->tenant())->toBeNull();
});

it('has no tenant connection configured at all while unbound', function (): void {
    // Stronger than an empty database name: the connection does not exist, so
    // there is nothing for a stray query to resolve against.
    expect(config('database.connections'))->not->toHaveKey('tenant');
});

it('throws a named exception when tenant data is accessed with no tenant', function (): void {
    app(TenantConnectionGuard::class)->ensureInitialized('appointments');
})->throws(TenantConnectionNotInitialized::class);

it('throws when the tenant context is required and absent', function (): void {
    app(TenantContext::class)->require();
})->throws(TenantNotResolved::class);

it('names the context in the exception message', function (): void {
    try {
        app(TenantConnectionGuard::class)->ensureInitialized('appointments');
    } catch (TenantConnectionNotInitialized $e) {
        expect($e->getMessage())
            ->toContain('appointments')
            ->toContain('No tenant is initialised');

        return;
    }

    $this->fail('Expected TenantConnectionNotInitialized.');
});

it('fails a tenant model query rather than reading the control database', function (): void {
    TenantAuditLog::query()->count();
})->throws(TenantConnectionNotInitialized::class);

it('never returns control-database rows through an uninitialised tenant connection', function (): void {
    Schema::connection('control')->dropIfExists('fail_closed_probe');
    Schema::connection('control')->create('fail_closed_probe', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });

    DB::connection('control')->table('fail_closed_probe')->insert(['name' => 'control-only-row']);

    $returned = [];
    $threw = false;

    try {
        $returned = DB::connection('tenant')->table('fail_closed_probe')->pluck('name')->all();
    } catch (Throwable) {
        $threw = true;
    }

    // The guarantee has two halves: it must error, and above all it must not
    // hand back another database's data.
    expect($returned)->not->toContain('control-only-row')
        ->and($threw)->toBeTrue();

    Schema::connection('control')->dropIfExists('fail_closed_probe');
});

it('unbinds the tenant even when the callback throws', function (): void {
    $tenant = $this->provisionTenant('Alpha');

    try {
        $this->asTenant($tenant, function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    // stancl's own Tenant::run() has no try/finally, so without the wrapper's
    // exception-safe teardown the next operation would silently inherit this
    // tenant's connection, cache tag and storage prefix.
    expect(app(TenantContext::class)->isBound())->toBeFalse()
        ->and(app(TenantConnectionGuard::class)->isInitialized())->toBeFalse()
        ->and(config('database.default'))->toBe('control');
});

it('recognises a tenant while bound and releases it afterwards', function (): void {
    $tenant = $this->provisionTenant('Alpha');

    $this->asTenant($tenant, function () use ($tenant): void {
        expect(app(TenantConnectionGuard::class)->isInitialized())->toBeTrue()
            ->and(app(TenantConnectionGuard::class)->boundDatabase())->toBe($tenant->databaseName)
            ->and(app(TenantConnectionGuard::class)->assertBoundDatabaseIsValid())->toBe($tenant->databaseName);
    });

    expect(app(TenantConnectionGuard::class)->isInitialized())->toBeFalse();
});

afterEach(function (): void {
    $this->tearDownTenantDatabases();
});
