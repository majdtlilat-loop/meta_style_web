<?php

declare(strict_types=1);

namespace App\Providers;

use App\Kernel\Reporting\ReportConnection;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Contracts\TenantResolver;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Kernel\Tenancy\PlatformHosts;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;

/**
 * Wires the Meta Style tenancy abstractions to their stancl/tenancy
 * implementations, and activates the package's bootstrappers.
 *
 * This provider and App\Kernel\Tenancy\Infrastructure are the seam from
 * ADR-018: everything else depends on the contracts bound here and never
 * learns which tenancy package is underneath. An architecture test enforces
 * that boundary.
 *
 * Note what this provider does NOT register: the package's automatic tenant
 * lifecycle pipeline (TenantCreated → CreateDatabase → MigrateDatabase). Meta
 * Style owns provisioning as an explicit, resumable, status-tracked service,
 * because we need failure isolation, retryability and per-tenant status that
 * an event chain does not give us (docs/02-TENANCY.md §8.2).
 */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $hosts = $this->app->make(PlatformHosts::class);
        $this->app->make('config')->set('tenancy.central_domains', [
            $hosts->corporateHost(),
            $hosts->superAdminHost(),
        ]);

        $this->app->singleton(StanclTenantContext::class);
        $this->app->singleton(StanclTenantResolver::class);

        $this->app->bind(TenantContext::class, StanclTenantContext::class);
        $this->app->bind(TenantResolver::class, StanclTenantResolver::class);

        $this->bootstrapperStaticHooks();
    }

    public function boot(): void
    {
        // Without these two listeners the bootstrappers never run: tenancy
        // would "initialize" while the database connection, cache tags and
        // storage paths all stayed central. Silent, and catastrophic.
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);
        Event::listen(TenancyEnded::class, static function (): void {
            app(ReportConnection::class)->forget();
        });
    }

    /**
     * Some bootstrappers must register hooks before tenancy is ever
     * initialised — the queue bootstrapper, for instance, has to attach its
     * job listener at provider time so that a job dequeued later restores the
     * tenant it was dispatched under.
     */
    private function bootstrapperStaticHooks(): void
    {
        /** @var list<class-string> $bootstrappers */
        $bootstrappers = $this->app->make('config')->get('tenancy.bootstrappers', []);

        foreach ($bootstrappers as $bootstrapper) {
            if (method_exists($bootstrapper, '__constructStatic')) {
                $bootstrapper::__constructStatic($this->app);
            }
        }
    }
}
