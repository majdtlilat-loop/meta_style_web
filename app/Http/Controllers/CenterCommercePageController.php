<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Menu\Application\PublicPageAppearance;
use Illuminate\Contracts\View\View;

/**
 * The cart and checkout pages of a center's public site.
 *
 * There is no cart backend yet — online checkout is a later phase — so both
 * pages say so honestly: the cart shows the center's own empty-state copy and
 * a way back to its services, never an invented line or total. What the
 * center DOES control today is how they look (Manager → Appearance → Cart),
 * passed here as validated values from PublicPageAppearance.
 */
final class CenterCommercePageController extends Controller
{
    public function cart(string $center, TenantContext $tenants, LanguageRegistry $languages, PublicPageAppearance $pages): View
    {
        unset($center);
        $locale = app()->getLocale();

        return view('center-public.cart', [
            'center' => $tenants->require(),
            'direction' => $languages->direction($locale),
            'appearance' => $pages->cart($locale),
        ]);
    }

    public function checkout(string $center, TenantContext $tenants, LanguageRegistry $languages, PublicPageAppearance $pages): View
    {
        unset($center);
        $locale = app()->getLocale();

        return view('center-public.checkout', [
            'center' => $tenants->require(),
            'direction' => $languages->direction($locale),
            'appearance' => $pages->cart($locale),
        ]);
    }
}
