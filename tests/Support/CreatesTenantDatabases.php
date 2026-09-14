<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\Tenant;
use App\Kernel\Tenancy\TenantProvisioningService;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Test harness for the tenant plane.
 *
 * Two levels, both against real databases:
 *
 *   provisionTenant()      the full pipeline — control record, database,
 *                          migrations, seed. Use this for anything about
 *                          tenancy behaviour.
 *   createTenantDatabase() a bare database with no tenant record. Use this
 *                          only for low-level connection tests.
 *
 * There is deliberately NO ambient default tenant. A test that forgets to bind
 * one hits the fail-closed guard, exactly as production would
 * (docs/11-TESTING-STRATEGY.md §3).
 */
trait CreatesTenantDatabases
{
    /** @var list<string> */
    private array $createdTenantDatabases = [];

    /**
     * Provisions a real tenant through the real service.
     *
     * Tests exercise the production pipeline rather than a fixture shortcut,
     * so a regression in provisioning fails the isolation suite too.
     *
     * @param  list<string>  $domains
     */
    protected function provisionTenant(string $name, array $domains = []): Tenant
    {
        $tenant = app(TenantProvisioningService::class)->provision($name, $domains);

        $this->createdTenantDatabases[] = $tenant->databaseName;

        return $tenant;
    }

    protected function tenantModel(Tenant $tenant): TenantModel
    {
        return TenantModel::query()->findOrFail($tenant->id);
    }

    /**
     * Runs $callback inside $tenant's context, through the real
     * TenantContext — including its exception-safe teardown.
     *
     * @template TReturn
     *
     * @param  Closure(Tenant): TReturn  $callback
     * @return TReturn
     */
    protected function asTenant(Tenant $tenant, Closure $callback): mixed
    {
        return app(StanclTenantContext::class)->runForModel(
            $this->tenantModel($tenant),
            $callback,
        );
    }

    /**
     * A bare database with no control-plane record behind it.
     */
    protected function createTenantDatabase(string $suffix): string
    {
        $database = TestDatabaseManager::PREFIX.'raw_'.$suffix;

        TestDatabaseManager::create($database);

        $this->createdTenantDatabases[] = $database;

        return $database;
    }

    /**
     * Binds the tenant connection directly, bypassing tenancy.
     *
     * Only for low-level connection tests. Anything about tenancy behaviour
     * should use asTenant() so it exercises the real bootstrappers.
     *
     * @template TReturn
     *
     * @param  Closure(Connection): TReturn  $callback
     * @return TReturn
     */
    protected function withTenantDatabase(string $database, Closure $callback): mixed
    {
        $connections = config('database.connections');
        $previous = $connections['tenant'] ?? null;

        config(['database.connections.tenant' => array_merge(
            (array) config('database.connections.tenant_template'),
            ['database' => $database],
        )]);
        DB::purge('tenant');

        try {
            return $callback(DB::connection('tenant'));
        } finally {
            DB::purge('tenant');
            $this->unbindTenantConnection($previous);
        }
    }

    /**
     * Restores the "no tenant bound" state — the connection must not merely be
     * emptied, it must be absent, or a later query would find a half-built
     * config instead of failing.
     */
    private function unbindTenantConnection(mixed $previous): void
    {
        $connections = config('database.connections');

        if ($previous === null) {
            unset($connections['tenant']);
        } else {
            $connections['tenant'] = $previous;
        }

        config(['database.connections' => $connections]);
    }

    protected function tearDownTenantDatabases(): void
    {
        DB::purge('tenant');
        $this->unbindTenantConnection(null);

        foreach (array_unique($this->createdTenantDatabases) as $database) {
            TestDatabaseManager::drop($database);
        }

        $this->createdTenantDatabases = [];
    }
}
