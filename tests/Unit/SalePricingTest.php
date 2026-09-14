<?php

declare(strict_types=1);

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Pricing\AdjustmentInput;
use App\Modules\Sales\Domain\Pricing\LineInput;
use App\Modules\Sales\Domain\Pricing\SalePricing;

/*
|--------------------------------------------------------------------------
| The commercial calculation
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §§9–12.
|
| Every total a sale or invoice carries comes from `SalePricing` — integers only,
| one rounding rule, discounts allocated so the shares sum exactly. A unit test:
| no database, no container, nothing but arithmetic.
|
*/

function spPricing(): SalePricing
{
    return new SalePricing;
}

function spLine(int $unit, int $quantity = 1, int $addons = 0): LineInput
{
    return new LineInput(unitPriceMinor: $unit, addonsUnitTotalMinor: $addons, quantity: $quantity);
}

function spPercent(int $bp): AdjustmentInput
{
    return new AdjustmentInput(AdjustmentType::DiscountPercent, $bp, 0);
}

function spFixed(int $amount, AdjustmentType $type = AdjustmentType::DiscountFixed): AdjustmentInput
{
    return new AdjustmentInput($type, null, $amount);
}

it('prices a line as unit plus add-ons, times quantity', function (): void {
    $totals = spPricing()->calculate([spLine(25000, 2, 5000), spLine(12000)], []);

    expect($totals->lines[0]->subtotalMinor)->toBe(60000)
        ->and($totals->lines[1]->subtotalMinor)->toBe(12000)
        ->and($totals->subtotalMinor)->toBe(72000)
        ->and($totals->grandTotalMinor)->toBe(72000)
        ->and($totals->taxTotalMinor)->toBe(0);
});

it('rounds a percentage half up to one minor unit, and only one way', function (): void {
    // 12 345 × 12.5% = 1 543.125 → 1 543
    expect(SalePricing::percentOf(12345, 1250))->toBe(1543)
        // 15 × 33.33% = 4.9995 → 5
        ->and(SalePricing::percentOf(15, 3333))->toBe(5)
        // exactly half: 1 × 50% = 0.5 → 1 (half UP, never banker's rounding)
        ->and(SalePricing::percentOf(1, 5000))->toBe(1)
        // 3 × 50% = 1.5 → 2
        ->and(SalePricing::percentOf(3, 5000))->toBe(2)
        // just under half: 49 × 1% = 0.49 → 0
        ->and(SalePricing::percentOf(49, 100))->toBe(0)
        ->and(SalePricing::percentOf(20000, 10000))->toBe(20000);
});

it('takes every percentage from the subtotal, so order cannot change the answer', function (): void {
    $a = spPricing()->calculate([spLine(20000)], [spPercent(1000), spPercent(1000)]);
    $b = spPricing()->calculate([spLine(20000)], [spPercent(1000), spFixed(500), spPercent(1000)]);

    // Two 10% discounts on 20 000 are 4 000 — not 3 800, which compounding gives.
    expect($a->discountTotalMinor)->toBe(4000)
        ->and($a->grandTotalMinor)->toBe(16000)
        ->and($b->discountTotalMinor)->toBe(4500)
        ->and($b->adjustmentAmounts)->toBe([2000, 500, 2000]);
});

it('adds surcharges and subtracts discounts', function (): void {
    $totals = spPricing()->calculate([spLine(20000)], [
        spFixed(3000),
        spFixed(1500, AdjustmentType::SurchargeFixed),
    ]);

    expect($totals->discountTotalMinor)->toBe(3000)
        ->and($totals->surchargeTotalMinor)->toBe(1500)
        ->and($totals->grandTotalMinor)->toBe(18500);
});

it('lets a discount take a sale to zero and never below it', function (): void {
    expect(spPricing()->calculate([spLine(20000)], [spFixed(20000)])->grandTotalMinor)->toBe(0);

    expect(fn () => spPricing()->calculate([spLine(20000)], [spFixed(20001)]))
        ->toThrow(SaleFailed::class, 'larger than the sale');

    // A surcharge does not create discountable value.
    expect(fn () => spPricing()->calculate([spLine(20000)], [
        spFixed(5000, AdjustmentType::SurchargeFixed),
        spFixed(22000),
    ]))->toThrow(SaleFailed::class, 'larger than the sale');
});

it('allocates a discount so the shares sum exactly, earlier lines winning a tie', function (): void {
    // 100 over three equal lines: 33.33… each. The spare unit goes to line 0.
    expect(SalePricing::allocate(100, [1000, 1000, 1000]))->toBe([34, 33, 33]);

    // Proportional: 1 000 over 3 000 / 1 000 / 1 000.
    expect(SalePricing::allocate(1000, [3000, 1000, 1000]))->toBe([600, 200, 200]);

    // Largest remainder, not position, decides who gets the spare units.
    // 10 over 7/2/1: exact 7, 2, 1.
    expect(SalePricing::allocate(10, [7, 2, 1]))->toBe([7, 2, 1]);
    // 5 over 1/1/1/1/3: 0.71… ×4, 2.14… → floors 0,0,0,0,2 → the four .71s get +1 first.
    expect(SalePricing::allocate(5, [1, 1, 1, 1, 3]))->toBe([1, 1, 1, 0, 2]);
});

it('stores the allocation on every line, summing to the discount total', function (): void {
    $totals = spPricing()->calculate([spLine(12345), spLine(6789, 3), spLine(1)], [spPercent(1750), spFixed(333)]);

    $allocated = array_sum(array_map(static fn ($l): int => $l->discountAllocatedMinor, $totals->lines));
    $lineTotals = array_sum(array_map(static fn ($l): int => $l->totalMinor, $totals->lines));

    expect($allocated)->toBe($totals->discountTotalMinor)
        ->and($lineTotals)->toBe($totals->subtotalMinor - $totals->discountTotalMinor);

    foreach ($totals->lines as $priced) {
        expect($priced->totalMinor)->toBeGreaterThanOrEqual(0);
    }
});

it('handles an empty cart and a zero-priced line', function (): void {
    $empty = spPricing()->calculate([], []);
    $free = spPricing()->calculate([spLine(0)], []);

    expect($empty->grandTotalMinor)->toBe(0)
        ->and($empty->lines)->toBe([])
        ->and($free->grandTotalMinor)->toBe(0)
        ->and(SalePricing::allocate(0, [0]))->toBe([0]);
});

it('refuses quantities, prices and percentages outside their bounds', function (): void {
    expect(fn () => spPricing()->calculate([spLine(100, 0)], []))->toThrow(SaleFailed::class, 'quantity');
    expect(fn () => spPricing()->calculate([spLine(100, 1000)], []))->toThrow(SaleFailed::class, 'quantity');
    expect(fn () => spPricing()->calculate([spLine(-1)], []))->toThrow(SaleFailed::class, 'negative');
    expect(fn () => spPricing()->calculate([spLine(100)], [spPercent(0)]))->toThrow(SaleFailed::class, 'percentage');
    expect(fn () => spPricing()->calculate([spLine(100)], [spPercent(10001)]))->toThrow(SaleFailed::class, 'percentage');
    expect(fn () => spPricing()->calculate([spLine(100)], [spFixed(0)]))->toThrow(SaleFailed::class, 'positive');
});

it('caps one sale so every multiplication provably fits a 64-bit integer', function (): void {
    $max = SalePricing::MAX_SUBTOTAL_MINOR;

    // At the ceiling, the allocation's largest product still fits.
    expect($max * $max)->toBeLessThan(PHP_INT_MAX)
        ->and(SalePricing::allocate($max, [$max - 1, 1]))->toBe([$max - 1, 1]);

    expect(fn () => spPricing()->calculate([spLine($max), spLine(1)], []))
        ->toThrow(SaleFailed::class, 'larger than a single sale');
});

it('parses a typed percentage as a string, never through a float', function (): void {
    expect(SalePricing::basisPoints('12.5'))->toBe(1250)
        ->and(SalePricing::basisPoints('12.51'))->toBe(1251)
        ->and(SalePricing::basisPoints('0.01'))->toBe(1)
        ->and(SalePricing::basisPoints('100'))->toBe(10000)
        ->and(SalePricing::percentLabel(1250))->toBe('12.5')
        ->and(SalePricing::percentLabel(1205))->toBe('12.05')
        ->and(SalePricing::percentLabel(1000))->toBe('10');

    foreach (['0', '100.01', '12.345', 'ten', '-5', '1e2', ''] as $bad) {
        expect(fn () => SalePricing::basisPoints($bad))->toThrow(SaleFailed::class);
    }
});

it('is currency-agnostic integers: IQD dinars and USD cents price the same way', function (): void {
    // 25 000 IQD is the integer 25000; $250.00 is the integer 25000 too.
    $iqd = spPricing()->calculate([spLine(Money::fromMajorString('25000', Currency::IQD)->minor)], [spPercent(1000)]);
    $usd = spPricing()->calculate([spLine(Money::fromMajorString('250.00', Currency::USD)->minor)], [spPercent(1000)]);

    expect($iqd->grandTotalMinor)->toBe(22500)
        ->and($usd->grandTotalMinor)->toBe(22500)
        ->and(Money::fromMinor($iqd->grandTotalMinor, Currency::IQD)->formatted('en'))->toBe('22,500 IQD')
        ->and(Money::fromMinor($usd->grandTotalMinor, Currency::USD)->formatted('en'))->toBe('225.00 $');
});
