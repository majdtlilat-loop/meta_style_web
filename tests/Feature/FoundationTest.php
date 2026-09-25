<?php

declare(strict_types=1);

use App\Livewire\SystemStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Phase 1 foundation
|--------------------------------------------------------------------------
|
| Proves the pieces Phase 2 will build on are actually wired: Livewire, the
| RTL-aware layout, the two database connections, and the control migrator.
|
*/

it('renders the Blade + Livewire foundation', function (): void {
    Livewire::test(SystemStatus::class)
        ->assertOk()
        ->assertSee('Meta Style')
        ->assertSee('Show more')
        ->call('toggle')
        ->assertSet('expanded', true)
        ->assertSee('Show less');
});

it('sets document direction from the language registry', function (): void {
    app()->setLocale('en');
    $this->get('/')->assertSee('dir="ltr"', escape: false);

    app()->setLocale('ar');
    $this->get('/')->assertSee('dir="rtl"', escape: false);

    app()->setLocale('ckb');
    $this->get('/')->assertSee('dir="rtl"', escape: false);
});

it('defaults to the control connection and never to a tenant', function (): void {
    expect(config('database.default'))->toBe('control')
        // The runtime `tenant` connection exists only while a tenant is
        // initialised (docs/02-TENANCY.md §4).
        ->and(config('database.connections'))->not->toHaveKey('tenant');
});

it('leaves no unsupported database connection reachable', function (): void {
    // Laravel merges its base config over ours, so simply omitting these from
    // config/database.php is not enough — see AppServiceProvider.
    //
    // An EXACT set, mirrored by `AppServiceProvider::SUPPORTED_CONNECTIONS`
    // and `DatabasePortabilityTest`. `reporting_template` is Phase 14's read
    // replica template (ADR-074): admitted here only because, like the tenant
    // template, it carries no database name — pinned by the test below.
    expect(array_keys(config('database.connections')))
        ->toEqualCanonicalizing(['control', 'tenant_template', 'reporting_template']);

    DB::connection('sqlite');
})->throws(InvalidArgumentException::class);

it('keeps both connection templates unusable as connections', function (): void {
    // A template supplies driver, host and credentials; it is copied, never
    // connected to, and must never carry a database name. The runtime
    // `tenant` and `tenant_reporting` connections get theirs from the bound
    // tenant, never from here (docs/28-REPORTS.md §2).
    expect(config('database.connections.tenant_template.database'))->toBeNull()
        ->and(config('database.connections.reporting_template.database'))->toBeNull();
});

it('records failed queue jobs in the control database', function (): void {
    // Not per tenant — one place for operators to look (ADR-015).
    expect(config('queue.failed.database'))->toBe('control');
});

it('migrates the control plane with the metastyle command', function (): void {
    // Idempotent: the suite already migrated the control plane, so this proves
    // re-running is safe as well as that the command targets the right
    // connection and path.
    $this->artisan('metastyle:control:migrate', ['--force' => true])
        ->assertSuccessful();

    $schema = Schema::connection('control');

    expect($schema->hasTable('migrations'))->toBeTrue()
        ->and($schema->hasTable('tenants'))->toBeTrue()
        ->and($schema->hasTable('domains'))->toBeTrue()
        ->and($schema->hasTable('tenant_operations'))->toBeTrue()
        ->and($schema->hasTable('platform_audit_logs'))->toBeTrue()
        ->and($schema->hasTable('failed_jobs'))->toBeTrue()
        ->and($schema->hasTable('jobs'))->toBeTrue()
        // Tenant tables never appear in the control database.
        ->and($schema->hasTable('settings'))->toBeFalse()
        ->and($schema->hasTable('audit_logs'))->toBeFalse();
});

it('keeps the two migration sets separate', function (): void {
    $control = glob(database_path('migrations/control/*.php')) ?: [];
    $tenant = glob(database_path('migrations/tenant/*.php')) ?: [];

    expect(is_dir(database_path('migrations/control')))->toBeTrue()
        ->and(is_dir(database_path('migrations/tenant')))->toBeTrue()
        ->and($control)->not->toBeEmpty()
        ->and($tenant)->not->toBeEmpty();
});
