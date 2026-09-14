<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Time\BranchClock;
use App\Modules\Sales\Domain\Enums\AdjustmentType;
use App\Modules\Sales\Domain\Enums\SaleStatus;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\InvoiceItem;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use Carbon\CarbonImmutable;

/**
 * The customer-safe view of an invoice, for every surface that shows one: the
 * digital invoice, the 80mm printout, the A4 printout.
 *
 * ## One view model, three layouts
 *
 * A receipt is not a second financial record. The 80mm paper, the A4 page and
 * the phone screen are three LAYOUTS of this one array, which is built only from
 * the invoice's own immutable snapshots — never from a sale line, the catalog
 * or the branch as it is today (docs/18-SALES.md §§21–22).
 *
 * ## An ALLOW-LIST
 *
 * Every field emitted is named below. No ids, no uuids of anything internal, no
 * staff names, no notes, no void reason, no cost, no visit or resource data, no
 * phone or email of the customer. A deny-list would be defeated by the next
 * column somebody adds (CLAUDE.md, §§27, 54).
 *
 * Whether the sale was VOIDED is the one live fact, read from the sale because
 * the invoice row can never be updated to say so.
 */
final class InvoiceRenderer
{
    public function __construct(private readonly LanguageRegistry $languages) {}

    /**
     * @return array<string, mixed>
     */
    public function document(Invoice $invoice, ?string $locale = null, ?SaleStatus $saleStatus = null, ?CarbonImmutable $voidedAt = null): array
    {
        $locale ??= app()->getLocale();

        $invoice->loadMissing('items');

        $issued = BranchClock::toLocal($invoice->issued_at->toImmutable()->utc(), $invoice->issued_timezone);

        return [
            'number' => $invoice->number,
            'issued_date' => $issued->format('Y-m-d'),
            'issued_time' => $issued->format('H:i'),

            'center_name' => $invoice->center_name,
            'branch_name' => $invoice->branch_name->get($locale),
            'branch_address' => $this->text($invoice->branch_address, $locale),
            'branch_phone' => $invoice->branch_phone,

            'customer_name' => $invoice->customer_name,

            'currency' => $invoice->currency,
            'lines' => array_values($invoice->items->map(
                fn (InvoiceItem $item): array => $this->line($invoice, $item, $locale),
            )->all()),

            'adjustments' => array_map(fn (array $adjustment): array => [
                'kind' => AdjustmentType::from($adjustment['type'])->isDiscount() ? 'discount' : 'surcharge',
                'percent' => $adjustment['basis_points'] === null ? null : SalePricing::percentLabel($adjustment['basis_points']),
                // Discounts print as negative amounts; the stored value never is.
                'amount' => $invoice->money(
                    AdjustmentType::from($adjustment['type'])->isDiscount()
                        ? -$adjustment['amount_minor']
                        : $adjustment['amount_minor'],
                )->toArray($locale),
            ], $invoice->adjustments),

            'subtotal' => $invoice->money($invoice->subtotal_minor)->toArray($locale),
            'discount_total' => $invoice->money($invoice->discount_total_minor)->toArray($locale),
            'surcharge_total' => $invoice->money($invoice->surcharge_total_minor)->toArray($locale),
            'tax_total' => $invoice->money($invoice->tax_total_minor)->toArray($locale),
            'grand_total' => $invoice->money($invoice->grand_total_minor)->toArray($locale),

            'voided' => $saleStatus === SaleStatus::Voided,
            'voided_date' => $saleStatus === SaleStatus::Voided && $voidedAt instanceof CarbonImmutable
                ? BranchClock::toLocal($voidedAt->utc(), $invoice->issued_timezone)->format('Y-m-d')
                : null,

            'locale' => $locale,
            'direction' => $this->languages->direction($locale),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Invoice $invoice, InvoiceItem $item, string $locale): array
    {
        return [
            'name' => $item->name->get($locale),
            'variation' => $this->text($item->variation_name, $locale),
            'addons' => array_map(fn (array $addon): array => [
                'name' => TranslatedText::fromArray($addon['name'])->get($locale),
                'unit_price' => $invoice->money($addon['unit_price_minor'])->toArray($locale),
            ], $item->addons),
            'quantity' => $item->quantity,
            'unit_price' => $invoice->money($item->unit_price_minor + $item->addons_unit_total_minor)->toArray($locale),
            'line_subtotal' => $invoice->money($item->line_subtotal_minor)->toArray($locale),
        ];
    }

    private function text(?TranslatedText $text, string $locale): ?string
    {
        if (! $text instanceof TranslatedText || $text->isEmpty()) {
            return null;
        }

        return $text->get($locale);
    }
}
