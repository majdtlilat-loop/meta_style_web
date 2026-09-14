<?php

use App\Kernel\Authorization\Console\SyncSystemRolesCommand;
use App\Kernel\Database\Console\MigrateControlCommand;
use App\Kernel\Diagnostics\Console\DoctorCommand;
use App\Kernel\Entitlements\Http\Middleware\RequireEntitlement;
use App\Kernel\Http\ApiExceptionRenderer;
use App\Kernel\Http\Console\SweepIdempotencyKeysCommand;
use App\Kernel\Localization\Http\Middleware\SetLocale;
use App\Kernel\Observability\Middleware\AssignRequestId;
use App\Kernel\Tenancy\Console\MigrateTenantsCommand;
use App\Kernel\Tenancy\Console\ProvisionTenantCommand;
use App\Kernel\Tenancy\Console\TenantStatusCommand;
use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Kernel\Tenancy\Http\Middleware\ResolveTenant;
use App\Modules\Onboarding\Console\RetryRegistrationCommand;
use App\Modules\Onboarding\Console\SweepRegistrationsCommand;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        MigrateControlCommand::class,
        DoctorCommand::class,
        ProvisionTenantCommand::class,
        MigrateTenantsCommand::class,
        TenantStatusCommand::class,
        SyncSystemRolesCommand::class,
        RetryRegistrationCommand::class,
        SweepRegistrationsCommand::class,
        SweepIdempotencyKeysCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        // Correlation id runs first so every later failure is attributable.
        $middleware->prepend(AssignRequestId::class);

        // Applied per route group, never globally: platform routes must run
        // with no tenant bound (docs/01-ARCHITECTURE.md §2).
        // Tenant resolution MUST run before authentication. Laravel's default
        // priority list puts authentication ahead of custom middleware, so
        // without this the token lookup would run with no tenant bound — and
        // tokens live in the tenant database. Listing them in the right order
        // in the route array is NOT enough: priority wins.
        //
        // The anchor is the AuthenticatesRequests *interface*, which is what
        // the priority list actually contains; anchoring on the concrete
        // Authenticate class silently appends to the end instead.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: ResolveTenant::class,
        );

        // SetLocale must run AFTER tenant resolution: steps 1-4 of the locale
        // chain all need to know which center this is, and with no tenant bound
        // it can only fall back to the platform default
        // (docs/07-LOCALIZATION.md §5).
        //
        // Inserted in this order on purpose. Adding SetLocale to the priority
        // list while the resolvers were absent from it would sort SetLocale
        // BEFORE them — Laravel puts every unprioritised middleware after every
        // prioritised one — which is the same silent misordering ADR-027
        // documents. Both resolvers are therefore anchored ahead of it.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: SetLocale::class,
        );

        $middleware->prependToPriorityList(
            before: SetLocale::class,
            prepend: ResolvePublicTenant::class,
        );

        $middleware->alias([
            'tenant' => ResolveTenant::class,

            // Guest-facing menu only. Deliberately a SEPARATE middleware from
            // `tenant`: it accepts a public key from the URL path, which
            // nothing authenticated may ever do (ADR-036). An architecture test
            // asserts it never shares a route with an auth middleware.
            'public.tenant' => ResolvePublicTenant::class,

            'locale' => SetLocale::class,
            // Coarse route-level gate. Actions still call
            // Entitlements::ensure() themselves — non-HTTP callers never reach
            // middleware (docs/05-ENTITLEMENTS.md §6).
            'entitlement' => RequireEntitlement::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request)
        );
    })->create();
