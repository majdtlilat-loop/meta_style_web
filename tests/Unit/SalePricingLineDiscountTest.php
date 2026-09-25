<?php

declare(strict_types=1);

use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Pricing\AdjustmentInput;
use App\Modules\Sales\Domain\Pricing\LineInput;
use App\Modules\Sales\Domain\Pricing\SalePricing;

/*
|--------------------------------------------------------------------------
| Line-targeted discounts
|--------------------------------------------------------------------------
|
| docs/18-SALES.md §12, docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §4.
|
| A benefit that belongs to one line — a package session, a member's price — is
| a fixed discount on that line alone, never larger than it. Sale-wide
| percentages are then taken from what is still payable, and sale-wide
| discounts spread over what each line still owes. With no targeted discount
| every answer is exactly Phase 9's.
|
*/

function slLine(int $unit, int $quantity = 1): LineInput
{
    return new LineInput(unitPriceMinor: $unit, addonsUnitTotalMinor: 0, quantity: $quantity);
}

function slOnLine(int $line, int $amount, AdjustmentType $type = AdjustmentType::BenefitDiscount): AdjustmentInput
{
    return new AdjustmentInput($type, null, $amount, $line);
}

it('puts a line-targeted discount on that line alone', function (): void {
    $totals = (new SalePricing)->calculate([slLine(20000), slLine(12000)], [slOnLine(0, 20000)]);

    expect($totals->discountTotalMinor)->toBe(20000)
        ->and($totals->grandTotalMinor)->toBe(12000)
        ->and($totals->lines[0]->discountAllocatedMinor)->toBe(20000)
        ->and($totals->lines[0]->totalMinor)->toBe(0)
        ->and($totals->lines[1]->discountAllocatedMinor)->toBe(0)
        ->and($totals->adjustmentAmounts)->toBe([20000]);
});

it('never lets line-targeted discounts exceed their line', function (): void {
    expect(fn () => (new SalePricing)->calculate([slLine(20000), slLine(12000)], [slOnLine(1, 12001)]))
        ->toThrow(SaleFailed::class, 'larger than the line it applies to');

    // Two on one line are bounded together.
    expect(fn () => (new SalePricing)->calculate([slLine(20000, 2)], [slOnLine(0, 20000), slOnLine(0, 20001)]))
        ->toThrow(SaleFailed::class, 'larger than the line it applies to');

    $both = (new SalePricing)->calculate([slLine(20000, 2)], [slOnLine(0, 20000), slOnLine(0, 20000)]);

    expect($both->grandTotalMinor)->toBe(0);
});

it('takes a sale-wide percentage only from what the targeted discounts left payable', function (): void {
    // 20 000 covered by a package; 10% of the remaining 12 000 is 1 200 —
    // not 3 200, which would discount the covered service a second time.
    $totals = (new SalePricing)->calculate(
        [slLine(20000), slLine(12000)],
        [slOnLine(0, 20000), new AdjustmentInput(AdjustmentType::DiscountPercent, 1000, 0)],
    );

    expect($totals->adjustmentAmounts)->toBe([20000, 1200])
        ->and($totals->discountTotalMinor)->toBe(21200)
        ->and($totals->grandTotalMinor)->toBe(10800)
        // The sale-wide part lands only on what the line still owed.
        ->and($totals->lines[0]->discountAllocatedMinor)->toBe(20000)
        ->and($totals->lines[1]->discountAllocatedMinor)->toBe(1200);
});

it('spreads sale-wide discounts over what each line still owes, summing exactly', function (): void {
    // Line 0 owes 10 000 after its benefit, line 1 owes 20 000: a 3 000 fixed
    // discount splits 1 000 / 2 000.
    $totals = (new SalePricing)->calculate(
        [slLine(20000), slLine(20000)],
        [slOnLine(0, 10000), new AdjustmentInput(AdjustmentType::DiscountFixed, null, 3000)],
    );

    expect($totals->lines[0]->discountAllocatedMinor)->toBe(11000)
        ->and($totals->lines[1]->discountAllocatedMinor)->toBe(2000)
        ->and($totals->lines[0]->discountAllocatedMinor + $totals->lines[1]->discountAllocatedMinor)->toBe($totals->discountTotalMinor)
        ->and($totals->grandTotalMinor)->toBe(27000);
});

it('refuses sale-wide discounts larger than what the targeted discounts left', function (): void {
    expect(fn () => (new SalePricing)->calculate(
        [slLine(20000), slLine(12000)],
        [slOnLine(0, 20000), new AdjustmentInput(AdjustmentType::DiscountFixed, null, 12001)],
    ))->toThrow(SaleFailed::class, 'larger than the sale');
});

it('lets only a fixed discount target a line, and only a line on the sale', function (): void {
    expect(fn () => (new SalePricing)->calculate([slLine(20000)], [new AdjustmentInput(AdjustmentType::DiscountPercent, 1000, 0, 0)]))
        ->toThrow(SaleFailed::class, 'Only a fixed discount can belong to a single line.');

    expect(fn () => (new SalePricing)->calculate([slLine(20000)], [new AdjustmentInput(AdjustmentType::SurchargeFixed, null, 1000, 0)]))
        ->toThrow(SaleFailed::class, 'Only a fixed discount can belong to a single line.');

    expect(fn () => (new SalePricing)->calculate([slLine(20000)], [slOnLine(1, 1000)]))
        ->toThrow(SaleFailed::class, 'not on this sale');
});

it('prices exactly as Phase 9 when nothing targets a line', function (): void {
    $lines = [slLine(25000, 2), slLine(12000), slLine(333)];
    $adjustments = [
        new AdjustmentInput(AdjustmentType::DiscountPercent, 1250, 0),
        new AdjustmentInput(AdjustmentType::DiscountFixed, null, 777),
        new AdjustmentInput(AdjustmentType::SurchargeFixed, null, 1500),
    ];

    $totals = (new SalePricing)->calculate($lines, $adjustments);

    // 62 333 subtotal; 12.5% = 7 791.625 → 7 792; + 777 = 8 569 discount.
    expect($totals->subtotalMinor)->toBe(62333)
        ->and($totals->adjustmentAmounts)->toBe([7792, 777, 1500])
        ->and($totals->discountTotalMinor)->toBe(8569)
        ->and($totals->grandTotalMinor)->toBe(62333 - 8569 + 1500)
        ->and(array_sum(array_map(static fn ($line): int => $line->discountAllocatedMinor, $totals->lines)))->toBe(8569)
        ->and(array_map(static fn ($line): int => $line->discountAllocatedMinor, $totals->lines))
        ->toBe(SalePricing::allocate(8569, [50000, 12000, 333]));
});
