<?php

declare(strict_types=1);

use App\Kernel\Localization\Actions\UpdateContentLanguages;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Tenancy\Actions\UpdateOwnCenterProfile;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Modules\Booking\Application\Actions\UpdateBookingSettings;
use App\Modules\Booking\Domain\BookingSettings;
use App\Modules\Menu\Application\Actions\SavePageAppearance;
use App\Modules\Menu\Application\Actions\SavePublicPolicies;
use App\Modules\Menu\Application\PublicPageAppearance;
use App\Modules\Printing\Application\Actions\UpdatePrintAppearance;
use App\Modules\Printing\Application\PrintAppearance;

/*
|--------------------------------------------------------------------------
| Appearance and settings isolation
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md §4 — release gate.
|
| Booking, cart and print appearance, policies, booking rules and content
| languages are tenant `settings` rows; the center profile is the bound
| tenant's own control-plane row. Writing any of them in one center must leave
| every other center exactly as it was.
|
*/

it('keeps appearance, policies, booking rules and languages inside the center that saved them', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();
        $this->grantPrinting();

        app(SavePageAppearance::class)($owner, 'booking', ['texts' => ['title' => ['en' => 'Alpha booking']], 'values' => ['header_style' => 'gradient']]);
        app(SavePageAppearance::class)($owner, 'cart', ['texts' => ['heading' => ['en' => 'Alpha basket']]]);
        app(SavePublicPolicies::class)($owner, ['terms' => ['en' => 'Alpha terms']]);
        app(UpdatePrintAppearance::class)($owner, ['texts' => ['footer_text' => ['en' => 'Alpha footer']]]);
        app(UpdateBookingSettings::class)($owner, ['slot_interval_minutes' => 45]);
        app(UpdateContentLanguages::class)($owner, ['en', 'ar', 'ckb'], 'ckb');

        // Pinned: every write really landed — in Alpha.
        app(BookingSettings::class)->forget();
        expect(app(PublicPageAppearance::class)->get('booking')->text('title', 'en'))->toBe('Alpha booking')
            ->and(app(PublicPageAppearance::class)->get('policies')->text('terms', 'en'))->toBe('Alpha terms')
            ->and(app(PrintAppearance::class)->get()->text('footer_text', 'en'))->toBe('Alpha footer')
            ->and(app(BookingSettings::class)->slotIntervalMinutes())->toBe(45);
    });

    $inBeta = $this->asCenter($beta['tenant'], function (): array {
        app(TenantLocales::class)->forget();
        app(BookingSettings::class)->forget();
        $pages = app(PublicPageAppearance::class);

        return [
            'booking_title' => $pages->get('booking')->text('title', 'en'),
            'header' => $pages->get('booking')->choice('header_style'),
            'cart' => $pages->get('cart')->text('heading', 'en'),
            'terms' => $pages->get('policies')->text('terms', 'en'),
            'footer' => app(PrintAppearance::class)->get()->text('footer_text', 'en'),
            'slot' => app(BookingSettings::class)->slotIntervalMinutes(),
            'primary' => app(TenantLocales::class)->default(),
        ];
    });

    expect($inBeta)->toBe([
        'booking_title' => null,
        'header' => 'plain',
        'cart' => null,
        'terms' => null,
        'footer' => null,
        'slot' => 15,
        'primary' => 'en',
    ]);
});

it('writes the center profile only to the bound center\'s own row', function (): void {
    $alpha = $this->registerCenter('Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Beta', 'owner@beta.test');

    $betaBefore = TenantModel::query()->findOrFail($beta['tenant']->id)->only(UpdateOwnCenterProfile::FIELDS);

    $this->asCenter($alpha['tenant'], function (): void {
        app(UpdateOwnCenterProfile::class)($this->ownerWithCatalogAccess(), [
            'name' => 'Alpha Renamed',
            'contact_email' => 'hello@alpha.test',
            'timezone' => 'Asia/Baghdad',
        ]);
    });

    expect(TenantModel::query()->findOrFail($alpha['tenant']->id)->name)->toBe('Alpha Renamed')
        ->and(TenantModel::query()->findOrFail($beta['tenant']->id)->only(UpdateOwnCenterProfile::FIELDS))->toBe($betaBefore);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
