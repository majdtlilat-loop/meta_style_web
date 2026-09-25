<?php

declare(strict_types=1);

namespace App\View\Manager;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Tenancy\Contracts\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web answer to {@see EntitlementRequired}: 403 with the upgrade offer.
 *
 * An Action that refuses a feature the center does not own throws that
 * exception; without this a browser got a 500. JSON and API requests keep
 * their envelope (ApiExceptionRenderer runs first), and outside a bound
 * center there is no offer to show, so Laravel's default handling applies.
 *
 * A full page renders inside the Manager shell. A Livewire update (which
 * shows a failed response in an overlay) gets the same offer on a bare page.
 * Nothing is unlocked by this page: it only explains the refusal.
 */
final class FeatureLockedPage
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly FeatureOffer $offers,
        private readonly LanguageRegistry $languages,
    ) {}

    public function render(EntitlementRequired $exception, Request $request): ?Response
    {
        if ($request->expectsJson() || $request->is('api/*') || ! $this->tenants->isBound()) {
            return null;
        }

        $viewer = $request->user('web');
        $viewer = $viewer instanceof User ? $viewer : null;

        $offer = $this->offers->for($exception->entitlement, null, $viewer);
        if ($offer === null) {
            return null;
        }

        return response()->view('errors.feature-locked', [
            'offer' => $offer,
            'locale' => app()->getLocale(),
            'dir' => $this->languages->direction(app()->getLocale()),
            'standalone' => $viewer === null || $request->hasHeader('X-Livewire'),
            'back' => $viewer !== null && Route::has('center.dashboard') ? route('center.dashboard') : null,
        ], 403);
    }
}
