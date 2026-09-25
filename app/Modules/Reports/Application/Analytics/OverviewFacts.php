<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

use App\Kernel\Authorization\Permission;

/**
 * Business overview: the few figures a center runs on, across its domains.
 *
 * Money is the lead currency only (others are disclosed, never added); the
 * booking rates share one denominator — bookings that reached an outcome —
 * so completed, cancelled and no-show always add up to 100 %. Customers and
 * loyalty appear only for a viewer who may see them.
 */
final class OverviewFacts
{
    /** @return array<string, mixed> */
    public function build(AnalyticsReads $reads): array
    {
        [$bookings, $bookingsBefore] = [$reads->bookings(), $reads->bookings(true)];
        [$visits, $visitsBefore] = [$reads->journeys(), $reads->journeys(true)];
        [$sales, $salesBefore] = [$reads->sales(), $reads->sales(true)];
        [$payments, $paymentsBefore] = [$reads->payments(), $reads->payments(true)];
        $lead = Facts::leadCurrency($sales['billed'], $salesBefore['billed'], $payments['net'], $paymentsBefore['net']);
        $count = count($reads->buckets);

        $billed = (int) ($sales['billed'][$lead] ?? 0);
        $billedBefore = (int) ($salesBefore['billed'][$lead] ?? 0);
        $invoices = (int) ($sales['billed_invoices'][$lead] ?? 0);
        $invoicesBefore = (int) ($salesBefore['billed_invoices'][$lead] ?? 0);

        $metrics = [
            'billed' => Facts::metric($billed, $billedBefore, 'money'),
            'net_collected' => Facts::metric((int) ($payments['net'][$lead] ?? 0), (int) ($paymentsBefore['net'][$lead] ?? 0), 'money'),
            'average_ticket' => Facts::metric(Facts::mean($billed, $invoices, 0), Facts::mean($billedBefore, $invoicesBefore, 0), 'money'),
            'scheduled_bookings' => Facts::metric((int) $bookings['total'], (int) $bookingsBefore['total']),
            'completed_services' => Facts::metric((int) ($visits['stages']['completed'] ?? 0), (int) ($visitsBefore['stages']['completed'] ?? 0)),
            'cancellation_rate' => Facts::metric(
                Facts::rate((int) ($bookings['status']['cancelled'] ?? 0), Facts::resolved($bookings['status'])),
                Facts::rate((int) ($bookingsBefore['status']['cancelled'] ?? 0), Facts::resolved($bookingsBefore['status'])),
                'percent',
                false,
            ),
            'no_show_rate' => Facts::metric(
                Facts::rate((int) ($bookings['status']['no_show'] ?? 0), Facts::resolved($bookings['status'])),
                Facts::rate((int) ($bookingsBefore['status']['no_show'] ?? 0), Facts::resolved($bookingsBefore['status'])),
                'percent',
                false,
            ),
        ];

        $series = [
            'billed' => Facts::pair(Facts::series($sales['daily'][$lead] ?? [], $sales['hourly'][$lead] ?? [], $reads->buckets), Facts::aligned($salesBefore['daily'][$lead] ?? [], $salesBefore['hourly'][$lead] ?? [], $reads->previousBuckets, $count), 'money'),
            'net_collected' => Facts::pair(Facts::series($payments['daily'][$lead] ?? [], $payments['hourly'][$lead] ?? [], $reads->buckets), null, 'money'),
            'scheduled_bookings' => Facts::pair(Facts::series($bookings['daily'], $bookings['hourly'], $reads->buckets), Facts::aligned($bookingsBefore['daily'], $bookingsBefore['hourly'], $reads->previousBuckets, $count)),
            'completed_services' => Facts::pair(Facts::series($visits['stages']['daily'] ?? [], $visits['stages']['hourly'] ?? [], $reads->buckets), null),
            'average_ticket' => Facts::pair(null, null, 'money'),
        ];

        $parts = [
            'booking_status' => $bookings['status'],
            'payment_methods' => Facts::methods($payments['methods'], $lead),
            'categories' => array_values(array_filter($sales['categories'] ?? [], static fn (array $row): bool => $row['currency'] === $lead && $row['total_minor'] > 0)),
            'top_services' => Facts::items($sales['items'], $salesBefore['items'], $lead, 'service'),
            'completion' => [
                'current' => Facts::rate((int) ($bookings['status']['completed'] ?? 0), Facts::resolved($bookings['status'])),
                'previous' => Facts::rate((int) ($bookingsBefore['status']['completed'] ?? 0), Facts::resolved($bookingsBefore['status'])),
            ],
            'branches' => $reads->branchCount() > 1 ? Facts::branches($reads, $bookings, $bookingsBefore, $sales, $salesBefore, $lead) : [],
        ];

        $empty = (int) $bookings['total'] === 0 && (int) $visits['total'] === 0 && (int) $sales['invoices'] === 0 && Facts::sum($payments['collected']) === 0;

        if ($reads->may(Permission::CustomerView)) {
            $customers = $reads->customers();
            $customersBefore = $reads->customers(true);
            $metrics['new_customers'] = Facts::metric((int) $customers['new'], (int) $customersBefore['new']);
            $metrics['returning_customers'] = Facts::metric((int) $customers['returning'], (int) $customersBefore['returning']);
            $series['new_customers'] = Facts::pair(Facts::series($customers['daily_new'] ?? [], $customers['hourly_new'] ?? [], $reads->buckets), null);
            $parts['customer_mix'] = ['new' => (int) $customers['new'], 'returning' => (int) $customers['returning']];
        }

        if ($reads->may(Permission::LoyaltyView)) {
            $benefits = $reads->benefits();
            $benefitsBefore = $reads->benefits(true);
            $earned = Facts::points($benefits, 'in');
            $earnedBefore = Facts::points($benefitsBefore, 'in');

            // A center without loyalty sees no zero tile; after a downgrade
            // its history still shows.
            if ($reads->owns('loyalty') || $earned > 0 || $earnedBefore > 0) {
                $metrics['loyalty_points_in'] = Facts::metric($earned, $earnedBefore);
                $series['loyalty_points_in'] = Facts::pair(Facts::series($benefits['daily_points']['in'] ?? [], null, $reads->buckets), null);
            }
        }

        return [
            'empty' => $empty,
            'currency' => $lead,
            'other_currencies' => Facts::others($sales['billed'], $lead),
            'metrics' => $metrics,
            'series' => $series,
            'parts' => $parts,
        ];
    }
}
