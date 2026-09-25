<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\MenuDesigner;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Menu\Application\MenuPublisher;
use App\Modules\Menu\Domain\MenuPresentation;
use App\Modules\Menu\Domain\MenuPresentationRejected;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Support\Arr;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Manager → Appearance → Menu
|--------------------------------------------------------------------------
|
| The editor and the renderer it drives: every new choice is a closed,
| validated option; the public page honours the owner's section ORDER and
| per-section settings; the editor needs `menu.view` to open; the draft preview
| is an authenticated render of the real template that writes nothing; and no
| raw key or English validator sentence reaches the page.
|
*/

function maOrderOf(string $html, string $needle): int
{
    $position = strpos($html, $needle);

    return $position === false ? -1 : $position;
}

it('validates the new appearance options as a closed catalog and fills older rows with the old look', function (): void {
    $valid = MenuPresentation::fromArray([
        'template_key' => 'modern',
        'theme' => ['layout' => 'compact', 'price_style' => 'badge', 'hero_style' => 'gradient', 'gradient_angle' => '90', 'language_switch' => 'menu', 'colors_source' => 'brand', 'image_ratio' => 'square', 'type_scale' => 'large', 'cta_style' => 'pill'],
        'sections' => [['key' => 'categories', 'visible' => true, 'config' => ['layout' => 'chips', 'show_images' => true]]],
    ]);

    expect($valid->themeValue('layout'))->toBe('compact')
        ->and($valid->sectionConfig('categories'))->toBe(['layout' => 'chips', 'show_images' => true]);

    // A row written before these options existed renders exactly as before.
    $legacy = MenuPresentation::fromArray([
        'template_key' => 'modern',
        'theme' => ['primary' => '#0f766e'],
        'sections' => [['key' => 'contact', 'visible' => true, 'config' => ['show_whatsapp' => false]]],
    ]);

    expect($legacy->themeValue('layout'))->toBe('grid')
        ->and($legacy->themeValue('hero_style'))->toBe('plain')
        ->and($legacy->themeValue('colors_source'))->toBe('menu')
        ->and($legacy->sectionConfig('contact'))->toBe(['show_whatsapp' => false, 'show_phone' => true, 'show_email' => true]);

    foreach ([['layout' => 'masonry'], ['gradient_angle' => '45'], ['hero_style' => 'url(x)'], ['colors_source' => 'site']] as $theme) {
        expect(fn () => MenuPresentation::fromArray(['template_key' => 'minimal', 'theme' => $theme, 'sections' => [['key' => 'hero', 'visible' => true]]]))
            ->toThrow(MenuPresentationRejected::class, 'must be one of');
    }

    // The rejection carries a stable reason for the Manager to translate.
    try {
        MenuPresentation::fromArray(['template_key' => 'minimal', 'theme' => ['accent' => 'red'], 'sections' => [['key' => 'hero', 'visible' => true]]]);
    } catch (MenuPresentationRejected $e) {
        expect($e->reason)->toBe('invalid_colour')->and($e->context)->toBe(['setting' => 'accent']);
    }

    // Choosing a template applies its preset on top of its theme.
    expect(MenuPresentation::preset('luxury'))->toMatchArray(['hero_style' => 'gradient', 'font' => 'serif']);
});

it('draws the sections in the order the owner chose, with their settings', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner): void {
        $this->seedCatalog();
        $publisher = app(MenuPublisher::class);

        // Contact first, services before categories, categories as a list.
        $publisher->saveDraft([
            'template_key' => 'minimal',
            'theme' => ['layout' => 'compact', 'price_style' => 'badge'],
            'sections' => [
                ['key' => 'contact', 'visible' => true, 'config' => []],
                ['key' => 'all_services', 'visible' => true, 'config' => ['group_by' => 'none']],
                ['key' => 'categories', 'visible' => true, 'config' => ['layout' => 'list']],
                ['key' => 'hero', 'visible' => true, 'config' => []],
            ],
        ], $owner);
        $publisher->publish($owner);
    });

    $html = (string) $this->get("http://{$slug}.localhost:8000/list")->assertOk()->getContent();

    expect(maOrderOf($html, '<h2>Services</h2>'))->toBeGreaterThan(0)
        ->and(maOrderOf($html, '<h2>Services</h2>'))->toBeLessThan(maOrderOf($html, '<h2>Categories</h2>'))
        ->and(maOrderOf($html, '<h2>Categories</h2>'))->toBeLessThan(maOrderOf($html, '<header class="hero">'))
        ->and($html)->toContain('class="cats list"')
        ->and($html)->toContain('layout-compact')
        ->and($html)->toContain('price-badge')
        ->and($html)->toContain('Haircut');

    // Only validated values reach the stylesheet: no quote was escaped into it.
    expect(str_contains($html, '&quot;'))->toBeFalse();
});

it('refuses the editor to staff without menu.view and never writes a draft just by opening it', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $staff = $this->asCenter($center['tenant'], fn () => $this->staffWith([Permission::SettingsView], 'nomenu@alpha.test'));
    $viewer = $this->asCenter($center['tenant'], fn () => $this->staffWith([Permission::MenuView], 'menuview@alpha.test'));

    $this->asCenter($center['tenant'], function () use ($staff, $viewer, $slug): void {
        $this->actingAs($staff);
        $this->get("http://{$slug}.localhost:8000/manager/menu")->assertForbidden();
        $this->get("http://{$slug}.localhost:8000/manager/appearance/menu/preview")->assertForbidden();

        $this->actingAs($viewer);
        $this->get("http://{$slug}.localhost:8000/manager/menu")
            ->assertOk()
            ->assertSee(__('manager_appearance.menu.read_only'));

        expect(MenuVersion::query()->draft()->exists())->toBeFalse();

        // A viewer cannot save through the component either.
        Livewire::actingAs($viewer)->test(MenuDesigner::class)
            ->set('templateKey', 'luxury')
            ->call('saveDraft')
            ->assertSet('noticeTone', 'danger');

        expect(MenuVersion::query()->draft()->exists())->toBeFalse();
    });
});

it('edits, reorders, previews and publishes through the editor', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->seedCatalog();
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');
        Branch::query()->where('is_main', true)->firstOrFail()->forceFill(['phone' => '+9647501112222'])->save();

        $component = Livewire::actingAs($owner)->test(MenuDesigner::class)
            ->set('templateKey', 'luxury')
            // The preset arrives with the template.
            ->assertSet('theme.hero_style', 'gradient')
            ->call('moveSection', 'contact', 0);

        expect($component->get('sections')[0]['key'])->toBe('contact');

        $component->call('preview')->assertHasNoErrors()->assertDispatched('menu-preview');

        // The preview renders the DRAFT through the public template, in the
        // requested content language, marked noindex — and the live menu is
        // untouched.
        $this->actingAs($owner);
        $preview = $this->get("http://{$slug}.localhost:8000/manager/appearance/menu/preview?lang=ar")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('dir="rtl"', false)
            ->assertSee('hero-gradient', false)
            ->assertSee('noindex, nofollow', false)
            ->assertSee('قص شعر');

        expect(maOrderOf((string) $preview->getContent(), 'contact-links'))->toBeGreaterThan(0)
            ->and(maOrderOf((string) $preview->getContent(), 'contact-links'))->toBeLessThan(maOrderOf((string) $preview->getContent(), '<header class="hero">'));
        expect(MenuVersion::query()->published()->firstOrFail()->template_key)->toBe('minimal');

        $component->call('publish')->assertHasNoErrors()->assertSet('noticeTone', 'success');

        expect(MenuVersion::query()->published()->firstOrFail()->template_key)->toBe('luxury')
            ->and(MenuVersion::query()->published()->firstOrFail()->sections[0]['key'])->toBe('contact');
    });

    // The interface language was not changed by previewing in Arabic.
    expect(app()->getLocale())->toBe('en');
});

it('explains a rejected value in the viewer language and restores a version as a new one', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app()->setLocale('ar');

        Livewire::actingAs($owner)->test(MenuDesigner::class)
            ->set('theme.accent', 'javascript:alert(1)')
            ->call('saveDraft')
            ->assertHasErrors('theme')
            ->assertSee(__('manager_appearance.errors.invalid_colour', ['setting' => __('manager_appearance.menu.colours.accent')]))
            ->assertDontSee('six-digit hex');

        app()->setLocale('en');
        $publisher = app(MenuPublisher::class);
        $original = $publisher->published();
        $publisher->saveDraft(['template_key' => 'modern', 'sections' => [['key' => 'all_services', 'visible' => true]]], $owner);
        $publisher->publish($owner);

        Livewire::actingAs($owner)->test(MenuDesigner::class)
            ->call('rollback', $original->refresh()->uuid)
            ->assertSet('templateKey', 'minimal')
            ->assertSet('noticeTone', 'success');

        // Restored as a new version, and the draft follows it.
        expect(MenuVersion::query()->published()->firstOrFail()->template_key)->toBe('minimal')
            ->and(MenuVersion::query()->published()->firstOrFail()->version)->toBe(3)
            ->and(MenuVersion::query()->draft()->firstOrFail()->template_key)->toBe('minimal');
    });
});

it('renders the editor with no raw translation keys in every interface language', function (string $locale, string $title): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $locale, $title): void {
        $this->actingAs($owner);

        $html = (string) $this->get("http://{$slug}.localhost:8000/manager/menu?locale={$locale}")
            ->assertOk()
            ->assertSee($title)
            ->getContent();

        expect(preg_match('/\b(manager_appearance|menu_public)\.[a-z_]+/', strip_tags($html)))->toBe(0)
            ->and(str_contains(strip_tags($html), 'barber_dark'))->toBeFalse();
    });
})->with([
    'English' => ['en', 'Menu appearance'],
    'Arabic' => ['ar', 'مظهر القائمة'],
    'Kurdish Sorani' => ['ckb', 'شێوەی مینیو'],
]);

it('keeps the appearance, settings and public-page translations aligned and complete in EN, AR and KU', function (): void {
    foreach (['manager_appearance', 'manager_settings', 'menu_public'] as $group) {
        $english = array_keys(Arr::dot(require lang_path("en/{$group}.php")));

        foreach (['ar', 'ckb'] as $locale) {
            $translated = Arr::dot(require lang_path("{$locale}/{$group}.php"));

            expect(array_keys($translated))->toBe($english, "{$locale}/{$group}");

            foreach ($translated as $key => $value) {
                expect($value)->toBeString()->not->toBe('', "{$locale}.{$group}.{$key}");
            }
        }
    }

    // Every option the catalog offers has a label — no raw value can reach the editor.
    foreach (['en', 'ar', 'ckb'] as $locale) {
        app()->setLocale($locale);

        foreach ((array) config('menu.theme.options') as $option => $values) {
            foreach ($values as $value) {
                $key = "manager_appearance.menu.options.{$option}.values.{$value}";
                expect(__($key))->not->toBe($key);
            }
        }

        foreach ((array) config('menu.section_config') as $setting => $rule) {
            expect(__("manager_appearance.menu.settings.{$setting}.label"))->not->toBe("manager_appearance.menu.settings.{$setting}.label");
        }

        foreach (array_keys((array) config('menu.sections')) as $section) {
            expect(__("manager_appearance.menu.sections.{$section}.label"))->not->toBe("manager_appearance.menu.sections.{$section}.label");
        }
    }

    app()->setLocale('en');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
