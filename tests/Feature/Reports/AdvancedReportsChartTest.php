<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/*
|--------------------------------------------------------------------------
| <x-chart.multiline> — the trend overlay added for Advanced comparisons
|--------------------------------------------------------------------------
|
| 2–4 series on one time axis, one unit: a legend, one crosshair tooltip per
| bucket listing every series, each stroke on its own categorical token, a
| data table with every value, the empty state when there is nothing to draw
| and "Other" past four series (docs/31-MANAGER-CHARTS.md).
|
*/

it('overlays entities on one time axis with every value in its table', function (string $locale): void {
    app()->setLocale($locale);

    $html = Blade::render('<x-chart.multiline label="Bookings" :buckets="$buckets" :series="$series" />', [
        'buckets' => [['label' => '1 Sep'], ['label' => '2 Sep'], ['label' => '3 Sep']],
        'series' => [
            ['label' => 'Downtown', 'values' => [3, 17, null]],
            ['label' => 'Riverside', 'values' => [0, 4, 9]],
        ],
    ]);
    $table = substr($html, (int) strpos($html, '<details class="chart__data">'));

    expect($html)->toContain('class="chart chart--kit chart--line chart--multiline"')
        ->toContain('class="chart__line" data-series="1" style="stroke: var(--mark)"')
        ->toContain('class="chart__line" data-series="2" style="stroke: var(--mark)"')
        ->toContain('class="chart__key chart__key--line" data-series="2"')
        ->toContain('data-chart-target tabindex="0"')
        ->toContain('role="img"')
        // Tokens only: the component source carries no colour of its own.
        ->and(preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) file_get_contents(resource_path('views/components/chart/multiline.blade.php'))))->toBe(0);

    foreach (['1 Sep', '2 Sep', '3 Sep', 'Downtown', 'Riverside', '17', '—', '9', '20', '13'] as $value) {
        expect($table)->toContain(e($value));
    }

    // One tooltip per bucket names every series.
    expect(substr_count($html, 'data-tip-rows'))->toBe(3);
})->with(['en', 'ar', 'ckb']);

it('shows the empty state for nothing, and folds a fifth series into Other', function (): void {
    $empty = Blade::render('<x-chart.multiline label="Visits" :buckets="$buckets" :series="$series" />', [
        'buckets' => ['A', 'B'],
        'series' => [['label' => 'One', 'values' => [0, 0]], ['label' => 'Two', 'values' => [null, 0]]],
    ]);

    expect($empty)->toContain('chart__empty')
        ->not->toContain('<figure');

    $folded = Blade::render('<x-chart.multiline label="Visits" :buckets="$buckets" :series="$series" />', [
        'buckets' => ['A', 'B'],
        'series' => array_map(static fn (int $i): array => ['label' => 'Entity '.$i, 'values' => [$i, $i + 1]], range(1, 5)),
    ]);

    expect($folded)->toContain('data-series="other"')
        ->toContain(e(__('manager_charts.other')))
        ->toContain('Entity 3')
        ->not->toContain('data-series="4"');
});
