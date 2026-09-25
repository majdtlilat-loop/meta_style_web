<?php

declare(strict_types=1);

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Tenancy\PlatformHosts;

/*
|--------------------------------------------------------------------------
| Localization
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md.
|
| Three launch languages, two of them right-to-left, and a schema that must
| accept a fourth without a migration. Direction is a property of the language,
| never a hardcoded list of locale codes.
|
*/

it('knows the direction of all three launch languages', function (): void {
    $languages = app(LanguageRegistry::class);

    expect($languages->direction('en'))->toBe('ltr')
        ->and($languages->direction('ar'))->toBe('rtl')
        // Kurdish Sorani is `ckb`, not `ku`. `ku` is the macrolanguage and is
        // usually read as Kurmanji — Latin script, LTR — which would give the
        // wrong direction and the wrong font.
        ->and($languages->direction('ckb'))->toBe('rtl')
        ->and($languages->isRtl('ckb'))->toBeTrue()
        ->and($languages->nativeName('ckb'))->toBe('کوردی سۆرانی');
});

it('accepts a new language without touching any schema', function (): void {
    // A center asking for Turkish is a config change, not a migration across
    // every tenant database — that is the whole point of JSON translatable
    // columns (docs/07-LOCALIZATION.md §2).
    config()->set('localization.languages.tr', [
        'name_native' => 'Türkçe',
        'name_en' => 'Turkish',
        'direction' => 'ltr',
    ]);

    $languages = app(LanguageRegistry::class);

    expect($languages->supports('tr'))->toBeTrue()
        ->and($languages->direction('tr'))->toBe('ltr');

    $text = TranslatedText::fromArray(['en' => 'Haircut', 'tr' => 'Saç kesimi']);

    expect($text->get('tr'))->toBe('Saç kesimi');
});

it('shows a name in the language being read when the center publishes in it, and never a switched-off one', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $name = TranslatedText::fromArray(['en' => 'Manager', 'ar' => 'المدير']);
        app()->setLocale('en');

        // An English-speaking manager of an Arabic-first center that also
        // publishes English reads English — not the center's default.
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'ar');
        expect($name->get())->toBe('Manager')
            // An explicit locale still wins over everything.
            ->and($name->get('ar'))->toBe('المدير');

        // Once English is switched off, the translation left behind never
        // outranks the default (disabling deletes nothing, docs/07 §3).
        app(TenantLocales::class)->setEnabled(['ar', 'ckb'], 'ar');
        expect($name->get())->toBe('المدير');
    });
});

it('gives a new center only the language it registered in', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $locales = app(TenantLocales::class);
        $locales->forget();

        // Enabling all three for everyone would put empty Kurdish and Arabic
        // fields on every form of a center that will only use English.
        expect($locales->enabled())->toBe(['en'])
            ->and($locales->default())->toBe('en');
    });
});

it('lets a center choose which languages it enables', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $locales = app(TenantLocales::class);

        $locales->setEnabled(['ar', 'ckb'], 'ar');

        expect($locales->enabled())->toBe(['ar', 'ckb'])
            ->and($locales->default())->toBe('ar')
            ->and($locales->isEnabled('en'))->toBeFalse();
    });
});

it('forces the default locale to be one of the enabled ones', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $locales = app(TenantLocales::class);

        // Otherwise the fallback chain terminates at a language the center has
        // switched off, and every field renders empty.
        $locales->setEnabled(['ar'], 'ckb');

        expect($locales->enabled())->toContain('ckb')
            ->and($locales->default())->toBe('ckb');
    });
});

it('ignores a language the platform does not know', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $locales = app(TenantLocales::class);

        $locales->setEnabled(['en', 'klingon'], 'en');

        expect($locales->enabled())->toBe(['en']);
    });
});

it('falls back silently for a locale the center has not enabled', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $locales = app(TenantLocales::class);

        $locales->setEnabled(['ar'], 'ar');

        // A customer whose phone is set to French should see the menu in
        // Arabic, not a 404 (docs/07-LOCALIZATION.md §5).
        expect($locales->resolve('fr'))->toBe('ar')
            ->and($locales->resolve('en'))->toBe('ar')
            ->and($locales->resolve('ar'))->toBe('ar')
            ->and($locales->resolve(null))->toBe('ar');
    });
});

it('never renders empty when some translation exists', function (): void {
    $text = TranslatedText::fromArray(['ar' => 'قص شعر']);

    // A blank service name in a menu is worse than the wrong language.
    expect($text->get('en'))->toBe('قص شعر')
        ->and($text->get('ckb'))->toBe('قص شعر')
        ->and($text->isEmpty())->toBeFalse();

    expect((new TranslatedText)->get('en'))->toBe('');
});

it('keeps translations for a language the center switches off', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $locales = app(TenantLocales::class);
        $locales->setEnabled(['en', 'ar'], 'en');

        $catalog = $this->seedCatalog();

        $locales->setEnabled(['en'], 'en');

        // Disabling hides a language; it must never delete what was written.
        // Re-enabling brings it back intact.
        expect($locales->isEnabled('ar'))->toBeFalse()
            ->and($catalog['service']->refresh()->name->in('ar'))->toBe('قص شعر');

        $locales->setEnabled(['en', 'ar'], 'en');

        expect($catalog['service']->refresh()->name->get('ar'))->toBe('قص شعر');
    });
});

it('resolves the request locale from the query string', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar', 'ckb'], 'en');
        $this->publishMenu();
        $this->seedCatalog();
    });

    $slug = (string) $center['registration']->requested_slug;

    $url = app(PlatformHosts::class)->centerUrl($slug, "/api/v1/menu/{$slug}");

    $this->getJson($url.'?locale=ar')
        ->assertOk()
        ->assertJsonPath('data.center.locale', 'ar')
        ->assertJsonPath('data.center.direction', 'rtl')
        ->assertJsonPath('data.services.0.name', 'قص شعر');

    $this->getJson($url.'?locale=en')
        ->assertOk()
        ->assertJsonPath('data.center.direction', 'ltr')
        ->assertJsonPath('data.services.0.name', 'Haircut');
});

it('negotiates the locale from Accept-Language when none is asked for', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        app(TenantLocales::class)->setEnabled(['en', 'ar'], 'en');
        $this->publishMenu();
        $this->seedCatalog();
    });

    $slug = (string) $center['registration']->requested_slug;

    // `ar-IQ` must narrow to `ar` — a hand-rolled header parser is exactly
    // where that goes wrong.
    $url = app(PlatformHosts::class)->centerUrl($slug, "/api/v1/menu/{$slug}");

    $this->withHeaders(['Accept-Language' => 'ar-IQ,ar;q=0.9,en;q=0.5'])
        ->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.center.locale', 'ar');

    // A language the center has not enabled falls back to its default.
    $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])
        ->getJson($url)
        ->assertOk()
        ->assertJsonPath('data.center.locale', 'en');
});

it('advertises the center\'s enabled languages on the public menu', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        app(TenantLocales::class)->setEnabled(['ar', 'ckb'], 'ar');
        $this->publishMenu();
        $this->seedCatalog();
    });

    $body = $this->getJson(app(PlatformHosts::class)->centerUrl(
        (string) $center['registration']->requested_slug,
        '/api/v1/menu/'.(string) $center['registration']->requested_slug,
    ))
        ->assertOk()
        ->json('data.center.locales');

    // A language switcher must offer what the center has, not all three.
    expect($body)->toHaveCount(2)
        ->and(array_column($body, 'code'))->toBe(['ar', 'ckb'])
        ->and(array_column($body, 'direction'))->toBe(['rtl', 'rtl']);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
