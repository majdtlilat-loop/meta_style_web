<?php

declare(strict_types=1);

use App\Kernel\Http\MiddlewareOrderGuard;
use App\Kernel\Http\MiddlewareOrderViolation;
use App\Kernel\Identity\TenantApiToken;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Http\Middleware\ResolveLivewireTenant;
use App\Kernel\Tenancy\Http\Middleware\ResolveTenant;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Middleware ordering in the real pipeline
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-027 · docs/06-AUTH-ROLES-PERMISSIONS.md §2.5.
|
| Sanctum tokens and staff accounts live in the TENANT database. So the pipeline
| has exactly one valid order:
|
|     resolve tenant  ->  initialise tenant  ->  look up credential  ->  authorize
|
| Getting this wrong does not produce a broken page, which is why it needs tests
| rather than a review. It WAS wrong once: the priority list holds the
| `AuthenticatesRequests` INTERFACE, so anchoring the insert on the concrete
| `Authenticate` class matched nothing and silently appended `ResolveTenant` to
| the end of the list. The route arrays said ['tenant', 'auth:sanctum'] in the
| right order the whole time; priority overrode them, and nothing failed
| visibly — Sanctum simply hit the fail-closed connection guard instead.
|
| These tests observe the running pipeline rather than reading the source,
| because the source looked correct while the behaviour was not.
|
*/

/**
 * The connections a request queried for a given table, in order.
 *
 * @return list<string>
 */
function connectionsQueryingTable(string $table, Closure $callback): array
{
    $connections = [];

    Event::listen(function (QueryExecuted $event) use ($table, &$connections): void {
        if (str_contains($event->sql, $table)) {
            $connections[] = $event->connectionName;
        }
    });

    $callback();

    return $connections;
}

/*
|--------------------------------------------------------------------------
| The API pipeline
|--------------------------------------------------------------------------
*/

it('has the expected tenant bound at the moment the credential is looked up', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');

    $context = app(TenantContext::class);
    $seen = null;

    // Sanctum authenticates through a request guard, which fires no
    // Authenticated event — so the observable moment is the query itself. It is
    // also the better one: this is exactly the instant that would read the
    // wrong database if resolution had not run first.
    Event::listen(function (QueryExecuted $event) use ($context, &$seen): void {
        if ($seen === null && str_contains($event->sql, 'personal_access_tokens')) {
            $seen = [
                'bound' => $context->isBound(),
                'tenant' => $context->id(),
                'connection' => $event->connectionName,
            ];
        }
    });

    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($alpha['tenant'])))
        ->getJson('/api/v1/tenant/me')
        ->assertOk();

    expect($seen)->not->toBeNull()
        ->and($seen['bound'])->toBeTrue()
        ->and($seen['tenant'])->toBe($alpha['tenant']->id)
        ->and($seen['connection'])->toBe('tenant');
});

it('looks the token up on the tenant connection, never the control plane', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $token = $this->apiTokenFor($alpha['tenant']);

    $connections = connectionsQueryingTable('personal_access_tokens', function () use ($token): void {
        $this->withHeaders($this->tokenHeaders($token))
            ->getJson('/api/v1/tenant/me')
            ->assertOk();
    });

    // The lookup happened, and every one of them was inside tenant context. A
    // query on `control` here would mean the token was searched for before the
    // tenant connection existed.
    expect($connections)->not->toBeEmpty()
        ->and(array_unique($connections))->toBe(['tenant']);
});

it('cannot authenticate at all when tenant resolution has not run', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $token = $this->apiTokenFor($alpha['tenant']);

    // A route deliberately missing the `tenant` middleware: the misordering
    // this suite exists to prevent, expressed as a route.
    Route::middleware(['api', 'auth:sanctum'])
        ->get('/test/unresolved', fn () => response()->json(['user' => Auth::id()]));

    $response = $this->withHeaders($this->tokenHeaders($token))->getJson('/test/unresolved');

    // Fails closed. The token is genuine and its owner is real; there is simply
    // no tenant database to look it up in, and the platform refuses to guess
    // rather than falling back to the control plane (docs/02-TENANCY.md §4).
    expect($response->status())->not->toBe(200)
        ->and(Auth::hasUser())->toBeFalse();
});

it('clears the tenant context once the request has finished', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');

    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($alpha['tenant'])))
        ->getJson('/api/v1/tenant/me')
        ->assertOk();

    $context = app(TenantContext::class);

    // Whatever runs next in this process — a queued job, a scheduled command,
    // another request in a long-lived worker — must not inherit Alpha.
    expect($context->isBound())->toBeFalse()
        ->and($context->id())->toBeNull();
});

it('clears the tenant context after a request that failed inside the pipeline', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');

    // Right center, wrong token half: resolution succeeds, authentication does
    // not, and the request dies with a tenant already bound.
    $forged = TenantApiToken::format(
        $this->publicKeyOf($alpha['tenant']),
        '999999|nonexistent-token-value',
    );

    $this->withHeaders($this->tokenHeaders($forged))
        ->getJson('/api/v1/tenant/me')
        ->assertUnauthorized();

    expect(app(TenantContext::class)->isBound())->toBeFalse();
});

it('rejects a token whose host names a different center, before authenticating', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $authenticated = false;
    Event::listen(Authenticated::class, function () use (&$authenticated): void {
        $authenticated = true;
    });

    $this->withHeaders($this->tokenHeaders($this->apiTokenFor($alpha['tenant'])))
        ->getJson('http://beta.localhost:8000/api/v1/tenant/me')
        ->assertForbidden()
        ->assertJsonPath('error.code', 'TENANT.RESOLUTION_CONFLICT');

    // Caught BEFORE any authentication attempt. Order matters here too:
    // authenticating first and checking the tenant afterwards would mean a
    // cross-tenant token had already been resolved against a database.
    expect($authenticated)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The web pipeline
|--------------------------------------------------------------------------
|
| Same priority list, same rule. The web guard loads the staff account from the
| tenant database too (ADR-030).
|
*/

it('has the session\'s center bound when the web guard loads the user', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $owner = $this->ownerOf($alpha['tenant']);

    $context = app(TenantContext::class);
    $seen = null;

    Event::listen(Authenticated::class, function () use ($context, &$seen): void {
        $seen ??= ['bound' => $context->isBound(), 'tenant' => $context->id()];
    });

    // Seeded as a real session rather than actingAs(), which would hand the
    // guard a user object and skip the database lookup this test is about.
    $connections = connectionsQueryingTable('users', function () use ($alpha, $owner): void {
        $this->withSession([
            StanclTenantResolver::SESSION_KEY => $this->publicKeyOf($alpha['tenant']),
            Auth::guard('web')->getName() => $owner->getAuthIdentifier(),
        ])->get('http://alpha.localhost:8000/manager')->assertOk();
    });

    expect($seen)->not->toBeNull()
        ->and($seen['bound'])->toBeTrue()
        ->and($seen['tenant'])->toBe($alpha['tenant']->id)
        ->and(array_unique($connections))->toBe(['tenant'])
        ->and($context->isBound())->toBeFalse();
});

it('rejects a web session whose center disagrees with the host', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    // A session that says Alpha arriving on Beta's host: a stale session, a
    // swapped cookie, or someone trying it on (ADR-030). Refused on the web
    // surface with a 403 page, not a 500 — the conflict is a decision, not a
    // crash.
    $this->withSession([StanclTenantResolver::SESSION_KEY => $this->publicKeyOf($alpha['tenant'])])
        ->get('http://beta.localhost:8000/manager')
        ->assertForbidden();

    expect(app(TenantContext::class)->isBound())->toBeFalse();
});

it('answers an unknown host with a plain not found on the web surface too', function (): void {
    // Enumerating which centers exist must not be possible by trying hostnames.
    $this->get('http://nobody.localhost:8000/manager')->assertNotFound();
});

/*
|--------------------------------------------------------------------------
| The startup assertion
|--------------------------------------------------------------------------
*/

it('places tenant resolution ahead of authentication in the priority list', function (): void {
    /** @var list<string> $priority */
    $priority = app(Router::class)->middlewarePriority;

    $tenant = array_search(ResolveTenant::class, $priority, true);
    $auth = array_search(AuthenticatesRequests::class, $priority, true);

    expect($tenant)->not->toBeFalse()
        ->and($auth)->not->toBeFalse()
        ->and($tenant)->toBeLessThan($auth);

    // The anchor is the interface, not the concrete class. Anchoring on
    // Authenticate::class is what silently did nothing.
    expect(in_array(Authenticate::class, $priority, true))->toBeFalse();
});

it('boots with the ordering guard satisfied', function (): void {
    // The application under test booted, which already ran this. Asserting it
    // explicitly records that a green suite implies a valid pipeline.
    expect(fn () => app(MiddlewareOrderGuard::class)->assert(app(Router::class)))
        ->not->toThrow(MiddlewareOrderViolation::class);
});

it('refuses to boot a pipeline that authenticates first', function (): void {
    $router = new Router(app('events'), app());
    $router->middlewarePriority = [AuthenticatesRequests::class, ResolveTenant::class];

    expect(fn () => app(MiddlewareOrderGuard::class)->assert($router))
        ->toThrow(MiddlewareOrderViolation::class, 'authentication (position 0) before tenant resolution');
});

it('refuses to boot a pipeline that does not prioritise tenant resolution at all', function (): void {
    $router = new Router(app('events'), app());
    $router->middlewarePriority = [AuthenticatesRequests::class];

    // The original bug's exact signature: the insert did nothing, so
    // ResolveTenant fell to the unprioritised tail of the list.
    expect(fn () => app(MiddlewareOrderGuard::class)->assert($router))
        ->toThrow(MiddlewareOrderViolation::class, 'absent from the middleware priority list');
});

it('resolves the center before the throttle on Livewire\'s upload endpoint', function (): void {
    $router = app(Router::class);

    // The resolved, sorted pipeline — what actually runs, not what the group says.
    $pipeline = array_map(
        static fn (mixed $middleware): string => is_string($middleware) ? $middleware : get_debug_type($middleware),
        $router->gatherRouteMiddleware($router->getRoutes()->getByName('livewire.upload-file')),
    );

    $resolver = array_search(ResolveLivewireTenant::class, $pipeline, true);
    $throttle = collect($pipeline)->search(static fn (string $middleware): bool => str_starts_with($middleware, ThrottleRequests::class));

    expect($resolver)->not->toBeFalse()
        ->and($throttle)->not->toBeFalse()
        ->and($resolver)->toBeLessThan($throttle);
});

it('refuses to boot a pipeline whose Livewire resolver is not prioritised', function (): void {
    $router = new Router(app('events'), app());
    $router->middlewarePriority = [ResolveTenant::class, AuthenticatesRequests::class, ThrottleRequests::class];

    expect(fn () => app(MiddlewareOrderGuard::class)->assert($router))
        ->toThrow(MiddlewareOrderViolation::class, ResolveLivewireTenant::class.' is absent');
});

it('refuses to boot a pipeline that throttles before the Livewire resolver', function (): void {
    $router = new Router(app('events'), app());
    $router->middlewarePriority = [ResolveTenant::class, ThrottleRequests::class, ResolveLivewireTenant::class, AuthenticatesRequests::class];

    expect(fn () => app(MiddlewareOrderGuard::class)->assert($router))
        ->toThrow(MiddlewareOrderViolation::class, 'reads the signed-in user');
});

it('refuses to boot when the anchor it sorts against has gone', function (): void {
    $router = new Router(app('events'), app());
    $router->middlewarePriority = [ResolveTenant::class];

    // A framework upgrade that renames the anchor would leave the ordering
    // unverified. Unverified is not the same as correct.
    expect(fn () => app(MiddlewareOrderGuard::class)->assert($router))
        ->toThrow(MiddlewareOrderViolation::class, 'no longer contains');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
