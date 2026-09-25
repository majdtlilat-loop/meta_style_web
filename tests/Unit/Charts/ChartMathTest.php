<?php

declare(strict_types=1);

use App\View\Charts\Arc;
use App\View\Charts\Axis;
use App\View\Charts\Change;
use App\View\Charts\DonutChart;
use App\View\Charts\Fold;
use App\View\Charts\Heatmap;
use App\View\Charts\Palette;
use App\View\Charts\PeriodAlign;
use App\View\Charts\Scale;

/*
 * The pure geometry and arithmetic behind the chart components
 * (docs/31-MANAGER-CHARTS.md): no container, no database.
 */

describe('semantic change', function (): void {
    it('tones a fall as GOOD for a metric where lower is better', function (): void {
        $change = Change::between(8, 12, higherIsBetter: false);

        expect($change->direction)->toBe('down')
            ->and($change->tone)->toBe('good')
            ->and($change->percent)->toBe(-33.3333)
            ->and($change->difference)->toBe(-4);
    });

    it('tones a rise as BAD for a metric where lower is better', function (): void {
        $change = Change::between(15, 12, higherIsBetter: false);

        expect($change->direction)->toBe('up')
            ->and($change->tone)->toBe('bad');
    });

    it('tones a rise as good only when higher is better', function (): void {
        expect(Change::between(12, 10, true)->tone)->toBe('good')
            ->and(Change::between(8, 10, true)->tone)->toBe('bad');
    });

    it('never judges a neutral metric', function (): void {
        expect(Change::between(20, 10, null)->tone)->toBe('neutral')
            ->and(Change::between(5, 10, null)->tone)->toBe('neutral');
    });

    it('gives no percentage from a zero base, only the absolute difference', function (): void {
        $change = Change::between(3, 0, true);

        expect($change->percent)->toBeNull()
            ->and($change->difference)->toBe(3)
            ->and($change->direction)->toBe('up')
            ->and($change->tone)->toBe('good');
    });

    it('says flat and neutral when nothing moved, and nothing at all without a previous value', function (): void {
        $flat = Change::between(7, 7, true);
        $none = Change::between(7, null, true);

        expect($flat->direction)->toBe('flat')
            ->and($flat->tone)->toBe('neutral')
            ->and($flat->percent)->toBe(0.0)
            ->and(Change::between(0, 0, true)->percent)->toBeNull()
            ->and($none->direction)->toBe('none')
            ->and($none->percent)->toBeNull()
            ->and($none->difference)->toBeNull();
    });

    it('measures a fall from a negative base against its magnitude', function (): void {
        $change = Change::between(-50, -100, true);

        expect($change->direction)->toBe('up')
            ->and($change->percent)->toBe(50.0)
            ->and($change->tone)->toBe('good');
    });
});

describe('Other folding', function (): void {
    it('keeps the largest items and folds the rest into Other, keeping every member', function (): void {
        $items = array_map(static fn (int $i): array => ['label' => "S{$i}", 'value' => $i], range(1, 10));

        $folded = Fold::top($items, 7, 'Other');

        expect($folded)->toHaveCount(8)
            ->and(array_column($folded, 'label'))->toBe(['S10', 'S9', 'S8', 'S7', 'S6', 'S5', 'S4', 'Other'])
            ->and($folded[7]['other'])->toBeTrue()
            ->and($folded[7]['value'])->toBe(6)
            ->and(array_column($folded[7]['members'], 'label'))->toBe(['S3', 'S2', 'S1'])
            ->and($folded[0]['other'])->toBeFalse();
    });

    it('does not fold when everything fits', function (): void {
        $items = [['label' => 'A', 'value' => 1], ['label' => 'B', 'value' => 2]];

        expect(Fold::top($items, 7, 'Other'))->toHaveCount(2)
            ->and(array_column(Fold::top($items, 7, 'Other', sort: false), 'label'))->toBe(['A', 'B']);
    });

    it('sums the previous values of the folded members only when all of them have one', function (): void {
        $with = Fold::top([
            ['label' => 'A', 'value' => 9, 'previous' => 1],
            ['label' => 'B', 'value' => 2, 'previous' => 3],
            ['label' => 'C', 'value' => 1, 'previous' => 4],
        ], 1, 'Other');
        $without = Fold::top([
            ['label' => 'A', 'value' => 9, 'previous' => 1],
            ['label' => 'B', 'value' => 2, 'previous' => 3],
            ['label' => 'C', 'value' => 1],
        ], 1, 'Other');

        expect($with[1]['previous'])->toBe(7)
            ->and($without[1])->not->toHaveKey('previous');
    });

    it('folds a ninth series bucket by bucket', function (): void {
        $series = array_map(static fn (int $i): array => ['label' => "S{$i}", 'values' => [$i, 1]], range(1, 10));

        $folded = Fold::series($series, Palette::SLOTS, 'Other');

        expect($folded)->toHaveCount(9)
            ->and($folded[8]['label'])->toBe('Other')
            ->and($folded[8]['values'])->toBe([19, 2])
            ->and(array_column($folded[8]['members'], 'label'))->toBe(['S9', 'S10']);
    });
});

describe('scales, geometry and alignment', function (): void {
    it('keeps whole-number headroom and round ticks', function (): void {
        expect(Scale::nice(3)['ticks'])->toBe([0.0, 1.0, 2.0, 3.0, 4.0])
            ->and(Scale::nice(870)['max'])->toBe(1000.0)
            ->and(Scale::percent(87.5)['ticks'])->toBe([0.0, 25.0, 50.0, 75.0, 100.0])
            ->and(Scale::percent(140)['max'])->toBeGreaterThan(140.0);
    });

    it('puts zero on the axis of a signed range', function (): void {
        $scale = Scale::range(-30, 90);

        expect($scale['min'])->toBeLessThanOrEqual(-30.0)
            ->and($scale['max'])->toBeGreaterThanOrEqual(90.0)
            ->and($scale['ticks'])->toContain(0.0)
            ->and(Scale::position(0, $scale['min'], $scale['max']))->toBeGreaterThan(0.0);
    });

    it('draws a donut slice per non-zero value, a full ring for a single one', function (): void {
        $slices = Arc::donut([3, 0, 1]);
        $ring = Arc::donut([0, 5]);

        expect($slices)->toHaveCount(3)
            ->and($slices[0]['d'])->toStartWith('M')
            ->and($slices[1]['d'])->toBeNull()
            ->and($slices[0]['share'])->toBe(0.75)
            ->and($ring[1]['d'])->toContain('A48 48 0 1 1')
            ->and(Arc::donut([0, 0])[0]['d'])->toBeNull();
    });

    it('leaves a slice too thin to show beside its gaps without a path', function (): void {
        $slices = Arc::donut([10000, 1]);

        expect($slices[0]['d'])->not->toBeNull()
            ->and($slices[1]['d'])->toBeNull()
            ->and($slices[1]['share'])->toBeGreaterThan(0.0);
    });

    it('aligns a previous period by position and by offset, null where it has no bucket', function (): void {
        expect(PeriodAlign::byPosition([5, 6], 3))->toBe([5, 6, null])
            ->and(PeriodAlign::byPosition([5, 6, 7, 8], 2))->toBe([5, 6])
            ->and(PeriodAlign::byOffset(['2026-09-01', '2026-09-02'], ['2025-09-01' => 4], '1 year'))->toBe([4, null])
            ->and(PeriodAlign::byOffset(['2026-09-24 10'], ['2026-09-23 10' => 2], '1 day', 'Y-m-d H'))->toBe([2])
            ->and(PeriodAlign::labels([['label' => '1 Aug'], '2 Aug'], 3))->toBe(['1 Aug', '2 Aug', null]);
    });

    it('thins axis labels instead of shrinking them', function (): void {
        $ticks = Axis::ticks(array_map(static fn (int $d): string => "{$d} Sep", range(1, 30)));

        expect(count(array_filter($ticks, static fn (array $t): bool => $t['show'])))->toBeLessThanOrEqual(8)
            ->and($ticks[0]['show'])->toBeTrue()
            ->and(Axis::labels(['a', ['label' => 'b'], 7]))->toBe(['a', 'b', '7']);
    });

    it('steps a heatmap cell from none, never lightest-for-zero', function (): void {
        expect(Heatmap::step(0, 10))->toBe(0)
            ->and(Heatmap::step(null, 10))->toBe(0)
            ->and(Heatmap::step(0.1, 10))->toBe(1)
            ->and(Heatmap::step(10, 10))->toBe(5)
            ->and(Heatmap::step(6, 10))->toBe(3);
    });

    it('prints shares without inventing precision', function (): void {
        expect(DonutChart::share(0.4))->toBe('<1%')
            ->and(DonutChart::share(8.94))->toBe('8.9%')
            ->and(DonutChart::share(42.6))->toBe('43%')
            ->and(DonutChart::share(0.0))->toBe('0%');
    });
});

describe('palette roles', function (): void {
    it('assigns categorical slots in order and never cycles past eight', function (): void {
        expect(Palette::slot(0))->toBe('1')
            ->and(Palette::slot(7))->toBe('8')
            ->and(Palette::slot(8))->toBe('other');
    });

    it('maps booking and payment statuses to distinct reserved tones', function (): void {
        $booking = array_map(Palette::statusTone(...), ['completed', 'confirmed', 'booked', 'no_show', 'cancelled']);

        expect($booking)->toBe(['good', 'info', 'neutral', 'warning', 'critical'])
            ->and(Palette::statusTone('succeeded'))->toBe('good')
            ->and(Palette::statusTone('failed'))->toBe('critical')
            ->and(Palette::statusTone('something-new'))->toBe('neutral');
    });

    it('gives a second status of the same tone a lighter step instead of the same colour', function (): void {
        expect(Palette::statusSeries(['critical', 'good', 'critical', 'critical']))->toBe(['critical', 'good', 'critical-2', 'neutral-2']);
    });

    it('accepts an explicit colour only from the documented roles', function (): void {
        expect(Palette::resolve(3, '1'))->toBe('3')
            ->and(Palette::resolve('other', '1'))->toBe('other')
            ->and(Palette::resolve(9, '2'))->toBe('2')
            ->and(Palette::resolve('#ff0000', '2'))->toBe('2');
    });
});
