<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

/**
 * Sales & payments: what was billed (published invoices, voided excluded),
 * what was collected (succeeded payments less refunds) and what is still
 * owed on the period's invoices. Never "revenue" or "profit"; one lead
 * currency, the others disclosed.
 */
final class SalesFacts
{
    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$sales, $salesBefore] = [$reads->sales(), $reads->sales(true)];
        [$payments, $paymentsBefore] = [$reads->paymentSummary(), $reads->paymentSummary(true)];
        $lead = Facts::leadCurrency($sales['billed'], $salesBefore['billed'], $payments['net'], $paymentsBefore['net']);
        $count = count($reads->buckets);
        $money = static fn (array $data, string $key): int => (int) ($data[$key][$lead] ?? 0);

        $billed = $money($sales, 'billed');
        $billedBefore = $money($salesBefore, 'billed');
        $invoices = $money($sales, 'billed_invoices');
        $invoicesBefore = $money($salesBefore, 'billed_invoices');

        $metrics = [
            'billed' => Facts::metric($billed, $billedBefore, 'money'),
            'net_collected' => Facts::metric($money($payments, 'net'), $money($paymentsBefore, 'net'), 'money'),
            'average_ticket' => Facts::metric(Facts::mean($billed, $invoices, 0), Facts::mean($billedBefore, $invoicesBefore, 0), 'money'),
            'invoices' => Facts::metric($invoices, $invoicesBefore),
            'collected' => Facts::metric($money($payments, 'collected'), $money($paymentsBefore, 'collected'), 'money'),
            'refunded' => Facts::metric($money($payments, 'refunded'), $money($paymentsBefore, 'refunded'), 'money', false),
            'outstanding' => Facts::metric($money($payments, 'outstanding'), $money($paymentsBefore, 'outstanding'), 'money', false),
            'voided' => Facts::metric((int) $sales['voided'], (int) $salesBefore['voided'], 'number', false),
        ];

        $series = [
            'billed' => Facts::pair(
                Facts::series($sales['daily'][$lead] ?? [], $sales['hourly'][$lead] ?? [], $reads->buckets),
                Facts::aligned($salesBefore['daily'][$lead] ?? [], $salesBefore['hourly'][$lead] ?? [], $reads->previousBuckets, $count),
                'money',
            ),
            'net_collected' => Facts::pair(
                Facts::series($payments['daily'][$lead] ?? [], $payments['hourly'][$lead] ?? [], $reads->buckets),
                Facts::aligned($paymentsBefore['daily'][$lead] ?? [], $paymentsBefore['hourly'][$lead] ?? [], $reads->previousBuckets, $count),
                'money',
            ),
        ];

        $categories = array_values(array_filter($sales['categories'] ?? [], static fn (array $row): bool => $row['currency'] === $lead && $row['total_minor'] > 0));
        $kinds = [];

        foreach ($categories as $row) {
            $kinds[$row['kind']] = ($kinds[$row['kind']] ?? 0) + (int) $row['total_minor'];
        }

        arsort($kinds);
        $noBookings = ['branches' => []];

        return [
            'empty' => (int) $sales['invoices'] === 0 && Facts::sum($payments['collected']) === 0 && Facts::sum($payments['refunded']) === 0,
            'currency' => $lead,
            'other_currencies' => Facts::others($sales['billed'], $lead),
            'metrics' => $metrics,
            'series' => $series,
            'parts' => [
                'payment_methods' => Facts::methods($payments['methods'], $lead),
                'payment_methods_previous' => Facts::methods($paymentsBefore['methods'], $lead),
                'categories' => $categories,
                'kinds' => $kinds,
                'items' => Facts::items($sales['items'], $salesBefore['items'], $lead),
                'branches' => $reads->branchCount() > 1 ? Facts::branches($reads, $noBookings, $noBookings, $sales, $salesBefore, $lead) : [],
            ],
        ];
    }
}
