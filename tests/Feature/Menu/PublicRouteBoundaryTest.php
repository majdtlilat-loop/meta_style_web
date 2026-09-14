<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Kernel\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
|--------------------------------------------------------------------------
| The public route boundary
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-036.
|
| `ResolvePublicTenant` accepts a center's public key from the URL path. That is
| the ONE deliberate exception to "a tenant identifier never comes from the
| client", and what makes it safe is the boundary around it, not the identifier.
|
| So the boundary is a test rather than a convention. A public key in a URL must
| never become a way to ACT as a center — and the day somebody adds
| `auth:sanctum` to the menu group to "let signed-in staff preview it", this
| fails.
|
*/

/**
 * @return list<Route>
 */
function publicTenantRoutes(): array
{
    $routes = [];

    foreach (Router::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        if (in_array('public.tenant', $middleware, true)
            || in_array(ResolvePublicTenant::class, $middleware, true)) {
            $routes[] = $route;
        }
    }

    return $routes;
}

it('has a public-tenant surface at all', function (): void {
    // Guards every other test in this file: if the routes disappeared, the
    // checks below would pass by checking nothing.
    expect(publicTenantRoutes())->not->toBeEmpty();
});

it('never puts an authenticating middleware on a public-tenant route', function (): void {
    $violations = [];

    foreach (publicTenantRoutes() as $route) {
        foreach ($route->gatherMiddleware() as $entry) {
            $name = is_string($entry) ? $entry : '';

            if ($name === 'auth' || str_starts_with($name, 'auth:') || str_starts_with($name, 'auth.')) {
                $violations[] = $route->uri().' → '.$name;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps the two tenant resolvers mutually exclusive', function (): void {
    $violations = [];

    foreach (Router::getRoutes() as $route) {
        $middleware = $route->gatherMiddleware();

        $authenticated = in_array('tenant', $middleware, true)
            || in_array(ResolveTenant::class, $middleware, true);

        $public = in_array('public.tenant', $middleware, true)
            || in_array(ResolvePublicTenant::class, $middleware, true);

        // Together, the path segment would become a resolution source for
        // authenticated requests — exactly what the separation prevents.
        if ($authenticated && $public) {
            $violations[] = $route->uri();
        }
    }

    expect($violations)->toBe([]);
});

it('throttles every public-tenant route', function (): void {
    $unthrottled = [];

    foreach (publicTenantRoutes() as $route) {
        $throttled = false;

        foreach ($route->gatherMiddleware() as $entry) {
            if (is_string($entry) && str_starts_with($entry, 'throttle:')) {
                $throttled = true;
            }
        }

        if (! $throttled) {
            // Unauthenticated and unbounded is how one center's menu becomes a
            // way to exhaust the platform (docs/08-AUDIT-SECURITY.md §13).
            $unthrottled[] = $route->uri();
        }
    }

    expect($unthrottled)->toBe([]);
});

it('resolves the locale on every public-tenant route', function (): void {
    $missing = [];

    foreach (publicTenantRoutes() as $route) {
        if (! in_array('locale', $route->gatherMiddleware(), true)) {
            // A menu that ignores the customer's language is the single most
            // visible failure this surface can have.
            $missing[] = $route->uri();
        }
    }

    expect($missing)->toBe([]);
});

/*
 * Phase 4 asserted the public surface was read-only. Phase 6 added the two
 * writes it said would come — "deliberately, with their own protections, not by
 * accident" (ADR-043). The assertion is not relaxed to match; it is made
 * specific. Anything that is not one of these two still fails, and each of the
 * two must carry the tighter booking throttle, so the exception cannot quietly
 * become a general permission to write from an unauthenticated page.
 */
it('writes only where booking was deliberately allowed', function (): void {
    $writable = [];

    foreach (publicTenantRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['GET', 'HEAD'], true)) {
                $writable[] = $route->uri().' → '.$method;
            }
        }
    }

    sort($writable);

    expect($writable)->toBe([
        'api/v1/menu/{center}/bookings → POST',
        'm/{center}/book → POST',
    ]);
});

it('throttles every public write harder than a menu read', function (): void {
    $unprotected = [];

    foreach (publicTenantRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                continue;
            }

            // A booking write costs a database transaction and a row somebody
            // has to deal with. `public-menu` is sized for reading a price
            // list; it is not a bound on how many appointments a stranger may
            // create.
            if (! in_array('throttle:public-booking', $route->gatherMiddleware(), true)) {
                $unprotected[] = $route->uri().' → '.$method;
            }
        }
    }

    expect($unprotected)->toBe([]);
});
