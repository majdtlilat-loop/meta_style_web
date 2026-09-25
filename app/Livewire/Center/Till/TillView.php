<?php

declare(strict_types=1);

namespace App\Livewire\Center\Till;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\PosFinance\BranchTime;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use App\View\Label;

/**
 * Shapes what the Sales presenter returned into what the till prints.
 *
 * Labels, tones, the next and previous quantity for the stepper, local times.
 * It never computes an amount: every figure is a formatted string that came
 * back from `SalePricing` through the presenter or from a grouped query.
 */
final class TillView
{
    /**
     * @param  array<string, mixed>  $sale  SalesPresenter::sale()
     * @return array<string, mixed>
     */
    public static function cart(array $sale, string $timezone): array
    {
        $sale['status_label'] = self::statusLabel((string) $sale['status']);
        $sale['source_label'] = (string) __('manager_pos.sale_source.'.$sale['source']);
        $sale['issued_at_label'] = BranchTime::label($sale['finalized_at'] ?? null, $timezone, BranchTime::DATETIME_FULL);

        /** @var list<array<string, mixed>> $lines */
        $lines = $sale['lines'] ?? [];

        $sale['lines'] = array_map(static function (array $line): array {
            $quantity = (int) $line['quantity'];

            $line['next_quantity'] = min(SalePricing::MAX_QUANTITY, $quantity + 1);
            $line['previous_quantity'] = max(1, $quantity - 1);
            $line['can_decrease'] = $quantity > 1;
            $line['can_increase'] = $quantity < SalePricing::MAX_QUANTITY;
            // A visit line and a line carrying a benefit change elsewhere.
            $line['steppable'] = ! $line['from_visit'] && ! $line['has_benefit'] && $line['offering_type'] === null;
            $line['kind_label'] = (string) __('manager_pos.line_kind.'.$line['kind']);

            return $line;
        }, $lines);

        /** @var list<array<string, mixed>> $adjustments */
        $adjustments = $sale['adjustments'] ?? [];

        $sale['adjustments'] = array_map(static fn (array $adjustment): array => $adjustment + [
            'label' => self::adjustmentLabel($adjustment),
            'sign' => $adjustment['is_discount'] ? '−' : '+',
        ], $adjustments);

        return $sale;
    }

    /**
     * @param  list<array{stage: string, service: string, booked: array<string, mixed>|null, performed: string, charged: array<string, mixed>|null, chargeable: bool}>  $rows
     * @return list<array<string, mixed>>
     */
    public static function checkout(array $rows): array
    {
        return array_map(static fn (array $row): array => $row + [
            'performed_label' => Label::for('stage_status', $row['performed']),
        ], $rows);
    }

    /**
     * @param  array{sales: int, voided: int, totals: list<array{currency: string, grand_total_minor: int}>}|null  $summary
     * @return list<string>
     */
    public static function shiftTotals(?array $summary): array
    {
        if ($summary === null) {
            return [];
        }

        return array_map(
            static fn (array $total): string => Money::fromMinor($total['grand_total_minor'], Currency::tryFrom($total['currency']) ?? Currency::default())->formatted(),
            $summary['totals'],
        );
    }

    public static function statusLabel(string $status): string
    {
        return (string) __('manager_pos.sale_status.'.$status);
    }

    /**
     * @param  array<string, mixed>  $adjustment
     */
    private static function adjustmentLabel(array $adjustment): string
    {
        $label = match (true) {
            (bool) $adjustment['benefit'] => (string) __('manager_pos.adjustment.benefit'),
            (bool) $adjustment['is_discount'] => (string) __('Discount'),
            default => (string) __('Surcharge'),
        };

        return $adjustment['percent'] === null ? $label : $label.' ('.$adjustment['percent'].'%)';
    }
}
