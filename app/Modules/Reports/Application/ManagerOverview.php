<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Reporting\ReportReadRequest;
use App\Kernel\Time\DateRange;
use App\Modules\Booking\Contracts\BookingReportReader;
use App\Modules\Customers\Contracts\CustomerReportReader;
use App\Modules\Loyalty\Contracts\BenefitReportReader;
use App\Modules\Payments\Contracts\PaymentReportReader;
use App\Modules\Queue\Contracts\QueueReportReader;
use App\Modules\Sales\Contracts\SalesReportReader;
use App\Modules\ServiceJourney\Contracts\JourneyReportReader;
use Throwable;

/**
 * The Manager overview: a SUMMARY of the period, never a second Reports page.
 *
 * Built from the same read contracts the Standard Reports use, through the
 * same request factory — so branch scope, the branch-local day and the
 * definitions are exactly the reports' own, and the two can never disagree
 * (the average ticket, for one, divides the same numerator by the same
 * denominator: billed value over NON-VOIDED invoices in that currency).
 *
 * Each section exists only when the viewer may see that domain (permission)
 * and the center has it (entitlement); a section the viewer cannot see is
 * absent, not zero. Money stays partitioned by currency: one lead currency is
 * charted and every other one is DISCLOSED, never added in or dropped.
 */
final class ManagerOverview
{
    public function __construct(
        private readonly ReportRequestFactory $requests,
        private readonly Entitlements $entitlements,
        private readonly BookingReportReader $bookings,
        private readonly JourneyReportReader $journeys,
        private readonly SalesReportReader $sales,
        private readonly PaymentReportReader $payments,
        private readonly CustomerReportReader $customers,
        private readonly QueueReportReader $queue,
        private readonly BenefitReportReader $benefits,
    ) {}

    /**
     * Which sections this viewer gets: permission AND entitlement, per domain.
     *
     * @return array<string, bool>
     */
    public function gates(User $user): array
    {
        return [
            'bookings' => $user->hasPermission(Permission::AppointmentView) && $this->entitlements->enabled('booking'),
            'visits' => $user->hasPermission(Permission::JourneyView),
            'sales' => $user->hasPermission(Permission::SaleView) && $this->entitlements->enabled('pos'),
            'payments' => $user->hasPermission(Permission::PaymentView) && $this->entitlements->enabled('pos'),
            // The customer reader is built from visits, so it needs both.
            'customers' => $user->hasPermission(Permission::CustomerView) && $user->hasPermission(Permission::JourneyView),
            'queue' => $user->hasPermission(Permission::QueueView) && $this->entitlements->enabled('queue_management'),
            'loyalty' => $user->hasPermission(Permission::LoyaltyView) && $this->entitlements->enabled('loyalty'),
            'packages' => $user->hasPermission(Permission::PackageView) && $this->entitlements->enabled('packages'),
            'memberships' => $user->hasPermission(Permission::MembershipView) && $this->entitlements->enabled('memberships'),
        ];
    }

    /**
     * @return array{sections: array<string, array<string, mixed>>, buckets: list<array{key: string, label: string}>, previous_buckets: list<array{key: string, label: string}>}
     */
    public function build(User $user, DateRange $range, string $locale, ?string $branchUuid = null): array
    {
        $previousRange = $range->previous();
        $empty = ['sections' => [], 'buckets' => [], 'previous_buckets' => []];

        try {
            $current = $this->request($user, $range, $branchUuid);
            $previous = $this->request($user, $previousRange, $branchUuid);
        } catch (Throwable $failure) {
            // A malformed range: an empty overview, never another branch's numbers.
            report($failure);

            return $empty;
        }

        $buckets = $range->buckets($locale);
        $previousBuckets = $previousRange->buckets($locale);
        $gates = $this->gates($user);
        $sections = [];
        $count = count($buckets);

        if ($gates['bookings']) {
            $sections['bookings'] = $this->bookingsSection($this->bookings->summary($current), $this->bookings->summary($previous), $buckets, $previousBuckets, $count);
        }

        if ($gates['visits']) {
            $sections['visits'] = $this->visitsSection($this->journeys->summary($current), $this->journeys->summary($previous), $buckets);
        }

        if ($gates['sales']) {
            $sections['sales'] = $this->salesSection($this->sales->summary($current), $this->sales->summary($previous), $buckets);
        }

        if ($gates['payments']) {
            $sections['payments'] = $this->paymentsSection($this->payments->totals($current), $this->payments->totals($previous), $buckets, $sections['sales']['currency'] ?? null);
        }

        if ($gates['customers']) {
            $now = $this->customers->summary($current);
            $then = $this->customers->summary($previous);
            $sections['customers'] = [
                'served' => (int) $now['customers'],
                'served_previous' => (int) $then['customers'],
                'new' => (int) $now['new'],
                'new_previous' => (int) $then['new'],
                'returning' => (int) $now['returning'],
                'returning_previous' => (int) $then['returning'],
                'series_new' => OverviewSeries::onto($now['daily_new'] ?? [], $now['hourly_new'] ?? [], $buckets),
            ];
        }

        if ($gates['queue']) {
            $now = $this->queue->summary($current);
            $then = $this->queue->summary($previous);
            $daily = [];
            foreach ($now['daily'] as $row) {
                $daily[(string) $row['date']] = (int) $row['tickets'];
            }

            $sections['queue'] = [
                'tickets' => (int) $now['total'],
                'tickets_previous' => (int) $then['total'],
                'first_call_seconds' => $now['average_first_call_seconds'] ?? null,
                'first_call_previous' => $then['average_first_call_seconds'] ?? null,
                'series' => OverviewSeries::onto($daily, $now['hourly'] ?? [], $buckets),
            ];
        }

        if ($gates['loyalty'] || $gates['packages'] || $gates['memberships']) {
            $sections['benefits'] = $this->benefitsSection($this->benefits->summary($current), $this->benefits->summary($previous), $gates);
        }

        return [
            'sections' => $sections,
            'buckets' => array_map(static fn (array $bucket): array => ['key' => $bucket['key'], 'label' => $bucket['label']], $buckets),
            'previous_buckets' => array_map(static fn (array $bucket): array => ['key' => $bucket['key'], 'label' => $bucket['label']], $previousBuckets),
        ];
    }

    private function request(User $user, DateRange $range, ?string $branchUuid): ReportReadRequest
    {
        return $this->requests->make(
            $user->branchScope(),
            $range->from->toDateString(),
            $range->to->toDateString(),
            // Narrows within scope; a uuid outside it yields no window at all.
            $branchUuid !== null && $branchUuid !== '' ? [$branchUuid] : [],
            ReadTarget::Primary,
        );
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $then
     * @param  list<array{key: string, label: string}>  $buckets
     * @param  list<array{key: string, label: string}>  $previousBuckets
     * @return array<string, mixed>
     */
    private function bookingsSection(array $now, array $then, array $buckets, array $previousBuckets, int $count): array
    {
        $status = static fn (array $data, string $key): int => (int) ($data['status'][$key] ?? 0);

        return [
            'total' => (int) $now['total'],
            'total_previous' => (int) $then['total'],
            'completed' => $status($now, 'completed'),
            'completed_previous' => $status($then, 'completed'),
            'cancelled' => $status($now, 'cancelled'),
            'cancelled_previous' => $status($then, 'cancelled'),
            'no_show' => $status($now, 'no_show'),
            'no_show_previous' => $status($then, 'no_show'),
            'status' => $now['status'],
            'sources' => $now['sources'],
            'series' => OverviewSeries::onto($now['daily'], $now['hourly'] ?? [], $buckets),
            'series_previous' => OverviewSeries::aligned($then['daily'], $then['hourly'] ?? [], $previousBuckets, $count),
        ];
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $then
     * @param  list<array{key: string, label: string}>  $buckets
     * @return array<string, mixed>
     */
    private function visitsSection(array $now, array $then, array $buckets): array
    {
        $services = array_values($now['stages']['services'] ?? []);
        usort($services, static fn (array $a, array $b): int => $b['completed'] <=> $a['completed']);
        $performers = array_values($now['stages']['employees'] ?? []);
        usort($performers, static fn (array $a, array $b): int => [$b['completed'], $b['minutes']] <=> [$a['completed'], $a['minutes']]);

        return [
            'arrivals' => (int) $now['total'],
            'arrivals_previous' => (int) $then['total'],
            'completed' => (int) ($now['status']['completed'] ?? 0),
            'completed_previous' => (int) ($then['status']['completed'] ?? 0),
            'active' => (int) ($now['status']['active'] ?? 0),
            'walk_ins' => (int) ($now['sources']['walk_in'] ?? 0),
            'walk_ins_previous' => (int) ($then['sources']['walk_in'] ?? 0),
            'services_performed' => (int) ($now['stages']['completed'] ?? 0),
            'services_performed_previous' => (int) ($then['stages']['completed'] ?? 0),
            'service_minutes' => (int) ($now['stages']['minutes'] ?? 0),
            'top_services' => array_slice($services, 0, 6),
            'performers' => array_slice($performers, 0, 6),
            'series' => OverviewSeries::onto($now['daily'], $now['hourly'] ?? [], $buckets),
        ];
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $then
     * @param  list<array{key: string, label: string}>  $buckets
     * @return array<string, mixed>
     */
    private function salesSection(array $now, array $then, array $buckets): array
    {
        $currency = $this->leadCurrency($now['billed'], $then['billed']);
        $billed = (int) ($now['billed'][$currency] ?? 0);
        $billedPrevious = (int) ($then['billed'][$currency] ?? 0);
        $invoices = (int) ($now['billed_invoices'][$currency] ?? 0);
        $invoicesPrevious = (int) ($then['billed_invoices'][$currency] ?? 0);
        $others = [];

        foreach ($now['billed'] as $code => $amount) {
            if ((string) $code !== $currency) {
                $others[] = ['currency' => (string) $code, 'billed' => (int) $amount, 'invoices' => (int) ($now['billed_invoices'][$code] ?? 0)];
            }
        }

        $categories = array_values(array_filter($now['categories'] ?? [], static fn (array $row): bool => $row['currency'] === $currency && $row['total_minor'] > 0));

        return [
            'currency' => $currency,
            'billed' => $billed,
            'billed_previous' => $billedPrevious,
            'invoices' => $invoices,
            'invoices_previous' => $invoicesPrevious,
            'voided' => (int) $now['voided'],
            // An average only when there is something to average.
            'average' => $invoices > 0 ? (int) round($billed / $invoices) : null,
            'average_previous' => $invoicesPrevious > 0 ? (int) round($billedPrevious / $invoicesPrevious) : null,
            'other_currencies' => $others,
            'top_items' => $this->topItems($now['items'], $currency),
            'categories' => array_slice($categories, 0, 8),
            'series' => OverviewSeries::onto($now['daily'][$currency] ?? [], $now['hourly'][$currency] ?? [], $buckets),
        ];
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $then
     * @param  list<array{key: string, label: string}>  $buckets
     * @return array<string, mixed>
     */
    private function paymentsSection(array $now, array $then, array $buckets, ?string $salesCurrency): array
    {
        $currency = $salesCurrency ?? $this->leadCurrency($now['collected'], $then['collected']);
        $methods = [];

        foreach ($now['methods'] as $method => $amounts) {
            if (($amounts[$currency] ?? 0) > 0) {
                $methods[] = ['method' => (string) $method, 'amount' => (int) $amounts[$currency]];
            }
        }

        usort($methods, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);
        $others = [];

        foreach ($now['net'] as $code => $amount) {
            if ((string) $code !== $currency && (int) $amount !== 0) {
                $others[] = ['currency' => (string) $code, 'net' => (int) $amount];
            }
        }

        return [
            'currency' => $currency,
            'collected' => (int) ($now['collected'][$currency] ?? 0),
            'refunded' => (int) ($now['refunded'][$currency] ?? 0),
            'net' => (int) ($now['net'][$currency] ?? 0),
            'net_previous' => (int) ($then['net'][$currency] ?? 0),
            'methods' => $methods,
            'other_currencies' => $others,
            'series' => OverviewSeries::onto($now['daily'][$currency] ?? [], $now['hourly'][$currency] ?? [], $buckets),
        ];
    }

    /**
     * @param  array<string, mixed>  $now
     * @param  array<string, mixed>  $then
     * @param  array<string, bool>  $gates
     * @return array<string, mixed>
     */
    private function benefitsSection(array $now, array $then, array $gates): array
    {
        $points = static function (array $data, string $direction): int {
            $sum = 0;
            foreach ($data['loyalty'] ?? [] as $movement) {
                if ($movement['direction'] === $direction) {
                    $sum += (int) $movement['points'];
                }
            }

            return $sum;
        };

        $section = [];

        if ($gates['loyalty']) {
            $section['loyalty'] = [
                'earned' => $points($now, 'in'),
                'earned_previous' => $points($then, 'in'),
                'spent' => $points($now, 'out'),
                'spent_previous' => $points($then, 'out'),
            ];
        }

        if ($gates['packages']) {
            $section['packages'] = [
                'sold' => (int) ($now['activations']['packages'] ?? 0),
                'sold_previous' => (int) ($then['activations']['packages'] ?? 0),
                'redeemed' => (int) ($now['packages']['redemption'] ?? 0),
                'redeemed_previous' => (int) ($then['packages']['redemption'] ?? 0),
            ];
        }

        if ($gates['memberships']) {
            $section['memberships'] = [
                'sold' => (int) ($now['activations']['memberships'] ?? 0),
                'sold_previous' => (int) ($then['activations']['memberships'] ?? 0),
                'uses' => (int) ($now['memberships']['use'] ?? 0),
                'uses_previous' => (int) ($then['memberships']['use'] ?? 0),
            ];
        }

        return $section;
    }

    /**
     * The currency with the most billed value across both periods; the
     * center's usual single currency in practice.
     *
     * @param  array<string, int>  $now
     * @param  array<string, int>  $then
     */
    private function leadCurrency(array $now, array $then): string
    {
        $totals = $now;
        foreach ($then as $code => $amount) {
            $totals[$code] = ($totals[$code] ?? 0) + $amount;
        }

        arsort($totals);
        $lead = array_key_first($totals);

        return is_string($lead) ? $lead : Currency::default()->value;
    }

    /**
     * @param  list<array{kind: string, name: string, currency: string, count: int, total_minor: int}>  $items
     * @return list<array{kind: string, name: string, count: int, total_minor: int}>
     */
    private function topItems(array $items, string $currency): array
    {
        $rows = array_values(array_filter($items, static fn (array $item): bool => $item['currency'] === $currency && $item['total_minor'] > 0));
        usort($rows, static fn (array $a, array $b): int => $b['total_minor'] <=> $a['total_minor']);

        return array_map(static fn (array $item): array => ['kind' => $item['kind'], 'name' => $item['name'], 'count' => $item['count'], 'total_minor' => $item['total_minor']], array_slice($rows, 0, 6));
    }
}
