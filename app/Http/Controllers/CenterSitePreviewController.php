<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Modules\CenterSite\Application\PublicSitePage;
use App\Modules\CenterSite\Application\SiteAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The saved DRAFT of the center's site, rendered exactly as the public page
 * would render it — for the builder's preview frame.
 *
 * Lives in the AUTHENTICATED Manager group (auth:web + tenant by host), never
 * under the public-tenant resolver (ADR-036), and needs `appearance.view`.
 * `lang` picks the content language for this render only: it is limited to
 * the center's enabled languages and never changes the staff member's own
 * interface language. Always noindex, never cached.
 */
final class CenterSitePreviewController extends Controller
{
    public function __invoke(Request $request, PublicSitePage $site, TenantLocales $locales): Response
    {
        $user = $request->user('web');
        abort_unless($user instanceof User && SiteAccess::canView($user), 403);

        $lang = $request->query('lang');
        $locale = is_string($lang) && $locales->isEnabled($lang) ? $lang : $locales->default();
        app()->setLocale($locale);

        return response()
            ->view('center-public.landing', ['page' => $site->preview($locale)])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }
}
