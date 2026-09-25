<?php

declare(strict_types=1);

namespace App\Livewire\Center\PosFinance;

use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\View\StatusTone;

/**
 * The words and tones the money screens put next to stored states.
 *
 * One vocabulary for the till, the invoice panel, receipts, expenses and the
 * finance overview: `manual_electronic` is "Transfer" everywhere, a succeeded
 * payment is "Paid", and a raw value never reaches a page. Presentation only —
 * the enums and Actions decide what a state means.
 */
final class MoneyLabels
{
    public static function method(?string $method): string
    {
        return self::from('manager_pos.method.', $method);
    }

    public static function paymentStatus(?string $status): string
    {
        return self::from('manager_pos.payment_status.', $status);
    }

    public static function refundStatus(?string $status): string
    {
        return self::from('manager_pos.refund_status.', $status);
    }

    public static function settlement(?string $state): string
    {
        return self::from('manager_pos.settlement.', $state);
    }

    public static function tone(?string $state): string
    {
        return match ($state) {
            'succeeded', 'paid', 'posted' => 'success',
            'pending', 'partial' => 'warning',
            'failed', 'voided', 'unpaid' => 'danger',
            default => StatusTone::for($state),
        };
    }

    /**
     * "25,000 IQD · 12 USD" — per-currency totals the module already added up,
     * listed side by side and never added together here.
     *
     * @param  list<array{currency: string, total_minor: int}>  $rows
     */
    public static function amounts(array $rows): string
    {
        if ($rows === []) {
            return Money::zero(Currency::default())->formatted();
        }

        return implode(' · ', array_map(
            static fn (array $row): string => Money::fromMinor($row['total_minor'], Currency::tryFrom($row['currency']) ?? Currency::default())->formatted(),
            $rows,
        ));
    }

    private static function from(string $prefix, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $key = $prefix.$value;
        $label = __($key);

        return $label === $key ? $value : (string) $label;
    }
}
