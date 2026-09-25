<?php

declare(strict_types=1);

use App\Kernel\Money\Currency;
use App\Modules\Payments\Domain\Data\InvoiceSettlementSummary;
use App\Modules\Payments\Domain\Enums\SettlementState;
use App\Modules\Payments\Domain\ProviderAmount;

/*
|--------------------------------------------------------------------------
| Money at the provider boundary, and the settlement formula
|--------------------------------------------------------------------------
|
| docs/19-PAYMENTS.md §§8–9, 28, 81. No float anywhere; an amount that is not
| exact is refused, not rounded.
|
*/

it('converts provider amounts to minor units exactly, both ways', function (): void {
    expect(ProviderAmount::toMinor('500.00', Currency::IQD))->toBe(500)
        ->and(ProviderAmount::toMinor('500', Currency::IQD))->toBe(500)
        ->and(ProviderAmount::toMinor(500, Currency::IQD))->toBe(500)
        ->and(ProviderAmount::toMinor('12.34', Currency::USD))->toBe(1234)
        ->and(ProviderAmount::toMinor(12, Currency::USD))->toBe(1200)
        ->and(ProviderAmount::toDecimalString(500, Currency::IQD))->toBe('500.00')
        ->and(ProviderAmount::toDecimalString(1234, Currency::USD))->toBe('12.34')
        ->and(ProviderAmount::toDecimalString(5, Currency::USD))->toBe('0.05');
});

it('refuses anything that is not an exact amount in the currency', function (mixed $value): void {
    expect(fn () => ProviderAmount::toMinor($value, Currency::IQD))->toThrow(InvalidArgumentException::class);
})->with([
    'a float' => [500.0],
    'a fraction of a dinar' => ['500.50'],
    'negative' => ['-5'],
    'text' => ['five hundred'],
    'null' => [null],
    'exponent' => ['5e2'],
]);

it('derives unpaid, partial and paid from GROSS collection, never reopened by refunds', function (): void {
    $unpaid = InvoiceSettlementSummary::of(total: 100, succeeded: 0, pending: 0, refunded: 0, pendingRefund: 0, voided: false, currency: 'IQD');
    $reserved = InvoiceSettlementSummary::of(total: 100, succeeded: 30, pending: 70, refunded: 0, pendingRefund: 0, voided: false, currency: 'IQD');
    $refunded = InvoiceSettlementSummary::of(total: 100, succeeded: 100, pending: 0, refunded: 30, pendingRefund: 0, voided: false, currency: 'IQD');
    $voided = InvoiceSettlementSummary::of(total: 100, succeeded: 0, pending: 0, refunded: 0, pendingRefund: 0, voided: true, currency: 'IQD');

    expect($unpaid->state)->toBe(SettlementState::Unpaid)
        ->and($unpaid->availableCollectibleMinor)->toBe(100)
        ->and($reserved->state)->toBe(SettlementState::Partial)
        ->and($reserved->availableCollectibleMinor)->toBe(0)
        // Paid 100, refunded 30: still PAID, net 70, nothing to collect again.
        ->and($refunded->state)->toBe(SettlementState::Paid)
        ->and($refunded->netCollectedMinor)->toBe(70)
        ->and($refunded->availableCollectibleMinor)->toBe(0)
        ->and($voided->availableCollectibleMinor)->toBe(0);
});
