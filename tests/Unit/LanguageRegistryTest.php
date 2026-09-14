<?php

declare(strict_types=1);

use App\Kernel\Localization\LanguageRegistry;
use Illuminate\Config\Repository;

/*
|--------------------------------------------------------------------------
| Language registry
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md §6. Direction is a property of the language, read
| from the registry — never a hardcoded list of RTL locales in application
| code, which is the anti-pattern this class exists to prevent.
|
*/

function registry(): LanguageRegistry
{
    return new LanguageRegistry(new Repository([
        'localization' => require dirname(__DIR__, 2).'/config/localization.php',
    ]));
}

it('reports direction for each launch language', function (string $locale, string $direction): void {
    expect(registry()->direction($locale))->toBe($direction);
})->with([
    ['en', 'ltr'],
    ['ar', 'rtl'],
    ['ckb', 'rtl'],
]);

it('treats Kurdish Sorani as ckb, not ku', function (): void {
    // `ku` is the macrolanguage and reads as Kurmanji — Latin script, LTR.
    // Using it would give the wrong direction and the wrong font stack.
    expect(registry()->supports('ckb'))->toBeTrue()
        ->and(registry()->supports('ku'))->toBeFalse()
        ->and(registry()->isRtl('ckb'))->toBeTrue();
});

it('falls back to ltr for an unknown language rather than failing', function (): void {
    expect(registry()->direction('xx'))->toBe('ltr')
        ->and(registry()->supports('xx'))->toBeFalse();
});

it('exposes the native name, falling back to the code', function (): void {
    expect(registry()->nativeName('ar'))->toBe('العربية')
        ->and(registry()->nativeName('xx'))->toBe('xx');
});

it('lists the launch languages', function (): void {
    expect(registry()->supported())->toBe(['en', 'ar', 'ckb']);
});
