<?php

declare(strict_types=1);

namespace App\Providers;

use App\Kernel\Diagnostics\ProductionReadiness;
use App\Kernel\Http\MiddlewareOrderGuard;
use App\Kernel\Identity\Models\PersonalAccessToken;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\Http\Middleware\SetLocale;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Observability\RequestId;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Kernel\Tenancy\Http\Middleware\ResolveTenant;
use App\Kernel\Tenancy\TenantConnectionGuard;
use App\Modules\Booking\Application\MetaStyleBookingEngine;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Queue\Application\SyncTicketsWithJourney;
use App\Modules\ServiceJourney\Domain\Events\JourneyAborted;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageSettled;
use App\Modules\ServiceJourney\Domain\Events\JourneyStageStarted;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

final class AppServiceProvider extends ServiceProvider
{
    /**
     * The only database connections Meta Style supports.
     *
     * `tenant` is absent on purpose — it is created at runtime by the tenancy
     * layer when a tenant is initialised, and removed when tenancy ends
     * (docs/02-TENANCY.md §4). Pruning happens during register(), long before
     * any tenant is bootstrapped, so the runtime connection is unaffected.
     *
     * @var list<string>
     */
    private const SUPPORTED_CONNECTIONS = ['control', 'tenant_template'];

    public function register(): void
    {
        $this->restrictDatabaseConnections();

        // Scoped so each request, queued job, and command gets its own
        // correlation id, and long-running workers do not leak one between
        // jobs (docs/10-API-FOUNDATION.md §3).
        $this->app->scoped(RequestId::class, fn (): RequestId => new RequestId);

        $this->app->singleton(TenantConnectionGuard::class);

        // ONE per request, not one per injection. `TenantLocales` caches the
        // center's enabled languages, and with a transient binding the locale
        // middleware, a presenter and a form would each hold their own copy —
        // so changing the enabled set would update one of them and leave the
        // others answering from a stale cache within the same request.
        $this->app->scoped(TenantLocales::class);

        // Same reasoning: the availability engine, a booking Action and a
        // settings form must all see one center's booking settings within a
        // request, or saving in one leaves the others answering from a stale
        // cache.
        $this->app->scoped(BookingSettings::class);

        // Channels depend on the CONTRACT. The concrete engine is an
        // implementation detail, and binding it here is what lets a WhatsApp
        // adapter type-hint the boundary rather than the class
        // (docs/04-MODULE-BOUNDARIES.md §4.1).
        $this->app->bind(BookingEngine::class, MetaStyleBookingEngine::class);

        /*
         * The availability collaborators are deliberately NOT scoped or
         * singletons. They each cache per instance — a branch's opening hours,
         * an employee eligibility set, the resources of a type — and that cache
         * is only safe for as long as one computation runs.
         *
         * Registering them scoped was tried and reverted: a scoped instance
         * outlives an HTTP request in a test process and under Octane, so a
         * booking made after a room was archived was still offered the room
         * from a cache built before it. A transient binding makes the cache
         * exactly as long-lived as the work it belongs to (ADR-048).
         */
    }

    /**
     * Laravel merges the framework's base config over the application's, so
     * deleting `sqlite`, `pgsql` and `sqlsrv` from config/database.php does not
     * actually remove them — they reappear from the framework defaults.
     *
     * That would leave SQLite reachable via `DB_CONNECTION=sqlite` or
     * `DB::connection('sqlite')`, which is exactly the failure ADR-016 exists
     * to prevent: a suite that is green on SQLite and broken on MySQL. Pruning
     * them here turns that into an immediate "connection not configured" error.
     */
    private function restrictDatabaseConnections(): void
    {
        $config = $this->app->make('config');

        /** @var array<string, mixed> $connections */
        $connections = $config->get('database.connections', []);

        $config->set('database.connections', array_intersect_key(
            $connections,
            array_flip(self::SUPPORTED_CONNECTIONS),
        ));
    }

    public function boot(): void
    {
        // Mass assignment must be declared explicitly, never inferred
        // (docs/08-AUDIT-SECURITY.md §12).
        Model::unguard(false);

        // Accessing a relation that was not loaded is a bug, not a feature —
        // it is how N+1 queries reach production. Local and testing only, so
        // production degrades rather than fails.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventAccessingMissingAttributes(! $this->app->isProduction());

        // Sanctum must resolve tokens on the TENANT connection. Without this
        // it would query the default connection — the control plane — where no
        // token rows exist, and every API request would fail confusingly
        // (docs/DECISIONS.md ADR-027).
        /*
         * Queue listens to Journey, synchronously.
         *
         * The events are dispatched inside the Journey Actions' transactions,
         * and this listener is a plain class — no `ShouldQueue`, no broadcast —
         * so a ticket and the stage it belongs to commit together or not at
         * all. A queued listener would leave the board and the television
         * showing a stage `in_service` beside a ticket still `called`
         * (docs/17-QUEUE.md §12, Phase 8 correction 4).
         *
         * Registered HERE rather than in Journey, because Journey must not know
         * Queue exists — an architecture test enforces the direction.
         */
        Event::listen(JourneyStageStarted::class, [SyncTicketsWithJourney::class, 'handleStageStarted']);
        Event::listen(JourneyStageSettled::class, [SyncTicketsWithJourney::class, 'handleStageSettled']);
        Event::listen(JourneyAborted::class, [SyncTicketsWithJourney::class, 'handleJourneyAborted']);

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // A token remains cryptographically valid after its owner is
        // deactivated. Authorization must not stop at "this token exists":
        // access is withdrawn the moment the account is, not whenever the
        // token happens to expire.
        //
        // This callback only ever NARROWS validity. Sanctum has already checked
        // that the token's owner matches the guard's provider model, which is
        // what keeps staff and customer tokens from crossing over; returning
        // true here could not undo that even if it wanted to.
        Sanctum::authenticateAccessTokensUsing(
            static function (PersonalAccessToken $accessToken, bool $isValid): bool {
                if (! $isValid) {
                    return false;
                }

                $owner = $accessToken->tokenable;

                return match (true) {
                    $owner instanceof User => $owner->is_active,
                    $owner instanceof CustomerAccount => $owner->canAuthenticate(),
                    // An unknown owner type is a token this application did not
                    // mean to issue. Refuse it.
                    default => false,
                };
            }
        );

        $this->rateLimiters();

        $this->persistLivewireMiddleware();

        // Fatal, on purpose: a pipeline that authenticates before it resolves
        // the tenant is not a degraded mode (docs/06-AUTH-ROLES-PERMISSIONS.md §2.5).
        $guard = $this->app->make(MiddlewareOrderGuard::class);

        $guard->assert($this->app->make(Router::class));
        $guard->assertLivewirePersists(Livewire::getPersistentMiddleware());

        $this->warnAboutProductionConfiguration();
    }

    /**
     * Carries tenant resolution and locale onto Livewire's update endpoint.
     *
     * A Livewire component action does NOT post to the route that rendered it.
     * It posts to `/livewire/update`, which carries only the `web` group;
     * Livewire then replays a filtered subset of the original route's
     * middleware — the ones registered here. Without this, `auth:web` runs on
     * that endpoint with no tenant bound, and every action in the center area
     * dies in `TenantConnectionGuard`.
     *
     * Both resolvers are registered. Only the one actually on the original
     * route is ever gathered, so the public menu's path-based resolver cannot
     * leak onto an authenticated route (ADR-036); and `SetLocale` comes too, or
     * a Livewire update would re-render half a page in the platform fallback
     * language.
     */
    private function persistLivewireMiddleware(): void
    {
        Livewire::addPersistentMiddleware([
            ResolveTenant::class,
            ResolvePublicTenant::class,
            SetLocale::class,
        ]);
    }

    /**
     * Makes a misconfigured production deployment noisy instead of silent.
     *
     * Logged rather than thrown: unlike middleware ordering, these are
     * conditions a running site can survive, and refusing to boot would turn a
     * degraded deployment into an outage. But they must not pass unremarked —
     * a rate limiter on a per-process store looks identical to a working one
     * (docs/08-AUDIT-SECURITY.md §13). `metastyle:doctor` is the version of
     * this that runs before the deployment goes live.
     */
    private function warnAboutProductionConfiguration(): void
    {
        if (! $this->app->isProduction()) {
            return;
        }

        foreach ($this->app->make(ProductionReadiness::class)->failures() as $failure) {
            Log::critical('Production readiness check failed: '.$failure->name, [
                'detail' => $failure->detail,
                'remedy' => $failure->remedy,
            ]);
        }
    }

    /**
     * Rate limits for the endpoints that matter.
     *
     * Two rules shape these (docs/08-AUDIT-SECURITY.md §13):
     *
     *  - Unauthenticated endpoints that CREATE things — registration — and
     *    endpoints that accept credentials are the abusable ones, so they are
     *    tight and keyed by IP.
     *  - Anything inside tenant context is keyed by TENANT as well as by user,
     *    so one center cannot exhaust another's allowance. A shared bucket
     *    would let a single busy center throttle the whole platform.
     */
    private function rateLimiters(): void
    {
        // Creating a tenant and a database is expensive; a handful an hour from
        // one address is generous for a real signup and hostile to a script.
        RateLimiter::for('registration', fn (Request $request) => [
            Limit::perHour(10)->by($request->ip()),
            Limit::perDay(30)->by($request->ip()),
        ]);

        RateLimiter::for('registration-status', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));

        // Keyed by IP and by the center being targeted, so brute force against
        // one center cannot be spread across addresses without also being
        // visible per center.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perMinute(20)->by('center:'.(string) $request->input('center_key')),
        ]);

        // The public menu is a read-only page a customer opens from a QR code,
        // so the limit is generous — but it is keyed by TENANT as well as by
        // address, so hammering one center's menu cannot exhaust another's.
        RateLimiter::for('public-menu', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return Limit::perMinute(120)->by($tenantId.'|'.$request->ip());
        });

        /*
         * Public booking. Tighter than the menu it sits behind, because this
         * one WRITES: it creates customers and appointments for an
         * unauthenticated caller. Keyed by tenant and address together, so
         * hammering one center cannot exhaust another's allowance
         * (docs/08-AUDIT-SECURITY.md §13).
         */
        RateLimiter::for('public-booking', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return [
                Limit::perMinute(10)->by($tenantId.'|'.$request->ip()),
                Limit::perHour(40)->by($tenantId.'|'.$request->ip()),
            ];
        });

        /*
         * The public queue display.
         *
         * A television polls this every three seconds and never stops, so the
         * ceiling has to accommodate a legitimate screen left on all day —
         * 20 a minute is one poll every three seconds with room for a reload —
         * while still bounding somebody scraping a center's calls.
         *
         * Keyed by DISPLAY as well as tenant and address: a center with four
         * screens behind one office NAT must not have them throttle each other
         * (docs/17-QUEUE.md §26).
         */
        RateLimiter::for('public-display', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';
            $display = (string) $request->route('display');

            return Limit::perMinute(30)->by($tenantId.'|'.$display.'|'.$request->ip());
        });

        /*
         * A customer's digital invoice.
         *
         * Opened a handful of times by a person, never polled — so a low
         * ceiling per address costs a real customer nothing, and makes
         * guessing 256-bit tokens even more pointless than the entropy already
         * does (docs/18-SALES.md §28).
         */
        RateLimiter::for('public-invoice', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';

            return Limit::perMinute(20)->by($tenantId.'|'.$request->ip());
        });

        /*
         * Customer sign-in and registration.
         *
         * A COARSE OUTER GUARD ONLY. The limit that actually protects an
         * account lives in `AuthenticateCustomer` via `LoginThrottle`, keyed by
         * the identifier being attacked as well as the address — a route
         * limiter cannot do that, and does not run for the Livewire sign-in at
         * all (Phase 6 §1).
         */
        RateLimiter::for('customer-login', fn (Request $request) => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perMinute(30)->by('center:'.(string) $request->input('center_key')),
        ]);

        RateLimiter::for('tenant-api', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'none';
            $userId = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute(120)->by($tenantId.'|'.$userId);
        });
    }
}
