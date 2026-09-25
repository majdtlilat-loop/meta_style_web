<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Application;

use App\Modules\SaasBilling\Domain\Models\SaasInvoice;
use App\Modules\SaasBilling\Domain\Models\SaasPayment;
use Illuminate\Support\Carbon;

/**
 * A center's SaaS account with Meta Style over a period: what Meta Style
 * billed the center and what the center paid, never the center's own sales or
 * finance.
 *
 * Every line is derived from a real record — an invoice being issued or
 * voided, a settlement being received or reversed — and dated when that
 * happened. Amounts are never summed across currencies: each currency is its
 * own account with its own opening balance, lines and totals.
 *
 * A running balance is only meaningful over EVERY movement. When the lines are
 * narrowed by invoice status or payment method, the statement says so and
 * shows no opening, running or closing balance rather than a wrong one.
 */
final class SaasAccountStatement
{
    public const TYPES = ['invoice', 'void', 'payment', 'partial_payment', 'reversal'];

    public const STATUSES = ['issued', 'partially_paid', 'settled', 'overdue', 'void'];

    public const METHODS = ['cash', 'bank_transfer', 'manual_electronic', 'other'];

    /**
     * @param  array{status?: string|null, method?: string|null, currency?: string|null}  $filters
     * @return array{from: Carbon, to: Carbon, filtered: bool, currencies: list<array{currency: string, opening: int|null, closing: int|null, totals: array{invoiced: int, discounts: int, voided: int, paid: int, reversed: int}, lines: list<array<string, mixed>>}>}
     */
    public function build(string $tenantId, Carbon $from, Carbon $to, array $filters = []): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();
        $status = in_array($filters['status'] ?? null, self::STATUSES, true) ? $filters['status'] : null;
        $method = in_array($filters['method'] ?? null, self::METHODS, true) ? $filters['method'] : null;
        $currency = is_string($filters['currency'] ?? null) && $filters['currency'] !== '' ? mb_strtoupper($filters['currency']) : null;
        $filtered = $status !== null || $method !== null;

        $events = $this->events($tenantId);

        $groups = [];
        foreach ($events as $event) {
            if ($currency !== null && $event['currency'] !== $currency) {
                continue;
            }
            $code = $event['currency'];
            $groups[$code] ??= ['currency' => $code, 'opening' => 0, 'closing' => null, 'totals' => ['invoiced' => 0, 'discounts' => 0, 'voided' => 0, 'paid' => 0, 'reversed' => 0], 'lines' => [], 'active' => false];

            if ($event['at']->lt($from)) {
                $groups[$code]['opening'] += $event['debit'] - $event['credit'];

                continue;
            }
            if ($event['at']->gt($to)) {
                continue;
            }
            if ($status !== null && $event['invoice_status'] !== $status) {
                continue;
            }
            if ($method !== null && ($event['method'] ?? null) !== $method) {
                continue;
            }

            $groups[$code]['active'] = true;
            $groups[$code]['lines'][] = $event;
            if ($event['type'] === 'invoice') {
                $groups[$code]['totals']['invoiced'] += $event['debit'];
                $groups[$code]['totals']['discounts'] += $event['discount'];
            } elseif ($event['type'] === 'void') {
                $groups[$code]['totals']['voided'] += $event['credit'];
            } elseif ($event['type'] === 'reversal') {
                $groups[$code]['totals']['reversed'] += $event['debit'];
            } else {
                $groups[$code]['totals']['paid'] += $event['credit'];
            }
        }

        $result = [];
        foreach ($groups as $group) {
            // A currency with an opening balance but no movement in the period
            // still belongs on the statement: it is money owed.
            if (! $group['active'] && ($filtered || $group['opening'] === 0)) {
                continue;
            }
            $balance = $group['opening'];
            foreach ($group['lines'] as $i => $line) {
                $balance += $line['debit'] - $line['credit'];
                $group['lines'][$i]['balance'] = $filtered ? null : $balance;
            }
            $result[] = [
                'currency' => $group['currency'],
                'opening' => $filtered ? null : $group['opening'],
                'closing' => $filtered ? null : $balance,
                'totals' => $group['totals'],
                'lines' => $group['lines'],
            ];
        }
        usort($result, static fn (array $a, array $b): int => strcmp($a['currency'], $b['currency']));

        return ['from' => $from, 'to' => $to, 'filtered' => $filtered, 'currencies' => $result];
    }

    /**
     * Every movement on the center's SaaS account, oldest first.
     *
     * @return list<array{at: Carbon, type: string, currency: string, debit: int, credit: int, discount: int, invoice_number: string, invoice_uuid: string, invoice_status: string, reference: string|null, method: string|null, description: array<string, mixed>}>
     */
    private function events(string $tenantId): array
    {
        $invoices = SaasInvoice::query()->where('tenant_id', $tenantId)->orderBy('issued_at')->orderBy('id')->get();
        $payments = SaasPayment::query()->where('tenant_id', $tenantId)->orderBy('received_at')->orderBy('id')->get()->groupBy('invoice_id');

        $events = [];
        foreach ($invoices as $invoice) {
            $status = $invoice->isOverdue() ? 'overdue' : $invoice->status;
            $base = [
                'currency' => mb_strtoupper($invoice->currency),
                'invoice_number' => $invoice->number,
                'invoice_uuid' => $invoice->uuid,
                'invoice_status' => $status,
                'method' => null,
                'discount' => 0,
            ];
            $events[] = array_merge($base, [
                'at' => $invoice->issued_at,
                'type' => 'invoice',
                'debit' => $invoice->total_minor,
                'credit' => 0,
                'discount' => $invoice->discount_minor,
                'reference' => $invoice->reference,
                'description' => ['plan' => $invoice->plan_name_snapshot, 'cycle' => $invoice->billing_period, 'period_start' => $invoice->period_start, 'period_end' => $invoice->period_end],
                'order' => 0,
            ]);
            if ($invoice->voided_at !== null) {
                $events[] = $base + [
                    'at' => $invoice->voided_at,
                    'type' => 'void',
                    'debit' => 0,
                    'credit' => $invoice->total_minor,
                    'reference' => null,
                    'description' => ['reason' => $invoice->void_reason],
                    'order' => 3,
                ];
            }

            // A settlement is partial when it leaves part of the invoice open
            // at the moment it is received, counting earlier reversals.
            $moves = [];
            foreach ($payments->get($invoice->id, collect()) as $payment) {
                /** @var SaasPayment $payment */
                $moves[] = ['at' => $payment->received_at, 'payment' => $payment, 'reversal' => false];
                if ($payment->reversed_at !== null) {
                    $moves[] = ['at' => $payment->reversed_at, 'payment' => $payment, 'reversal' => true];
                }
            }
            usort($moves, static fn (array $a, array $b): int => [$a['at']->getTimestamp(), (int) $a['reversal']] <=> [$b['at']->getTimestamp(), (int) $b['reversal']]);
            $settled = 0;
            foreach ($moves as $move) {
                /** @var SaasPayment $payment */
                $payment = $move['payment'];
                $line = array_merge($base, [
                    'at' => $move['at'],
                    'reference' => $payment->reference,
                    'method' => $payment->method,
                    'currency' => mb_strtoupper($payment->currency),
                ]);
                if ($move['reversal']) {
                    $settled -= $payment->amount_minor;
                    $events[] = $line + ['type' => 'reversal', 'debit' => $payment->amount_minor, 'credit' => 0, 'description' => ['reason' => $payment->reversal_reason, 'method' => $payment->method], 'order' => 2];

                    continue;
                }
                $settled += $payment->amount_minor;
                $events[] = $line + ['type' => $settled < $invoice->total_minor ? 'partial_payment' : 'payment', 'debit' => 0, 'credit' => $payment->amount_minor, 'description' => ['method' => $payment->method], 'order' => 1];
            }
        }

        usort($events, static fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['order']] <=> [$b['at']->getTimestamp(), $b['order']]);

        return array_map(static function (array $event): array {
            unset($event['order']);

            return $event;
        }, $events);
    }
}
