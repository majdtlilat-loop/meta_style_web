<?php

declare(strict_types=1);

use App\View\Charts\Change;
use App\View\Charts\KpiFigure;
use App\View\Charts\ValueFormat;

/*
 * How chart values print (docs/31-MANAGER-CHARTS.md): money from integer
 * minor units through Kernel\Money — IQD with NO decimals — percent points,
 * durations with translated units, and the semantic delta label.
 */

it('prints IQD with no decimals and USD with two, from minor units', function (): void {
    app()->setLocale('en');

    expect(ValueFormat::make('money', 'IQD')->full(25000))->toBe('25,000 IQD')
        ->and(ValueFormat::make('money', 'USD')->full(1250))->toBe('12.50 $')
        ->and(ValueFormat::make(null, 'IQD')->full(1500000))->toBe('1,500,000 IQD')
        ->and(ValueFormat::make('money', 'KWD')->full(1500))->toBe('1.500 KWD');
});

it('uses the locale symbol for money in Arabic and Kurdish', function (string $locale): void {
    app()->setLocale($locale);

    expect(ValueFormat::make('money', 'IQD')->full(25000))->toBe('25,000 د.ع');
})->with(['ar', 'ckb']);

it('compacts money ticks in major units without a symbol', function (): void {
    expect(ValueFormat::make('money', 'IQD', 'en')->tick(250000))->toBe('250K')
        ->and(ValueFormat::make('money', 'USD', 'en')->tick(1250000))->toBe('12.5K')
        ->and(ValueFormat::make('money', 'IQD', 'en')->short(1250000))->toBe('1.3M IQD')
        ->and(ValueFormat::make('money', 'IQD', 'en')->short(45000))->toBe('45,000 IQD');
});

it('prints numbers, percent points and durations', function (): void {
    app()->setLocale('en');

    expect(ValueFormat::make()->full(1284))->toBe('1,284')
        ->and(ValueFormat::make()->full(12.46))->toBe('12.5')
        ->and(ValueFormat::make('compact')->full(12900))->toBe('12.9K')
        ->and(ValueFormat::make('percent')->full(87.5))->toBe('87.5%')
        ->and(ValueFormat::make('percent')->full(40))->toBe('40%')
        ->and(ValueFormat::make('duration')->full(95))->toBe('1 h 35 min')
        ->and(ValueFormat::make('duration')->full(45))->toBe('45 min')
        ->and(ValueFormat::make('seconds')->full(200))->toBe('3 min 20 s')
        ->and(ValueFormat::make('seconds')->full(42))->toBe('42 s')
        ->and(ValueFormat::make()->full(null))->toBe('—');
});

it('translates duration units', function (): void {
    app()->setLocale('ar');
    expect(ValueFormat::make('duration')->full(95))->toBe('1 ساعة 35 دقيقة');

    app()->setLocale('ckb');
    expect(ValueFormat::make('duration')->full(45))->toBe('45 خولەک');
});

it('refuses an unknown format rather than guessing', function (): void {
    ValueFormat::make('currency');
})->throws(InvalidArgumentException::class);

it('labels a change: percent only from a non-zero base, points for a percent metric', function (): void {
    app()->setLocale('en');

    expect(Change::between(120, 100, true)->label())->toBe('+20%')
        ->and(Change::between(80, 100, false)->label())->toBe('−20%')
        ->and(Change::between(3, 0, true)->label())->toBe('+3')
        ->and(Change::between(25000, 0, true)->label(ValueFormat::make('money', 'IQD')))->toBe('+25,000 IQD')
        ->and(Change::between(8, 12, false)->label(ValueFormat::make('percent')))->toBe('−4 pts')
        ->and(Change::between(5, 5, true)->label())->toBe(__('ui.delta.no_change'))
        ->and(Change::between(5, null, true)->label())->toBe('')
        ->and(Change::between(300, 100, true)->label())->toBe('+200%');
});

it('bridges to the existing KPI delta with the same semantic direction', function (): void {
    app()->setLocale('en');
    $delta = Change::between(8, 12, false)->toDelta();

    expect($delta?->tone)->toBe('positive')
        ->and($delta?->direction)->toBe('down')
        ->and(Change::between(8, null, false)->toDelta())->toBeNull();
});

it('builds a KPI whose tone follows the metric, not the arrow', function (): void {
    app()->setLocale('en');

    $cancellations = KpiFigure::build(8, 12, 'percent', null, false, null, null);
    $bookings = KpiFigure::build(80, 100, null, null, true, null, 'vs previous period');
    $fresh = KpiFigure::build(4, 0, null, null, true, null, null);

    expect($cancellations['delta']['tone'])->toBe('good')
        ->and($cancellations['delta']['text'])->toBe('−4 pts')
        ->and($cancellations['value'])->toBe('8%')
        ->and($cancellations['compare'])->toBe('vs 12%')
        ->and($bookings['delta']['tone'])->toBe('bad')
        ->and($bookings['delta']['text'])->toBe('−20%')
        ->and($bookings['compare'])->toBe('vs previous period: 100')
        ->and($fresh['delta']['text'])->toBe('+4')
        ->and(KpiFigure::build(4, null, null, null, true, null, null)['delta'])->toBeNull();
});
