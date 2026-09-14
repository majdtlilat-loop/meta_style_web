<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain\Pricing;

use App\Modules\Sales\Domain\Exceptions\SaleFailed;

/**
 * THE commercial calculation. Every total a sale or invoice carries comes from
 * here, and from nowhere else.
 *
 * Framework-free and integer-only: no catalog lookup, no database, no floats.
 * The browser may preview a total; it never supplies one (docs/18-SALES.md §9).
 *
 * ## The rules, all of them
 *
 *     line subtotal   = (unit price + add-ons per unit) × quantity
 *     subtotal        = Σ line subtotals
 *     percent amount  = round_half_up(subtotal × basis points ÷ 10 000)
 *     discount total  = Σ discount amounts          (must not exceed subtotal)
 *     surcharge total = Σ surcharge amounts
 *     tax total       = 0                           (a seam, not a guess)
 *     grand total     = subtotal − discount total + surcharge total + tax total
 *
 * Every percentage is taken from the SUBTOTAL, so two percentage discounts do
 * not compound and the order they were added in cannot change the answer.
 *
 * ## One rounding rule
 *
 * Half up, to one minor unit of the sale's currency — one dinar for IQD, one
 * cent for USD — computed as `intdiv(base × bp + 5000, 10000)` on non-negative
 * integers. Rounding a cash total to the smallest note in circulation is a
 * SETTLEMENT concern and belongs to Payment (Phase 10).
 *
 * ## Allocation
 *
 * The discount total is spread over the lines in proportion to their subtotals
 * by the LARGEST REMAINDER method: every line gets the floor of its exact share,
 * and the minor units left over go one each to the lines with the largest
 * remainders, earlier lines first on a tie. The shares therefore sum EXACTLY to
 * the discount total, every time, with no line ever receiving a fraction.
 *
 * ## Why a sale has a ceiling
 *
 * The allocation multiplies the discount by a line subtotal. Capping a sale's
 * subtotal at 3 000 000 000 minor units means that product is at most
 * 9 × 10¹⁸, which fits a signed 64-bit integer — so the arithmetic is provably
 * exact without floats and without a big-number dependency. That is 3 billion
 * dinars or 30 million dollars in one sale, which no salon till will meet.
 */
final class SalePricing
{
    public const MAX_SUBTOTAL_MINOR = 3_000_000_000;

    public const MAX_QUANTITY = 999;

    public const MAX_BASIS_POINTS = 10_000;

    /**
     * @param  list<LineInput>  $lines
     * @param  list<AdjustmentInput>  $adjustments
     *
     * @throws SaleFailed when the inputs break a rule a total depends on
     */
    public function calculate(array $lines, array $adjustments): SaleTotals
    {
        $subtotals = [];

        foreach ($lines as $line) {
            $this->assertLine($line);

            $subtotals[] = ($line->unitPriceMinor + $line->addonsUnitTotalMinor) * $line->quantity;
        }

        $subtotal = array_sum($subtotals);

        if ($subtotal > self::MAX_SUBTOTAL_MINOR) {
            throw SaleFailed::policy('That sale is larger than a single sale may be.', [
                'max_subtotal_minor' => self::MAX_SUBTOTAL_MINOR,
            ]);
        }

        $discount = 0;
        $surcharge = 0;
        $amounts = [];

        foreach ($adjustments as $adjustment) {
            $amount = $this->resolve($adjustment, $subtotal);
            $amounts[] = $amount;

            if ($adjustment->type->isDiscount()) {
                $discount += $amount;
            } else {
                $surcharge += $amount;
            }
        }

        /*
         * A discount may take a sale to zero and no further. Surcharges do not
         * create discountable value: "20% off, plus a delivery fee" must not
         * let the discount eat the fee and push the goods below nothing.
         */
        if ($discount > $subtotal) {
            throw SaleFailed::policy('Those discounts are larger than the sale.', [
                'subtotal_minor' => $subtotal,
                'discount_total_minor' => $discount,
            ]);
        }

        if ($surcharge > self::MAX_SUBTOTAL_MINOR) {
            throw SaleFailed::policy('Those surcharges are larger than a single sale may be.');
        }

        $allocations = self::allocate($discount, $subtotals);

        $priced = [];

        foreach ($subtotals as $index => $lineSubtotal) {
            $priced[] = new PricedLine(
                subtotalMinor: $lineSubtotal,
                discountAllocatedMinor: $allocations[$index],
                totalMinor: $lineSubtotal - $allocations[$index],
            );
        }

        $tax = 0;

        return new SaleTotals(
            subtotalMinor: $subtotal,
            discountTotalMinor: $discount,
            surchargeTotalMinor: $surcharge,
            taxTotalMinor: $tax,
            grandTotalMinor: $subtotal - $discount + $surcharge + $tax,
            lines: $priced,
            adjustmentAmounts: $amounts,
        );
    }

    /**
     * "12.5" → 1250. Parsed as a STRING, never through a float, with at most two
     * decimal places: `(int) (12.51 * 100)` is 1250 on some inputs, which is
     * exactly the kind of bug this class exists to rule out.
     *
     * @throws SaleFailed
     */
    public static function basisPoints(string $percent): int
    {
        $percent = trim($percent);

        if (preg_match('/^(\d{1,3})(?:\.(\d{1,2}))?$/', $percent, $parts) !== 1) {
            throw SaleFailed::policy('Enter a percentage like 10 or 12.5.');
        }

        $bp = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '0', 2, '0');

        if ($bp < 1 || $bp > self::MAX_BASIS_POINTS) {
            throw SaleFailed::policy('A percentage must be more than 0% and at most 100%.');
        }

        return $bp;
    }

    /**
     * 1250 → "12.5". Integer arithmetic for display, the inverse of
     * {@see basisPoints()}; a float here would print 12.499999 one day.
     */
    public static function percentLabel(int $basisPoints): string
    {
        $whole = intdiv($basisPoints, 100);
        $fraction = $basisPoints % 100;

        if ($fraction === 0) {
            return (string) $whole;
        }

        return $whole.'.'.rtrim(str_pad((string) $fraction, 2, '0', STR_PAD_LEFT), '0');
    }

    /**
     * `base × bp ÷ 10 000`, rounded half up to one minor unit.
     */
    public static function percentOf(int $baseMinor, int $basisPoints): int
    {
        if ($baseMinor < 0 || $basisPoints < 0) {
            throw SaleFailed::policy('A percentage is only ever taken of a non-negative amount.');
        }

        return intdiv($baseMinor * $basisPoints + 5_000, 10_000);
    }

    /**
     * Spreads `$amount` over `$weights` by the largest-remainder method.
     *
     * @param  list<int>  $weights  non-negative
     * @return list<int> one share per weight, summing exactly to `$amount`
     */
    public static function allocate(int $amount, array $weights): array
    {
        $shares = array_fill(0, count($weights), 0);
        $total = array_sum($weights);

        if ($amount === 0 || $total === 0) {
            return $shares;
        }

        if ($amount > $total) {
            throw SaleFailed::policy('Cannot allocate more than the lines are worth.');
        }

        $remainders = [];
        $given = 0;

        foreach ($weights as $index => $weight) {
            // Guarded rather than assumed: the subtotal ceiling is what keeps
            // this product inside a 64-bit integer, and if that ceiling is ever
            // raised without revisiting this, failing is right.
            if ($weight > 0 && $amount > intdiv(PHP_INT_MAX, $weight)) {
                throw SaleFailed::policy('That sale is too large to allocate exactly.');
            }

            $product = $amount * $weight;

            $shares[$index] = intdiv($product, $total);
            $remainders[$index] = $product % $total;
            $given += $shares[$index];
        }

        $left = $amount - $given;

        // Largest remainder first; the earlier line wins a tie, so the same
        // cart always allocates the same way.
        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => [$remainders[$b], $a] <=> [$remainders[$a], $b]);

        for ($i = 0; $i < $left; $i++) {
            $shares[$order[$i]]++;
        }

        return $shares;
    }

    private function assertLine(LineInput $line): void
    {
        if ($line->quantity < 1 || $line->quantity > self::MAX_QUANTITY) {
            throw SaleFailed::policy('A line quantity must be between 1 and '.self::MAX_QUANTITY.'.');
        }

        if ($line->unitPriceMinor < 0 || $line->addonsUnitTotalMinor < 0) {
            throw SaleFailed::policy('A price cannot be negative. Use a discount instead.');
        }

        if ($line->unitPriceMinor + $line->addonsUnitTotalMinor > self::MAX_SUBTOTAL_MINOR) {
            throw SaleFailed::policy('That price is larger than a single sale may be.');
        }
    }

    private function resolve(AdjustmentInput $adjustment, int $subtotal): int
    {
        if ($adjustment->type->isPercentage()) {
            $bp = $adjustment->basisPoints ?? 0;

            if ($bp < 1 || $bp > self::MAX_BASIS_POINTS) {
                throw SaleFailed::policy('A percentage must be more than 0% and at most 100%.');
            }

            return self::percentOf($subtotal, $bp);
        }

        if ($adjustment->amountMinor < 1 || $adjustment->amountMinor > self::MAX_SUBTOTAL_MINOR) {
            throw SaleFailed::policy('An adjustment amount must be positive and within the sale limit.');
        }

        return $adjustment->amountMinor;
    }
}
