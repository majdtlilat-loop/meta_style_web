<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Kernel\Appearance\Appearance;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Menu\Application\MenuPublisher;
use App\Modules\Menu\Application\PublicMenuQuery;
use App\Modules\Menu\Application\PublicPageAppearance;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The Manager's previews of the guest pages: the menu DRAFT, and the booking
 * and cart pages with the appearance currently on screen.
 *
 * Each renders the REAL public template, so what an owner checks is exactly
 * what a customer will get. They live in the authenticated /manager group and
 * nowhere else — never under the public resolver, which may not share a route
 * with authentication (ADR-036) — and they are marked noindex and uncacheable.
 *
 * `?lang=` picks one of the center's ENABLED content languages for this
 * render only; the viewer's own interface language is untouched (it is not
 * `?locale=`, which the locale middleware would remember).
 *
 * The booking and cart previews read what the editor STASHED in this viewer's
 * session a moment earlier (validated there), falling back to what is saved.
 * No preview writes anything, and no booking can be made from one.
 */
final class ManagerAppearancePreviewController extends Controller
{
    public const STASH = 'appearance_preview.';

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly TenantLocales $locales,
        private readonly LanguageRegistry $languages,
    ) {}

    public function menu(Request $request, MenuPublisher $publisher, PublicMenuQuery $query, Entitlements $entitlements): Response
    {
        $this->authorize($request, Permission::MenuView);
        $locale = $this->locales->resolve($this->lang($request));

        $menu = $query->forPresentation($publisher->previewPresentation());

        if ($menu === null) {
            throw new NotFoundHttpException;
        }

        return $this->render('menu.show', $locale, fn (): array => [
            'menu' => $menu,
            'center' => $this->tenants->require(),
            'locales' => $this->locales->enabled(),
            'languages' => $this->languages,
            'bookable' => $entitlements->enabled('booking'),
            'centerKey' => (string) $request->route('center'),
            'preview' => true,
            'langParam' => 'lang',
        ]);
    }

    public function booking(Request $request, PublicPageAppearance $pages, Entitlements $entitlements): Response
    {
        $this->authorize($request, Permission::AppearanceView);

        // No `booking`, no booking page — the same answer the public route gives.
        if (! $entitlements->enabled('booking')) {
            throw new NotFoundHttpException;
        }

        $locale = $this->locales->resolve($this->lang($request));
        $override = $this->stashed($request, $pages, 'booking');

        /** @var Collection<int, Branch> $branches */
        $branches = Branch::query()->publiclyVisible()->get();
        $branch = $branches->first();

        $services = $branch instanceof Branch
            ? Service::query()->publiclyVisible()->where('is_online_bookable', true)->atBranch((int) $branch->getKey())->get()
            : new Collection;

        return $this->render('menu.book', $locale, fn (): array => [
            'center' => $this->tenants->require(),
            'centerKey' => (string) $request->route('center'),
            'branches' => $branches,
            'branch' => $branch,
            'services' => $services,
            'service' => null,
            'variationUuid' => '',
            'employeeUuid' => '',
            'employees' => [],
            'date' => CarbonImmutable::now()->setTimezone($branch instanceof Branch ? $branch->timezone : 'UTC')->format('Y-m-d'),
            'slots' => [],
            'startsAt' => '',
            'currency' => Currency::default(),
            'error' => null,
            'idempotencyKey' => '',
            'confirmation' => null,
            'appearance' => $pages->booking($locale, $override),
            'preview' => true,
        ]);
    }

    public function cart(Request $request, PublicPageAppearance $pages): Response
    {
        $this->authorize($request, Permission::AppearanceView);
        $locale = $this->locales->resolve($this->lang($request));
        $override = $this->stashed($request, $pages, 'cart');

        return $this->render('center-public.cart', $locale, fn (): array => [
            'center' => $this->tenants->require(),
            'appearance' => $pages->cart($locale, $override),
            'preview' => true,
        ]);
    }

    /**
     * Renders in `$locale` and hands the interface language back afterwards,
     * so a preview in Kurdish leaves the Manager in the viewer's language.
     * The data is built INSIDE that window too: the built-in copy a page falls
     * back to is translated when it is resolved, not when it is printed.
     *
     * @param  Closure(): array<string, mixed>  $data
     */
    private function render(string $view, string $locale, Closure $data): Response
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);

        try {
            $html = view($view, $data() + [
                'locale' => $locale,
                'direction' => $this->languages->direction($locale),
            ])->render();
        } finally {
            app()->setLocale($previous);
        }

        return new Response($html, 200, [
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function stashed(Request $request, PublicPageAppearance $pages, string $page): ?Appearance
    {
        $raw = $request->session()->get(self::STASH.$page);

        return is_array($raw) ? Appearance::fromStored($pages->schema($page), $raw, $this->languages->supported()) : null;
    }

    private function lang(Request $request): ?string
    {
        $lang = $request->query('lang');

        return is_string($lang) ? $lang : null;
    }

    private function authorize(Request $request, Permission $permission): void
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->hasPermission($permission), 403);
    }
}
