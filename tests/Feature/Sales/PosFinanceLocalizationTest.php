<?php

declare(strict_types=1);

use App\Livewire\Center\PosFinance\Refusals;
use Illuminate\Support\Arr;

/*
|--------------------------------------------------------------------------
| The money screens speak the viewer's language
|--------------------------------------------------------------------------
|
| manager_pos and manager_finance exist in EN, AR and KU with exactly the same
| keys and no empty leaf; every key the screens name resolves in all three;
| and an Action's English refusal reaches an Arabic or Kurdish till in that
| language — the English sentence stays exactly what the Action said.
|
*/

it('keeps the money translation groups aligned and non-empty in every interface language', function (): void {
    foreach (['manager_pos', 'manager_finance'] as $group) {
        $english = Arr::dot(require lang_path("en/{$group}.php"));

        foreach (['ar', 'ckb'] as $locale) {
            $translated = Arr::dot(require lang_path("{$locale}/{$group}.php"));

            expect(array_keys($translated))->toBe(array_keys($english));

            foreach ($translated as $key => $value) {
                expect($value)->toBeString("{$locale}.{$group}.{$key}")->not->toBe('');
            }
        }
    }
});

it('resolves every static money key the screens use, in every interface language', function (): void {
    $keys = [];
    $roots = [
        app_path('Livewire/Center'),
        resource_path('views/livewire/center'),
    ];

    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            preg_match_all("/__\\(\\s*'(manager_(?:pos|finance)\\.[a-z0-9_.]+[a-z0-9_])'/", (string) file_get_contents($file->getPathname()), $matches);
            $keys = [...$keys, ...$matches[1]];
        }
    }

    expect($keys)->not->toBeEmpty();

    foreach (['en', 'ar', 'ckb'] as $locale) {
        app()->setLocale($locale);

        foreach (array_unique($keys) as $key) {
            expect(__($key))->toBeString("{$locale}: {$key}")->not->toBe($key);
        }
    }
});

it('translates an Action\'s refusal at the Livewire boundary and leaves English exact', function (): void {
    $sentence = 'That is more than is left to collect on this invoice.';

    app()->setLocale('en');
    expect(Refusals::text($sentence))->toBe($sentence)
        ->and(Refusals::text('Choose a range of at most 92 days.'))->toBe('Choose a range of at most 92 days.');

    app()->setLocale('ar');
    expect(Refusals::text($sentence))->toBe('هذا أكثر من المتبقي للتحصيل على هذه الفاتورة.')
        ->and(Refusals::text('Choose a range of at most 92 days.'))->toBe('اختر فترة لا تتجاوز 92 يومًا.')
        ->and(Refusals::text('That sale is voided and can no longer be changed.'))->toBe('لم يعد بالإمكان تعديل هذا البيع (الحالة: ملغاة).')
        // Unknown text is shown as it came: never an invented translation.
        ->and(Refusals::text('Something nobody translated.'))->toBe('Something nobody translated.');

    app()->setLocale('ckb');
    expect(Refusals::text($sentence))->toBe('ئەمە زیاترە لەوەی ماوە بۆ وەرگرتن لەسەر ئەم پسووڵەیە.')
        ->and(Refusals::text('A sale may carry at most 50 lines.'))->toContain('50');

    // Every catalogued refusal has its own key, and every key round-trips.
    app()->setLocale('en');

    foreach (require lang_path('en/manager_pos.php') as $section => $lines) {
        if ($section !== 'refusals') {
            continue;
        }

        foreach ($lines as $key => $english) {
            expect(Refusals::key($english))->toBe($key);
        }
    }
});
