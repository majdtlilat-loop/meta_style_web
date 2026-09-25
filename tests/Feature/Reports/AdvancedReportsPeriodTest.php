<?php

declare(strict_types=1);

use App\View\AdvancedReports\AdvancedPeriod;
use App\View\AdvancedReports\Series;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| Advanced Reports — periods and their comparison
|--------------------------------------------------------------------------
|
| The advanced presets resolve in the branch timezone and compare like for
| like (DateRange semantics: month presets against the same days of the
| previous month, custom against the equal preceding range), or against the
| same dates one year earlier. Never longer than 366 days, never past today.
|
*/

function advancedPeriod(string $preset, string $comparison = 'previous', ?string $from = null, ?string $to = null): AdvancedPeriod
{
    // Thursday 24 September 2026, 10:00 in Baghdad.
    return AdvancedPeriod::resolve($preset, $from, $to, $comparison, 'Asia/Baghdad', CarbonImmutable::parse('2026-09-24 10:00', 'Asia/Baghdad'));
}

it('resolves every advanced preset and its like-for-like comparison', function (string $preset, string $from, string $to, string $compareFrom, string $compareTo): void {
    $period = advancedPeriod($preset);

    expect($period->preset)->toBe($preset)
        ->and($period->from->toDateString())->toBe($from)
        ->and($period->to->toDateString())->toBe($to)
        ->and($period->compareFrom->toDateString())->toBe($compareFrom)
        ->and($period->compareTo->toDateString())->toBe($compareTo);
})->with([
    'today → yesterday' => ['today', '2026-09-24', '2026-09-24', '2026-09-23', '2026-09-23'],
    'yesterday → the day before' => ['yesterday', '2026-09-23', '2026-09-23', '2026-09-22', '2026-09-22'],
    'last 7 days → the 7 before' => ['last_7_days', '2026-09-18', '2026-09-24', '2026-09-11', '2026-09-17'],
    'last 30 days → the 30 before' => ['last_30_days', '2026-08-26', '2026-09-24', '2026-07-27', '2026-08-25'],
    'last 90 days → the 90 before' => ['last_90_days', '2026-06-27', '2026-09-24', '2026-03-29', '2026-06-26'],
    'this month → same days last month' => ['this_month', '2026-09-01', '2026-09-24', '2026-08-01', '2026-08-24'],
    'last month → the month before' => ['last_month', '2026-08-01', '2026-08-31', '2026-07-01', '2026-07-31'],
    'this quarter → same number of days of last quarter' => ['this_quarter', '2026-07-01', '2026-09-24', '2026-04-01', '2026-06-25'],
    'this year → same days last year' => ['this_year', '2026-01-01', '2026-09-24', '2025-01-01', '2025-09-24'],
]);

it('compares with the same dates one year earlier on request', function (): void {
    $period = advancedPeriod('last_30_days', 'last_year');

    expect($period->comparison)->toBe('last_year')
        ->and($period->compareFrom->toDateString())->toBe('2025-08-26')
        ->and($period->compareTo->toDateString())->toBe('2025-09-24');
});

it('keeps a custom range inside today and 366 days, compared with the equal preceding range', function (): void {
    $swapped = advancedPeriod('custom', 'previous', '2026-09-10', '2026-09-01');
    $future = advancedPeriod('custom', 'previous', '2026-09-20', '2026-12-31');
    $long = advancedPeriod('custom', 'previous', '2024-01-01', '2026-09-24');
    $broken = advancedPeriod('custom', 'previous', 'not-a-date', null);

    expect([$swapped->from->toDateString(), $swapped->to->toDateString()])->toBe(['2026-09-01', '2026-09-10'])
        ->and([$swapped->compareFrom->toDateString(), $swapped->compareTo->toDateString()])->toBe(['2026-08-22', '2026-08-31'])
        ->and($future->to->toDateString())->toBe('2026-09-24')
        ->and($long->days())->toBe(366)
        ->and($broken->preset)->toBe('this_month')
        ->and(advancedPeriod('bogus')->preset)->toBe('this_month')
        ->and(advancedPeriod('today', 'bogus')->comparison)->toBe('previous');
});

it('draws hours for a day, days to two months, weeks to half a year and months beyond', function (): void {
    expect(advancedPeriod('today')->buckets('en'))->toHaveCount(24)
        ->and(advancedPeriod('last_30_days')->buckets('en'))->toHaveCount(30)
        ->and(advancedPeriod('last_90_days')->buckets('en'))->toHaveCount(13)
        ->and(advancedPeriod('this_year')->buckets('en'))->toHaveCount(9);

    // The comparison buckets follow the current granularity, position by position.
    $month = advancedPeriod('this_month');
    expect($month->comparisonBuckets('en'))->toHaveCount(24)
        ->and($month->comparisonBuckets('en')[0]['from'])->toBe('2026-08-01');
});

it('lays branch-local facts onto the buckets and a day-of-week grid, never inventing a value', function (): void {
    $week = advancedPeriod('last_90_days')->buckets('en');
    $values = Series::onto(['2026-06-27' => 2, '2026-07-03' => 1, '2026-07-04' => 5, '2026-09-24' => 3], [], $week);

    expect($values[0])->toBe(3)
        ->and($values[1])->toBe(5)
        ->and(array_sum($values))->toBe(11);

    // Saturday 19 September at 09:00 and 11:00, Friday 18 September at 10:00.
    $grid = Series::heatmap(['2026-09-19 09' => 2, '2026-09-19 11' => 1, '2026-09-18 10' => 4], 'en');

    expect($grid['columns'])->toBe(['09', '10', '11'])
        ->and($grid['rows'][0])->toBe('Sat')
        ->and($grid['values'][0])->toBe([2, 0, 1])
        ->and($grid['values'][6])->toBe([0, 4, 0])
        ->and(Series::heatmap([], 'en'))->toBeNull()
        ->and(Series::rate(1, 0))->toBeNull()
        ->and(Series::rate(1, 3))->toBe(33.3);
});
