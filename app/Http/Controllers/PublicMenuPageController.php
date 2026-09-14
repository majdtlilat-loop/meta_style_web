<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Tenancy\Http\Middleware\ResolvePublicTenant;
use App\Modules\Menu\Application\PublicMenuQuery;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer-facing menu page.
 *
 * A plain server-rendered page, not Livewire: it is read-only, it is opened
 * from a QR code on a phone, and it should render without a JavaScript payload
 * on a slow connection.
 *
 * Everything it renders comes from {@see PublicMenuQuery}, which loads only
 * publicly-visible rows in a bounded number of queries.
 */
final class PublicMenuPageController extends Controller
{
    public function __invoke(
        Request $request,
        PublicMenuQuery $query,
        TenantContext $tenants,
        TenantLocales $locales,
        LanguageRegistry $languages,
        Entitlements $entitlements,
    ): View {
        $branch = $request->query('branch');

        $menu = $query->forBranch(is_string($branch) && $branch !== '' ? $branch : null);

        if ($menu === null) {
            throw new NotFoundHttpException;
        }

        $locale = app()->getLocale();

        return view('menu.show', [
            'menu' => $menu,
            'center' => $tenants->require(),
            'locale' => $locale,
            'direction' => $languages->direction($locale),
            'locales' => $locales->enabled(),
            'languages' => $languages,

            /*
             * Whether to offer booking at all.
             *
             * The MENU stays viewable for a center without the `booking`
             * entitlement — it is the center's shop window, and switching it
             * off would punish their customers for a billing decision. Only the
             * Book action disappears (docs/13-ROADMAP.md Phase 6 §31).
             */
            'bookable' => $entitlements->enabled('booking'),

            // The key already in the URL. Passed through rather than re-derived
            // so the links this page renders cannot drift from the route it was
            // reached by.
            'centerKey' => (string) $request->route(ResolvePublicTenant::PARAMETER),
        ]);
    }
}
