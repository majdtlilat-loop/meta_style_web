<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

/**
 * Every figure the Advanced page states, derived from one period's section
 * facts (the reporting readers' own summaries) — the same function for the
 * current period, the comparison period and each compared entity, so a KPI,
 * a movement and a comparison row can never disagree.
 *
 * A metric exists only when its section was read (the viewer holds its
 * permissions); a rate without a denominator is null, never 0 %.
 * Direction (`higher`): true = more is better, false = less is better (the
 * cancellation rate, waits, refunds), null = no judgement (a mix, a volume).
 */
final class Metrics
{
    /** key => [section, format, higher is better] */
    public const DEFINITIONS = [
        'billed' => ['sales', 'money', true],
        'average_ticket' => ['sales', 'money', true],
        'invoices' => ['sales', 'number', true],
        'voided' => ['sales', 'number', false],
        'net_collected' => ['payments', 'money', true],
        'refunded' => ['payments', 'money', false],
        'outstanding' => ['payments', 'money', false],
        'bookings' => ['bookings', 'number', true],
        'created' => ['bookings', 'number', true],
        'completion_rate' => ['bookings', 'percent', true],
        'cancellation_rate' => ['bookings', 'percent', false],
        'no_show_rate' => ['bookings', 'percent', false],
        'arrivals' => ['visits', 'number', true],
        'completed_visits' => ['visits', 'number', true],
        'walk_ins' => ['visits', 'number', null],
        'aborted_visits' => ['visits', 'number', false],
        'completed_services' => ['visits', 'number', true],
        'service_minutes' => ['visits', 'duration', null],
        'average_service_minutes' => ['visits', 'duration', null],
        'customers' => ['customers', 'number', true],
        'new_customers' => ['customers', 'number', true],
        'returning_customers' => ['customers', 'number', true],
        'return_rate' => ['customers', 'percent', true],
        'visit_frequency' => ['customers', 'number', true],
        'reviews' => ['reviews', 'number', true],
        'average_rating' => ['reviews', 'number', true],
        'tickets' => ['queue', 'number', null],
        'first_call' => ['queue', 'seconds', false],
        'service_start' => ['queue', 'seconds', false],
        'loyalty_earned' => ['benefits', 'number', null],
        'loyalty_redeemed' => ['benefits', 'number', null],
        'package_redemptions' => ['benefits', 'number', true],
        'membership_uses' => ['benefits', 'number', true],
        'memberships_activated' => ['benefits', 'number', true],
        'packages_activated' => ['benefits', 'number', true],
    ];

    /**
     * @param  array<string, array<string, mixed>>  $facts  section => reader summary (one period)
     * @return array<string, int|float|null> only the metrics whose section is present
     */
    public static function values(array $facts, string $currency): array
    {
        $values = [];

        if (isset($facts['sales'])) {
            $sales = $facts['sales'];
            $billed = (int) ($sales['billed'][$currency] ?? 0);
            $count = (int) ($sales['billed_invoices'][$currency] ?? 0);
            $values += [
                'billed' => $billed,
                'average_ticket' => $count > 0 ? (int) round($billed / $count) : null,
                'invoices' => (int) ($sales['invoices'] ?? 0),
                'voided' => (int) ($sales['voided'] ?? 0),
            ];
        }

        if (isset($facts['payments'])) {
            $payments = $facts['payments'];
            $values += [
                'net_collected' => (int) ($payments['net'][$currency] ?? 0),
                'refunded' => (int) ($payments['refunded'][$currency] ?? 0),
                'outstanding' => isset($payments['outstanding']) ? (int) ($payments['outstanding'][$currency] ?? 0) : null,
            ];
        }

        if (isset($facts['bookings'])) {
            $bookings = $facts['bookings'];
            $status = (array) ($bookings['status'] ?? []);
            // One denominator for the three rates, as on Standard Reports:
            // bookings that reached an OUTCOME. A booking still ahead (booked,
            // confirmed) has no outcome yet — counting it would make "today"
            // look like a collapse against yesterday at ten in the morning.
            $outcomes = (int) ($status['completed'] ?? 0) + (int) ($status['cancelled'] ?? 0) + (int) ($status['no_show'] ?? 0);
            $values += [
                'bookings' => (int) ($bookings['total'] ?? 0),
                'created' => (int) ($bookings['created'] ?? 0),
                'completion_rate' => Series::rate((int) ($status['completed'] ?? 0), $outcomes),
                'cancellation_rate' => Series::rate((int) ($status['cancelled'] ?? 0), $outcomes),
                'no_show_rate' => Series::rate((int) ($status['no_show'] ?? 0), $outcomes),
            ];
        }

        if (isset($facts['visits'])) {
            $visits = $facts['visits'];
            $stages = (array) ($visits['stages'] ?? []);
            $completedServices = (int) ($stages['completed'] ?? 0);
            $minutes = (int) ($stages['minutes'] ?? 0);
            $values += [
                'arrivals' => (int) ($visits['total'] ?? 0),
                'completed_visits' => (int) ($visits['status']['completed'] ?? 0),
                'walk_ins' => (int) ($visits['sources']['walk_in'] ?? 0),
                'aborted_visits' => (int) ($visits['status']['aborted'] ?? 0),
                'completed_services' => $completedServices,
                'service_minutes' => $minutes,
                'average_service_minutes' => $completedServices > 0 ? round($minutes / $completedServices, 1) : null,
            ];
        }

        if (isset($facts['customers'])) {
            $customers = $facts['customers'];
            $served = (int) ($customers['customers'] ?? 0);
            $values += [
                'customers' => $served,
                'new_customers' => (int) ($customers['new'] ?? 0),
                'returning_customers' => (int) ($customers['returning'] ?? 0),
                'return_rate' => Series::rate((int) ($customers['returning'] ?? 0), $served),
                'visit_frequency' => is_numeric($customers['visit_frequency'] ?? null) ? (float) $customers['visit_frequency'] : null,
            ];
        }

        if (isset($facts['reviews'])) {
            $values += [
                'reviews' => (int) ($facts['reviews']['count'] ?? 0),
                'average_rating' => is_numeric($facts['reviews']['average'] ?? null) ? (float) $facts['reviews']['average'] : null,
            ];
        }

        if (isset($facts['queue'])) {
            $queue = $facts['queue'];
            $values += [
                'tickets' => (int) ($queue['total'] ?? 0),
                'first_call' => is_numeric($queue['average_first_call_seconds'] ?? null) ? (int) $queue['average_first_call_seconds'] : null,
                'service_start' => is_numeric($queue['average_service_start_seconds'] ?? null) ? (int) $queue['average_service_start_seconds'] : null,
            ];
        }

        if (isset($facts['benefits'])) {
            $benefits = $facts['benefits'];
            $earned = 0;
            $redeemed = 0;

            foreach ((array) ($benefits['loyalty'] ?? []) as $movement) {
                if (($movement['kind'] ?? '') === 'earn') {
                    $earned += (int) ($movement['points'] ?? 0);
                } elseif (($movement['kind'] ?? '') === 'redeem') {
                    $redeemed += (int) ($movement['points'] ?? 0);
                }
            }

            $values += [
                'loyalty_earned' => $earned,
                'loyalty_redeemed' => $redeemed,
                'package_redemptions' => (int) ($benefits['packages']['redemption'] ?? 0),
                'membership_uses' => (int) ($benefits['memberships']['use'] ?? 0),
                'memberships_activated' => (int) ($benefits['activations']['memberships'] ?? 0),
                'packages_activated' => (int) ($benefits['activations']['packages'] ?? 0),
            ];
        }

        return $values;
    }

    public static function format(string $key): string
    {
        return self::DEFINITIONS[$key][1] ?? 'number';
    }

    public static function higherIsBetter(string $key): ?bool
    {
        return self::DEFINITIONS[$key][2] ?? null;
    }

    public static function label(string $key): string
    {
        return (string) __('manager_advanced.metric.'.$key);
    }

    /**
     * One KPI card's props.
     *
     * @param  array<string, int|float|null>  $current
     * @param  array<string, int|float|null>  $previous
     * @param  list<int|float>  $trend
     * @return array<string, mixed>|null null when the metric is not available
     */
    public static function card(string $key, array $current, array $previous, string $currency, array $trend = [], ?string $icon = null): ?array
    {
        if (! array_key_exists($key, $current)) {
            return null;
        }

        $format = self::format($key);

        return [
            'key' => $key,
            'label' => self::label($key),
            'current' => $current[$key],
            'previous' => $previous[$key] ?? null,
            'format' => $format,
            'currency' => $format === 'money' ? $currency : null,
            'higher' => self::higherIsBetter($key),
            'trend' => count($trend) > 1 ? $trend : [],
            'icon' => $icon,
        ];
    }
}
