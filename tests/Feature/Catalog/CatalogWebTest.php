<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Livewire\Center\Branches;
use App\Livewire\Center\Catalog;
use App\Livewire\Center\MenuDesigner;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Catalog and menu web UI
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 4 §22.
|
| Enough to prove the flows work through a browser-shaped path. Every write goes
| through the same Action the API uses, so these check the wiring — not a second
| implementation of the rules.
|
*/

it('adds a branch with split opening hours from the web form', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(Branches::class)
            ->set('name.en', 'Mansour')
            ->set('timezone', 'Asia/Baghdad')
            ->set('phone', '+9647700000005')
            ->call('addInterval')
            ->set('hours.0.day_of_week', 0)
            ->set('hours.0.opens_at', '09:00')
            ->set('hours.0.closes_at', '13:00')
            ->call('addInterval')
            ->set('hours.1.day_of_week', 0)
            ->set('hours.1.opens_at', '16:00')
            ->set('hours.1.closes_at', '22:00')
            ->call('save')
            ->assertHasNoErrors();

        $branch = Branch::query()->where('is_main', false)->firstOrFail();

        expect($branch->name?->get('en'))->toBe('Mansour')
            ->and($branch->workingHours()->count())->toBe(2);
    });
});

it('reports overlapping hours back to the person entering them', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(Branches::class)
            ->set('name.en', 'Overlap')
            ->call('addInterval')
            ->set('hours.0.opens_at', '09:00')
            ->set('hours.0.closes_at', '14:00')
            ->call('addInterval')
            ->set('hours.1.opens_at', '13:00')
            ->set('hours.1.closes_at', '18:00')
            ->call('save')
            ->assertHasErrors('hours');

        // Nothing was written: the whole schedule save is one transaction.
        expect(Branch::query()->where('is_main', false)->count())->toBe(0);
    });
});

it('creates a service with a variation that inherits its price', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(Catalog::class)
            ->set('serviceName.en', 'Haircut')
            ->set('duration', 30)
            ->set('price', '25000')
            ->call('addVariation')
            ->set('variations.0.name.en', 'Standard')
            // Blank price: follow the service.
            ->set('variations.0.price', '')
            ->call('saveService')
            ->assertHasNoErrors();

        $service = Service::query()->firstOrFail();
        $variation = $service->variations()->firstOrFail();

        expect($service->price_minor)->toBe(25000)
            ->and($variation->price_minor)->toBeNull()
            ->and($variation->effectivePrice($service)->minor)->toBe(25000);
    });
});

it('rejects a price the currency cannot express', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        // IQD has no minor unit, so "25000.50" is not a price a center can
        // charge — and it must be refused rather than silently truncated.
        Livewire::actingAs($owner)
            ->test(Catalog::class)
            ->set('serviceName.en', 'Haircut')
            ->set('duration', 30)
            ->set('price', '25000.50')
            ->call('saveService')
            ->assertHasErrors('price');

        expect(Service::query()->count())->toBe(0);
    });
});

it('renders one input per enabled locale, and no more', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');

        // A center that has not enabled Kurdish never sees a Kurdish field.
        Livewire::actingAs($owner)
            ->test(Catalog::class)
            ->assertSee('(en)')
            ->assertSee('(ar)')
            ->assertDontSee('(ckb)');
    });
});

it('saves a menu draft without changing the live page, then publishes it', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->seedCatalog();

        Livewire::actingAs($owner)
            ->test(MenuDesigner::class)
            ->set('templateKey', 'luxury')
            ->set('theme.primary', '#7c2d12')
            ->call('saveDraft')
            ->assertHasNoErrors();

        expect(MenuVersion::query()->published()->firstOrFail()->template_key)->toBe('minimal');

        Livewire::actingAs($owner)
            ->test(MenuDesigner::class)
            ->set('templateKey', 'luxury')
            ->call('publish')
            ->assertHasNoErrors();

        expect(MenuVersion::query()->published()->firstOrFail()->template_key)->toBe('luxury');
    });
});

it('shows a rejected theme value instead of dropping it', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        Livewire::actingAs($owner)
            ->test(MenuDesigner::class)
            ->set('theme.primary', 'javascript:alert(1)')
            ->call('saveDraft')
            ->assertHasErrors('theme');
    });
});

it('serves the public menu page to a guest, in the right direction', function (): void {
    $center = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');

    $this->asCenter($center['tenant'], function (): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        $this->publishMenu();
        $this->seedCatalog();
    });

    $key = $this->publicKeyOf($center['tenant']);

    $this->get("/m/{$key}")
        ->assertOk()
        ->assertSee('Barbershop Alpha')
        ->assertSee('Haircut')
        ->assertSee('20,000 IQD')
        ->assertSee('dir="ltr"', escape: false);

    // Arabic is right-to-left, and the direction comes from the language
    // registry rather than a hardcoded list (docs/07-LOCALIZATION.md §10).
    $this->get("/m/{$key}?locale=ar")
        ->assertOk()
        ->assertSee('قص شعر')
        ->assertSee('dir="rtl"', escape: false)
        ->assertSee('lang="ar"', escape: false);
});

it('keeps the public menu page free of anything internal', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->publishMenu();
        $catalog = $this->seedCatalog();

        $catalog['service']->addInternalNote('Cost 8,000 — never quote below 15,000.');

        Service::query()->create([
            'name' => TranslatedText::make('en', 'Staff Only Service'),
            'duration_minutes' => 30, 'price_minor' => 1000,
            'is_active' => true, 'is_public' => false,
        ]);
    });

    $html = $this->get('/m/'.$this->publicKeyOf($center['tenant']))->assertOk()->getContent();

    expect($html)->not->toContain('Cost 8,000')
        ->not->toContain('Staff Only Service')
        ->not->toContain('owner@alpha.test')
        ->toContain('Haircut');
});

it('shows the center area only to a signed-in member of staff', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->withSession([
        StanclTenantResolver::SESSION_KEY => $this->publicKeyOf($center['tenant']),
        Permission::BranchView->value => null,
    ])->get('/center/branches')->assertRedirect();

    $this->withSession([
        StanclTenantResolver::SESSION_KEY => $this->publicKeyOf($center['tenant']),
        Auth::guard('web')->getName() => $owner->getAuthIdentifier(),
    ])->get('/center/branches')->assertOk()->assertSee('Branches');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
