<?php

declare(strict_types=1);

namespace App\Modules\Sales\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Sales\Domain\Models\CashierShift;
use App\Modules\Sales\Domain\Models\Invoice;
use App\Modules\Sales\Domain\Models\Sale;
use App\Modules\Sales\Domain\Models\SaleAdjustment;
use App\Modules\Sales\Domain\Models\SaleItem;
use App\Modules\Sales\Domain\Models\SaleItemAddon;
use App\Modules\Sales\Domain\Pricing\SalePricing;

/**
 * The STAFF view of a sale. Explicit fields only.
 *
 * Staff-facing, so it carries what a till needs that a customer's copy does not:
 * the original price behind an override, who overrode it and why, line notes,
 * the void reason. It still never emits a numeric id, and a customer's phone
 * number is not a till's business — the name is enough to pick the right
 * person (docs/18-SALES.md §25).
 *
 * Callers eager-load what they pass in. A presenter that lazy-loads is an N+1
 * that only shows up on a real day's sales list.
 */
final class SalesPresenter
{
    public function __construct(
        private readonly InvoiceRenderer $renderer,
        private readonly InvoiceLinks $links,
        private readonly LineEmployees $employees,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sale(Sale $sale, User $viewer, bool $withLines = true): array
    {
        $locale = app()->getLocale();

        $data = [
            'uuid' => $sale->uuid,
            'status' => $sale->status->value,
            'source' => $sale->source->value,
            'branch' => $sale->relationLoaded('branch') ? $sale->branch?->uuid : null,
            'customer' => $sale->relationLoaded('customer') && $sale->customer !== null
                ? ['uuid' => $sale->customer->uuid, 'name' => $sale->customer->name]
                : null,
            'journey' => $sale->relationLoaded('journey') ? $sale->journey?->uuid : null,
            'currency' => $sale->currency,
            'subtotal' => $sale->money($sale->subtotal_minor)->toArray($locale),
            'discount_total' => $sale->money($sale->discount_total_minor)->toArray($locale),
            'surcharge_total' => $sale->money($sale->surcharge_total_minor)->toArray($locale),
            'tax_total' => $sale->money($sale->tax_total_minor)->toArray($locale),
            'grand_total' => $sale->money($sale->grand_total_minor)->toArray($locale),
            'created_by' => $sale->created_by_label,
            'created_at' => $sale->created_at?->toIso8601String(),
            'finalized_at' => $sale->finalized_at?->toIso8601String(),
            'finalized_by' => $sale->finalized_by_label,
            'voided_at' => $sale->voided_at?->toIso8601String(),
            'voided_by' => $sale->voided_by_label,
            'void_reason' => $sale->void_reason,
            'invoice' => $sale->relationLoaded('invoice') && $sale->invoice instanceof Invoice
                ? $this->invoiceSummary($sale->invoice, $viewer)
                : null,
        ];

        if ($withLines) {
            // Which line each benefit rests on, by the line's uuid — no ids.
            $lineUuids = [];

            foreach ($sale->items as $item) {
                $lineUuids[(int) $item->getKey()] = $item->uuid;
            }

            $benefitLines = array_flip(array_filter(array_map(
                static fn (SaleAdjustment $a): ?int => $a->sale_item_id,
                $sale->adjustments->all(),
            )));

            // Who performed each service line — staff-facing only; the
            // customer's invoice never names staff. One query for the sale.
            $employees = $this->employees->named(array_values($sale->items->map(static fn (SaleItem $item): ?int => $item->employee_id)->all()));

            $data['lines'] = array_values($sale->items->map(fn (SaleItem $item): array => $this->line($sale, $item) + [
                'has_benefit' => isset($benefitLines[(int) $item->getKey()]),
                'employee' => $item->employee_id === null ? null : ($employees[$item->employee_id] ?? null),
            ])->all());
            $data['adjustments'] = array_values($sale->adjustments->map(fn (SaleAdjustment $a): array => [
                'uuid' => $a->uuid,
                'type' => $a->type->value,
                // A benefit (points, a member's price, a package session) is a
                // discount too; only a surcharge adds to the bill.
                'is_discount' => $a->type->isDiscount(),
                'benefit' => $a->type->isBenefit(),
                'line' => $a->sale_item_id === null ? null : ($lineUuids[$a->sale_item_id] ?? null),
                'basis_points' => $a->basis_points,
                'percent' => $a->basis_points === null ? null : SalePricing::percentLabel($a->basis_points),
                'amount' => $sale->money($a->amount_minor)->toArray($locale),
                'reason' => $a->reason,
                'by' => $a->created_by_label,
            ])->all());
        }

        return $data;
    }

    /**
     * What the till can sell besides the catalog — memberships, packages —
     * as other modules offer them. Opaque type and reference; Sales prices
     * the line from the offering when it is added, never from this list.
     *
     * @param  list<array{type: string, reference: string, name: TranslatedText, unit_price_minor: int}>  $offerings
     * @return list<array<string, mixed>>
     */
    public function offerings(array $offerings): array
    {
        $locale = app()->getLocale();

        return array_map(static fn (array $offering): array => [
            'type' => $offering['type'],
            'reference' => $offering['reference'],
            'name' => $offering['name']->get($locale),
            'unit_price' => Money::fromMinor($offering['unit_price_minor'], Currency::default())->toArray($locale),
        ], $offerings);
    }

    /**
     * @return array<string, mixed>
     */
    public function line(Sale $sale, SaleItem $item): array
    {
        $locale = app()->getLocale();

        return [
            'uuid' => $item->uuid,
            'kind' => $item->kind->value,
            'name' => $item->name->get($locale),
            'variation' => $item->variation_name?->get($locale),
            'addons' => array_values($item->addons->map(fn (SaleItemAddon $addon): array => [
                'name' => $addon->name->get($locale),
                'unit_price' => $sale->money($addon->unit_price_minor)->toArray($locale),
            ])->all()),
            'quantity' => $item->quantity,
            'from_visit' => $item->journey_stage_id !== null,
            // For an offering line: which catalog sold it (a membership, a
            // package). Opaque here; its module explains it.
            'offering_type' => $item->offering_type,
            'price_source' => $item->price_source->value,
            'original_unit_price' => $sale->money($item->original_unit_price_minor)->toArray($locale),
            'unit_price' => $sale->money($item->unit_price_minor)->toArray($locale),
            'overridden' => $item->isOverridden(),
            'override_reason' => $item->price_override_reason,
            'overridden_by' => $item->price_overridden_by_label,
            'addons_unit_total' => $sale->money($item->addons_unit_total_minor)->toArray($locale),
            'line_subtotal' => $sale->money($item->line_subtotal_minor)->toArray($locale),
            'discount_allocated' => $sale->money($item->discount_allocated_minor)->toArray($locale),
            'line_total' => $sale->money($item->line_total_minor)->toArray($locale),
            'note' => $item->note,
        ];
    }

    /**
     * `share_url` exists only in the response to the call that MINTED the link —
     * finalization or rotation — because the secret is not stored anywhere it
     * could be read back from. Every other read says whether a live link exists,
     * so a screen can offer to issue a new one (docs/18-SALES.md §20).
     *
     * @return array<string, mixed>
     */
    public function invoiceSummary(Invoice $invoice, User $viewer, #[\SensitiveParameter] ?string $freshSecret = null): array
    {
        return [
            'uuid' => $invoice->uuid,
            'number' => $invoice->number,
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'grand_total' => $invoice->money($invoice->grand_total_minor)->toArray(app()->getLocale()),
            'share_link_active' => $invoice->relationLoaded('activeLink') ? $invoice->activeLink !== null : null,
            // Shown to the people who issue invoices, so they can hand it over.
            'share_url' => $freshSecret !== null && $viewer->hasPermission(Permission::SaleFinalize)
                ? $this->links->url($freshSecret)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function invoice(Invoice $invoice, Sale $sale, User $viewer, #[\SensitiveParameter] ?string $freshSecret = null): array
    {
        return [
            'uuid' => $invoice->uuid,
            'sale' => $sale->uuid,
            'document' => $this->renderer->document(
                $invoice,
                null,
                $sale->status,
                $sale->voided_at?->toImmutable(),
            ),
        ] + ['summary' => $this->invoiceSummary($invoice, $viewer, $freshSecret)];
    }

    /**
     * @param  array<string, mixed>|null  $summary
     * @return array<string, mixed>
     */
    public function shift(CashierShift $shift, ?array $summary = null): array
    {
        return [
            'uuid' => $shift->uuid,
            'status' => $shift->status->value,
            'branch' => $shift->relationLoaded('branch') ? $shift->branch?->uuid : null,
            'cashier' => $shift->relationLoaded('user') ? $shift->user?->name : null,
            'opening_cash_minor' => $shift->opening_cash_minor,
            'opened_at' => $shift->opened_at->toIso8601String(),
            'closed_at' => $shift->closed_at?->toIso8601String(),
            'opening_note' => $shift->opening_note,
            'closing_note' => $shift->closing_note,
            'closed_by' => $shift->closed_by_label,
            'summary' => $summary,
        ];
    }
}
