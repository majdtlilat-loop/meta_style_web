<?php

declare(strict_types=1);

use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Kernel\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
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
 * accident" (ADR-043). Phase 10 added three more the same way: a customer
 * starting an online payment from their invoice link (the API and the page's
 * form), and a payment provider's callback (ADR-058, ADR-060). Phase 12 adds
 * one: a customer leaving the review their capability link opens, once. Phase 13
 * adds one more of the SAME kind as the payment callback -- a provider posting
 * a notification that is verified before anything is written (ADR-070).
 *
 * The assertion is not relaxed to match; it stays an exact list, and every
 * entry names the dedicated limiter it must carry. Anything not listed still
 * fails, so the exceptions cannot quietly become a general permission to write
 * from an unauthenticated page.
 *
 * @return array<string, string> "uri → METHOD" => required limiter
 */
function publicWriteLimiters(): array
{
    return [
        'api/v1/invoices/{center}/{token}/payments → POST' => 'public-payment',
        'api/v1/menu/{center}/bookings → POST' => 'public-booking',
        'api/v1/payments/{center}/gateways/{account}/webhook → POST' => 'payment-webhook',
        'api/v1/whatsapp/{center}/accounts/{account}/webhook → POST' => 'whatsapp-webhook',
        // The guest pages now live on the center's own host (the center is
        // resolved from the host, never from a path segment): the booking
        // form, the invoice payment and the review form.
        'booking → POST' => 'public-booking',
        'i/{token}/pay → POST' => 'public-payment',
        'r/{token} → POST' => 'public-review',
    ];
}

/**
 * @return list<Limit>
 */
function publicLimits(string $limiter, ?Request $request = null): array
{
    $callback = RateLimiter::limiter($limiter);

    expect($callback)->not->toBeNull("limiter {$limiter} is not defined");

    return array_values(Arr::wrap($callback($request ?? Request::create('/', 'POST'))));
}

it('writes only where it was deliberately allowed', function (): void {
    $writable = [];

    foreach (publicTenantRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['GET', 'HEAD'], true)) {
                $writable[] = $route->uri().' → '.$method;
            }
        }
    }

    sort($writable);

    expect($writable)->toBe(array_keys(publicWriteLimiters()));
});

it('throttles every public write with its own limiter, never the menu\'s', function (): void {
    $unprotected = [];

    foreach (publicTenantRoutes() as $route) {
        foreach ($route->methods() as $method) {
            if (in_array($method, ['GET', 'HEAD'], true)) {
                continue;
            }

            $required = publicWriteLimiters()[$route->uri().' → '.$method] ?? null;
            $middleware = $route->gatherMiddleware();

            // A write costs a transaction and a row somebody has to deal with —
            // or, for a payment, a call to a provider. `public-menu` is sized for
            // reading a price list; it is not a bound on any of that.
            if ($required === null
                || ! in_array('throttle:'.$required, $middleware, true)
                || in_array('throttle:public-menu', $middleware, true)) {
                $unprotected[] = $route->uri().' → '.$method;
            }
        }
    }

    expect($unprotected)->toBe([]);
});

it('keeps every write a stranger starts tighter than a menu read', function (): void {
    $menuPerMinute = min(array_map(static fn (Limit $limit): int => $limit->maxAttempts, publicLimits('public-menu')));

    // A booking, an online payment and a review are all started by a person on
    // a public page: every window of their limiters is below what reading the
    // menu gets.
    foreach (['public-booking', 'public-payment', 'public-review'] as $limiter) {
        foreach (publicLimits($limiter) as $limit) {
            if ($limit->decaySeconds <= 60) {
                expect($limit->maxAttempts)->toBeLessThan($menuPerMinute, "{$limiter} allows {$limit->maxAttempts}/min");
            }
        }
    }
});

it('bounds provider callbacks per gateway account, so rotating addresses buys nothing', function (): void {
    /*
     * A callback is sent by a provider, not a person, and may arrive in bursts.
     * Its budget is therefore per ACCOUNT — not per IP, which an attacker could
     * rotate — and every callback is verified before any write (docs/19 §21).
     */
    $request = Request::create('/api/v1/payments/center-key/gateways/account-uuid-1/webhook', 'POST');
    $request->setRouteResolver(fn () => Router::getRoutes()->match($request));

    $limits = publicLimits('payment-webhook', $request);

    expect($limits)->not->toBeEmpty();

    foreach ($limits as $limit) {
        expect((string) $limit->key)->toContain('account-uuid-1');
    }
});
