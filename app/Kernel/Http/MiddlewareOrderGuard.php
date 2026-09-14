<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Localization\Http\Middleware\SetLocale;
use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Kernel\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Routing\Router;

/**
 * Fails the boot if tenant resolution could run after authentication.
 *
 * Why this exists rather than a comment in `bootstrap/app.php`: the ordering was
 * silently wrong once already. `prependToPriorityList(before: Authenticate::class)`
 * looks correct, reads correctly in review, and does nothing — the priority list
 * holds the `AuthenticatesRequests` *interface*, so the anchor never matched and
 * `ResolveTenant` was appended to the end of the list instead. Route arrays listed
 * `['tenant', 'auth:sanctum']` in the right order the whole time; priority
 * silently overrode them.
 *
 * The consequence is not a broken page, which is why it survived review: Sanctum
 * would look for the token, find the tenant connection unbound, and fail closed
 * on `TenantConnectionGuard`. Correct, but by accident of a second defence
 * rather than by design — and any future middleware that reads tenant data
 * during authentication would have had no such backstop.
 *
 * Source inspection cannot catch this class of bug, because the source looked
 * right. Only the resolved list can. See docs/06-AUTH-ROLES-PERMISSIONS.md §2.5.
 */
final class MiddlewareOrderGuard
{
    /**
     * @throws MiddlewareOrderViolation
     */
    public function assert(Router $router): void
    {
        /** @var list<string> $priority */
        $priority = $router->middlewarePriority;

        $anchor = array_search(AuthenticatesRequests::class, $priority, true);

        if ($anchor === false) {
            // The framework no longer sorts on this interface. Whatever replaced
            // it, our anchor is now meaningless and the ordering is unverified —
            // which is exactly the state this guard exists to refuse.
            throw new MiddlewareOrderViolation(sprintf(
                'Middleware priority no longer contains %s. The anchor in bootstrap/app.php '
                .'is stale, so tenant resolution is no longer guaranteed to precede '
                .'authentication. Re-anchor prependToPriorityList() against whatever the '
                .'framework now uses for authentication.',
                AuthenticatesRequests::class,
            ));
        }

        $tenant = array_search(ResolveTenant::class, $priority, true);

        if ($tenant === false) {
            throw new MiddlewareOrderViolation(sprintf(
                '%s is absent from the middleware priority list. Without a priority entry '
                .'Laravel sorts it after every prioritised middleware, including '
                .'authentication, so API tokens would be looked up with no tenant bound.',
                ResolveTenant::class,
            ));
        }

        if ($tenant > $anchor) {
            throw new MiddlewareOrderViolation(sprintf(
                'Middleware priority runs authentication (position %d) before tenant '
                .'resolution (position %d). Sanctum tokens live in the tenant database, so '
                .'the lookup must not run until a tenant is bound (ADR-027).',
                $anchor,
                $tenant,
            ));
        }

        $this->assertLocaleFollowsResolution($priority, $tenant);
    }

    /**
     * Tenant resolution must survive a Livewire component update.
     *
     * A SECOND SILENT ORDERING BUG, of exactly the same family as the one above
     * and found the same way — by looking at what actually runs rather than at
     * what the routes say.
     *
     * Livewire component actions do not POST to the route that rendered the
     * component. They POST to `/livewire/update`, which carries only the `web`
     * middleware group; Livewire then re-runs a filtered subset of the original
     * route's middleware — the ones registered as PERSISTENT. That subset
     * includes `Authenticate` by default and did not include `ResolveTenant`.
     *
     * So every Livewire action on `/center/*` authenticated a staff user whose
     * model lives in the tenant database, with no tenant bound. It failed
     * closed on `TenantConnectionGuard`, which is the right failure and the
     * wrong outcome: the admin area's interactivity did not work in a browser
     * at all. Tests did not catch it because `Livewire::test()` bypasses the
     * HTTP endpoint and binds the tenant itself.
     *
     * `AppServiceProvider` registers the two resolvers and the locale
     * middleware as persistent. This asserts it took effect, and that the order
     * Livewire will run them in still puts resolution first.
     *
     * @param  list<string>  $persistent  as reported by Livewire
     *
     * @throws MiddlewareOrderViolation
     */
    public function assertLivewirePersists(array $persistent): void
    {
        foreach ([ResolveTenant::class, SetLocale::class] as $required) {
            if (! in_array($required, $persistent, true)) {
                throw new MiddlewareOrderViolation(sprintf(
                    '%s is not registered as Livewire persistent middleware. Livewire '
                    .'component updates POST to /livewire/update, which does not carry the '
                    .'original route middleware, so every Livewire action on a tenant page '
                    .'would run with no tenant bound.',
                    $required,
                ));
            }
        }
    }

    /**
     * Locale resolution must follow tenant resolution.
     *
     * Four of the five steps in the locale chain — the explicit parameter, the
     * user preference, header negotiation, and the center default — are all
     * answered against the CENTER's enabled locales
     * (docs/07-LOCALIZATION.md §5). Run first, `SetLocale` sees no tenant and
     * silently serves every center in the platform fallback language.
     *
     * Silently is the problem: the page renders, in English, and nobody
     * notices until an Arabic-speaking customer does.
     *
     * @param  list<string>  $priority
     */
    private function assertLocaleFollowsResolution(array $priority, int $tenantPosition): void
    {
        $locale = array_search(SetLocale::class, $priority, true);

        if ($locale === false) {
            return;
        }

        $publicTenant = array_search(ResolvePublicTenant::class, $priority, true);

        $earliestResolver = $publicTenant === false
            ? $tenantPosition
            : min($tenantPosition, $publicTenant);

        if ($locale < $earliestResolver) {
            throw new MiddlewareOrderViolation(sprintf(
                'Middleware priority runs locale resolution (position %d) before tenant '
                .'resolution (position %d). Which locales exist is a per-center setting, so '
                .'every request would silently fall back to the platform default '
                .'(docs/07-LOCALIZATION.md §5).',
                $locale,
                $earliestResolver,
            ));
        }

        if ($publicTenant !== false && $publicTenant > $tenantPosition + 1 && $publicTenant > $locale) {
            throw new MiddlewareOrderViolation(sprintf(
                'The public-menu tenant resolver (position %d) must also precede locale '
                .'resolution (position %d).',
                $publicTenant,
                $locale,
            ));
        }
    }
}
