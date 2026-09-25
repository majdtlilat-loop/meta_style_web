<?php

declare(strict_types=1);

use App\Http\Controllers\ManagerAppearancePreviewController;
use App\Kernel\Appearance\AppearanceRejected;
use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Appearance\BookingAppearance;
use App\Livewire\Center\Appearance\CartAppearance;
use App\Modules\Menu\Application\Actions\SavePageAppearance;
use App\Modules\Menu\Application\Actions\SavePublicPolicies;
use App\Modules\Menu\Application\PublicPageAppearance;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Booking and cart page appearance
|--------------------------------------------------------------------------
|
| A closed, validated document per page — colours, choices, switches and
| plain-text copy per language — saved by one Action that checks the
| permission (and `booking` for the booking page), audits WHICH settings
| changed, and never lets markup through. The public pages render it; the cart
| stays an honest empty state with no invented line or total.
|
*/

it('saves a validated booking appearance, audits the change and refuses markup, bad colours and unknown keys', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $save = app(SavePageAppearance::class);

        $saved = $save($owner, 'booking', [
            'values' => ['header_style' => 'gradient', 'accent' => '#FF8800', 'show_prices' => false, 'inherit_brand' => false],
            'texts' => ['title' => ['en' => 'Book with Alpha', 'ar' => 'احجز مع ألفا'], 'cta_label' => ['en' => 'Reserve my seat']],
        ]);

        expect($saved->choice('header_style'))->toBe('gradient')
            ->and($saved->colour('accent'))->toBe('#ff8800')
            ->and($saved->flag('show_prices'))->toBeFalse()
            ->and(app(PublicPageAppearance::class)->get('booking')->text('title', 'ar'))->toBe('احجز مع ألفا');

        $audit = TenantAuditLog::query()->where('action', 'appearance.booking.updated')->firstOrFail();
        expect($audit->after['changed'])->toContain('header_style', 'texts.title')
            // Which settings changed — never the copy itself.
            ->and(json_encode($audit->after))->not->toContain('Book with Alpha');

        foreach ([
            ['texts' => ['intro' => ['en' => '<script>alert(1)</script>']]],
            ['texts' => ['intro' => ['en' => 'Hello <b>there</b>']]],
            ['values' => ['accent' => 'red']],
            ['values' => ['accent' => '#fff; background:url(x)']],
            ['values' => ['header_style' => 'video']],
            ['values' => ['custom_css' => 'body{}']],
            ['texts' => ['title' => ['fr' => 'Bonjour']]],
            ['texts' => ['title' => ['en' => str_repeat('a', 81)]]],
        ] as $input) {
            expect(fn () => $save($owner, 'booking', $input))->toThrow(AppearanceRejected::class);
        }

        // A rejected save changed nothing.
        expect(app(PublicPageAppearance::class)->get('booking')->colour('accent'))->toBe('#ff8800');
    });
});

it('needs appearance.manage to save, and booking to style the booking page', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $viewer = $this->staffWith([Permission::AppearanceView], 'appearance-viewer@alpha.test');

        expect(fn () => app(SavePageAppearance::class)($viewer, 'cart', ['values' => ['layout' => 'split']]))
            ->toThrow(AuthorizationException::class)
            ->and(fn () => app(SavePublicPolicies::class)($viewer, ['terms' => ['en' => 'x']]))
            ->toThrow(AuthorizationException::class);

        $this->revokeEntitlement('booking');
        $owner = $this->ownerWithCatalogAccess();

        expect(fn () => app(SavePageAppearance::class)($owner, 'booking', ['values' => ['header_style' => 'banner']]))
            ->toThrow(EntitlementRequired::class);

        // The cart has no entitlement of its own.
        expect(app(SavePageAppearance::class)($owner, 'cart', ['values' => ['layout' => 'split']])->choice('layout'))->toBe('split');
    });
});

it('keeps the texts of a disabled language and brings them back when it is enabled again', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app(TenantLocales::class)->setEnabled(['en', 'ckb'], 'en');
        app(SavePageAppearance::class)($owner, 'cart', ['texts' => ['heading' => ['en' => 'Basket', 'ckb' => 'سەبەتە']]]);

        // Kurdish is switched off; the form still carries its text and saves it back unchanged.
        app(TenantLocales::class)->setEnabled(['en'], 'en');
        app(TenantLocales::class)->forget();

        Livewire::actingAs($owner)->test(CartAppearance::class)
            ->assertSet('texts.heading.ckb', 'سەبەتە')
            ->set('texts.heading.en', 'Your basket')
            ->call('save')
            ->assertHasNoErrors();

        expect(app(PublicPageAppearance::class)->get('cart')->texts('heading'))->toBe(['en' => 'Your basket', 'ckb' => 'سەبەتە']);
    });
});

it('renders the booking page and the cart page in the center appearance, with policies and no fake cart', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->seedBookableCenter();
        $this->publishMenu();
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');

        app(SavePageAppearance::class)($owner, 'booking', [
            'values' => ['header_style' => 'banner', 'cta_style' => 'pill', 'steps_style' => 'numbered', 'show_prices' => false],
            'texts' => ['title' => ['en' => 'Reserve at Alpha', 'ar' => 'احجز في ألفا'], 'intro' => ['en' => 'Pick a time that suits you.']],
        ]);
        app(SavePublicPolicies::class)($owner, ['cancellation' => ['en' => 'Cancel up to 2 hours before.', 'ar' => 'يمكن الإلغاء قبل ساعتين.']]);
        app(SavePageAppearance::class)($owner, 'cart', [
            'values' => ['layout' => 'split', 'summary' => 'panel'],
            'texts' => ['empty_title' => ['en' => 'Nothing here yet']],
        ]);
    });

    $booking = $this->get("http://{$slug}.localhost:8000/booking")
        ->assertOk()
        ->assertSee('Reserve at Alpha')
        ->assertSee('Pick a time that suits you.')
        ->assertSee('Cancel up to 2 hours before.')
        ->assertSee('header-banner', false)
        ->assertSee('cta-pill', false)
        ->assertSee('steps-numbered', false);

    // Prices switched off: the service list shows no amount.
    expect(str_contains((string) $booking->getContent(), 'IQD'))->toBeFalse();

    $this->get("http://{$slug}.localhost:8000/booking?locale=ar")
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee('احجز في ألفا')
        ->assertSee('يمكن الإلغاء قبل ساعتين.');

    $cart = $this->get("http://{$slug}.localhost:8000/cart")
        ->assertOk()
        ->assertSee('Nothing here yet')
        ->assertSee('cart-layout-split', false)
        ->assertSee(__('menu_public.cart.summary_empty'));

    // Never a fake cart: no line, no amount, no total.
    expect(str_contains((string) $cart->getContent(), 'IQD'))->toBeFalse();

    $this->get("http://{$slug}.localhost:8000/checkout")->assertOk()->assertSee(__('menu_public.cart.checkout_body'));
});

it('edits the booking and cart pages in the Manager and previews unsaved changes without saving them', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->seedBookableCenter();
        $this->actingAs($owner);

        foreach (['en' => 'Booking page', 'ar' => 'صفحة الحجز', 'ckb' => 'پەڕەی نۆرەگرتن'] as $locale => $title) {
            $html = (string) $this->get("http://{$slug}.localhost:8000/manager/appearance/booking?locale={$locale}")->assertOk()->assertSee($title)->getContent();
            expect(preg_match('/\b(manager_appearance|menu_public)\.[a-z_]+/', strip_tags($html)))->toBe(0);
        }

        $this->get("http://{$slug}.localhost:8000/manager/appearance/cart")->assertOk()->assertSee(__('manager_appearance.cart.phase_note'));

        // A rejected value is put on its field, in words.
        Livewire::actingAs($owner)->test(BookingAppearance::class)
            ->set('values.accent', 'not-a-colour')
            ->call('save')
            ->assertHasErrors('values.accent');

        // Preview stashes the on-screen values for THIS session only…
        Livewire::actingAs($owner)->test(BookingAppearance::class)
            ->set('texts.title.en', 'Draft headline')
            ->call('preview')
            ->assertHasNoErrors()
            ->assertDispatched('booking-preview');

        $stash = session()->get(ManagerAppearancePreviewController::STASH.'booking');
        expect($stash['texts']['title']['en'] ?? null)->toBe('Draft headline');

        $this->withSession([ManagerAppearancePreviewController::STASH.'booking' => $stash])
            ->get("http://{$slug}.localhost:8000/manager/appearance/booking/preview?lang=en")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('Draft headline')
            // Everything in the requested language — the built-in copy too,
            // whatever interface language the viewer uses.
            ->assertSee(__('menu_public.booking.preview_banner', [], 'en'))
            ->assertSee(__('menu_public.booking.confirm', [], 'en'));

        // …and nothing was saved: the public page still has the default title.
        expect(app(PublicPageAppearance::class)->get('booking')->text('title', 'en'))->toBeNull();
    });

    $this->get("http://{$slug}.localhost:8000/booking")->assertOk()->assertDontSee('Draft headline');
});

it('keeps the previews behind appearance.view', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        $this->actingAs($this->staffWith([Permission::MenuView], 'menu-only@alpha.test'));

        $this->get("http://{$slug}.localhost:8000/manager/appearance/booking/preview")->assertForbidden();
        $this->get("http://{$slug}.localhost:8000/manager/appearance/cart/preview")->assertForbidden();
        $this->get("http://{$slug}.localhost:8000/manager/appearance/cart")->assertForbidden();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
