<?php

declare(strict_types=1);

namespace App\Kernel\Localization\Http\Middleware;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Contracts\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides what language this request speaks, once.
 *
 * ## Two language systems, never one
 *
 *   INTERFACE languages   what Meta Style itself is shown in — EN / AR / KU.
 *                         Staff, the Manager, Super Admin and the platform.
 *   CONTENT languages     what a CENTER publishes to its customers — the
 *                         center's own choice (TenantLocales).
 *
 * A manager may run the Manager in English while the center's customers read
 * Arabic and Kurdish. Validating the interface against the center's content
 * languages forced an Arabic-only center's staff into Arabic whatever they
 * chose. A surface is customer-facing when its route sits behind
 * `public.tenant` or a customer guard; everything else is the interface.
 * Livewire replays this middleware against the ORIGINAL route, so a component
 * update speaks the same language as the page it came from.
 *
 * ## The chain (docs/07-LOCALIZATION.md §5)
 *
 *   1. explicit `?locale=`
 *   2. the explicit choice persisted in this session
 *   3. the same choice remembered in this browser (survives sign-out and an
 *      expired session)
 *   4. the signed-in account's preference
 *   5. Accept-Language — never above an explicit choice
 *   6. the center's default, then the platform fallback
 *
 * A candidate the surface does not allow is skipped, not an error: a customer
 * whose phone is set to French sees the menu in the center's language.
 *
 * Runs AFTER tenant resolution. With no tenant bound it serves the platform.
 */
final class SetLocale
{
    public const SESSION_KEY = 'metastyle.locale';

    public const COOKIE = 'metastyle_locale';

    private const COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $this->allowedFor($request);
        $explicit = $this->explicit($request);

        if ($explicit !== null && in_array($explicit, $allowed, true)) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $explicit);
            }
            Cookie::queue(self::COOKIE, $explicit, self::COOKIE_MINUTES);
        }

        $locale = $this->first($allowed, [
            $explicit,
            $this->persisted($request),
            $this->remembered($request),
            $this->userPreference($request),
            $this->negotiate($request, $allowed),
        ]);

        app()->setLocale($locale);

        // Carbon formats dates in its own locale, which is not the app's
        // unless it is told. Without this a menu in Arabic shows English
        // weekday names.
        setlocale(LC_TIME, $locale);

        // Read by the layout for `dir` and the font stack, so no Blade file
        // ever needs its own list of RTL locales (docs/07-LOCALIZATION.md §10).
        $request->attributes->set('locale_direction', $this->languages->direction($locale));

        return $next($request);
    }

    /**
     * @return list<string>
     */
    private function allowedFor(Request $request): array
    {
        $allowed = $this->customerFacing($request) ? $this->locales->enabled() : $this->languages->supported();

        return array_values(array_filter($allowed, 'is_string'));
    }

    private function customerFacing(Request $request): bool
    {
        if (! $this->tenants->isBound()) {
            return false;
        }

        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && ($middleware === 'public.tenant' || preg_match('/^(auth|guest):customer/', $middleware) === 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<?string>  $candidates
     */
    private function first(array $allowed, array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate !== null && in_array($candidate, $allowed, true)) {
                return $candidate;
            }
        }

        $default = $this->locales->default();

        return in_array($default, $allowed, true) ? $default : ($allowed[0] ?? $default);
    }

    /**
     * The locale the caller asked for, if any — validated by the caller
     * against what this surface allows.
     */
    private function explicit(Request $request): ?string
    {
        $explicit = $request->query('locale');

        return is_string($explicit) && $explicit !== '' ? $explicit : null;
    }

    private function persisted(Request $request): ?string
    {
        if (! $request->hasSession()) {
            return null;
        }

        $persisted = $request->session()->get(self::SESSION_KEY);

        return is_string($persisted) && $persisted !== '' ? $persisted : null;
    }

    private function remembered(Request $request): ?string
    {
        $remembered = $request->cookie(self::COOKIE);

        return is_string($remembered) && $remembered !== '' ? $remembered : null;
    }

    /**
     * Only the guard that belongs HERE: a center's staff guard when a center
     * is bound, the platform guard otherwise. Asking the default guard on the
     * platform host let a stray center "remember me" cookie query a tenant
     * table with no tenant bound.
     */
    private function userPreference(Request $request): ?string
    {
        $user = $this->tenants->isBound() ? $request->user('web') : $request->user('platform');

        return $user !== null && isset($user->locale) && is_string($user->locale) && $user->locale !== ''
            ? $user->locale
            : null;
    }

    /**
     * Picks the caller's most-preferred language among those this surface
     * allows. `getPreferredLanguage` handles the `ar-IQ` → `ar` narrowing that
     * a hand-rolled parser gets wrong.
     *
     * @param  list<string>  $allowed
     */
    private function negotiate(Request $request, array $allowed): ?string
    {
        if ($allowed === [] || $request->headers->get('Accept-Language') === null) {
            return null;
        }

        $preferred = $request->getPreferredLanguage($allowed);

        if (! is_string($preferred)) {
            return null;
        }

        // Symfony answers the FIRST allowed locale when nothing the browser
        // asked for matches. That is not a preference, so it must not outrank
        // the center's default.
        foreach ($request->getLanguages() as $language) {
            $normalised = strtolower(str_replace('-', '_', $language));

            if ($normalised === $preferred || strtok($normalised, '_') === $preferred) {
                return $preferred;
            }
        }

        return null;
    }
}
