<?php

declare(strict_types=1);

namespace App\Kernel\Localization\Http\Middleware;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides what language this request speaks, once.
 *
 * The chain, in order (docs/07-LOCALIZATION.md §5):
 *
 *   1. explicit `?locale=` — must be one the center has enabled
 *   2. the signed-in user's preference
 *   3. Accept-Language, negotiated against the center's enabled locales
 *   4. the center's default locale
 *   5. the platform fallback
 *
 * A requested locale the center has not enabled falls back silently. It is not
 * an error: a customer whose phone is set to French should see the menu in
 * Arabic, not a 404.
 *
 * Runs AFTER tenant resolution, because steps 1–4 all need to know which center
 * this is. With no tenant bound it degrades to the platform fallback rather
 * than failing — the registration and login pages are real pages.
 */
final class SetLocale
{
    public function __construct(
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->locales->resolve($this->requested($request));

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
     * The locale the caller asked for, if any. Not yet validated against what
     * the center has enabled — that is `TenantLocales::resolve()`'s job.
     */
    private function requested(Request $request): ?string
    {
        $explicit = $request->query('locale');

        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $user = $request->user();

        if ($user !== null && isset($user->locale) && is_string($user->locale) && $user->locale !== '') {
            return $user->locale;
        }

        return $this->negotiate($request);
    }

    /**
     * Picks the caller's most-preferred language that this center actually has.
     *
     * `getPreferredLanguage` needs the candidate list up front, and it handles
     * the `ar-IQ` → `ar` narrowing that a hand-rolled parser gets wrong.
     */
    private function negotiate(Request $request): ?string
    {
        $enabled = $this->locales->enabled();

        if ($enabled === []) {
            return null;
        }

        $preferred = $request->getPreferredLanguage($enabled);

        return is_string($preferred) ? $preferred : null;
    }
}
