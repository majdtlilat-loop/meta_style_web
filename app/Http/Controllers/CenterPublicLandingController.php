<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Modules\CenterSite\Application\PublicSitePage;
use Illuminate\Contracts\View\View;

/**
 * The center's public home page (`/` on the center's host): the PUBLISHED
 * site, or the default page for a center that has never published one.
 *
 * Guest, throttled and localized (the `public.tenant` group); the language is
 * the one SetLocale resolved against the center's enabled content languages.
 * Everything the template prints comes from PublicSitePage's allow-listed
 * array — no model reaches the view.
 */
final class CenterPublicLandingController extends Controller
{
    public function __invoke(string $center, PublicSitePage $site): View
    {
        unset($center);

        return view('center-public.landing', ['page' => $site->live(app()->getLocale())]);
    }
}
