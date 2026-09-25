<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;

/**
 * The charts each catalog report's rows support — and only those: a report
 * whose rows are a ranking gets ranked bars, a current-vs-comparison report
 * gets grouped bars per unit, a daily report gets a line. Every chart keeps
 * its data table (the component's <details>), and the report's own detail
 * table is still printed below.
 */
final class LibraryCharts
{
    public function __construct(private readonly ?string $currency) {}

    /**
     * @param  array<string, mixed>  $result  ReportResult::toArray()
     * @return list<array<string, mixed>>
     */
    public function for(string $code, array $result): array
    {
        $rows = (array) $result['rows'];

        $cards = match ($code) {
            'period_comparison', 'customer_cohorts', 'benefit_trends' => $this->comparison($rows),
            'branch_comparison' => $this->branches($rows),
            'service_analysis', 'employee_analysis' => $this->dimensions($code, $rows),
            'booking_funnel_analysis' => $this->funnel($result, $rows),
            'customer_retention' => $this->retention($result),
            'customer_value' => [Cards::ranked(__('manager_advanced.cards.top_customers'), array_map(static fn (array $row): array => [
                'label' => __('manager_advanced.values.customer_rank', ['rank' => $row['rank']]),
                'value' => (int) $row['net_minor'],
            ], array_values(array_filter($rows, fn (array $row): bool => ($row['currency'] ?? null) === $this->currency))), 'money', $this->currency, true, true, 'full', 10)],
            'queue_trends' => $this->queue($rows),
            default => [],
        };

        return array_values(array_filter($cards));
    }

    /**
     * Current against comparison per unit: counts together, money together.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>|null>
     */
    private function comparison(array $rows): array
    {
        $cards = [];

        foreach (['number' => ['number'], 'money' => ['money_minor']] as $unit => $formats) {
            $selected = array_values(array_filter($rows, static fn (array $row): bool => in_array($row['format'] ?? 'number', $formats, true) && is_numeric($row['current'] ?? null)));

            if ($selected === []) {
                continue;
            }

            $cards[] = Cards::grouped(__('manager_advanced.library.against_comparison'), array_map(static fn (array $row): string => Lang::has('manager_advanced.kpi.'.$row['metric_key']) ? (string) __('manager_advanced.kpi.'.$row['metric_key']) : (string) $row['metric'], $selected), [
                ['label' => __('manager_advanced.period.current_series'), 'values' => array_map(static fn (array $row): int|float => $row['current'] + 0, $selected)],
                ['label' => __('manager_advanced.period.comparison_series'), 'values' => array_map(static fn (array $row): int|float => is_numeric($row['previous'] ?? null) ? $row['previous'] + 0 : 0, $selected)],
            ], $unit === 'money' ? 'money' : 'number', $unit === 'money' ? $this->currency : null);
        }

        return $cards;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>|null>
     */
    private function branches(array $rows): array
    {
        $names = array_map(static fn (array $row): string => (string) $row['branch'], $rows);
        $groups = [(string) __('manager_advanced.kpi.scheduled_bookings'), (string) __('manager_advanced.kpi.completed_visits'), (string) __('manager_advanced.kpi.walk_ins')];
        $counts = array_map(static fn (string $name, int $i) => [
            'label' => $name,
            'values' => [(int) ($rows[$i]['scheduled_bookings'] ?? 0), (int) ($rows[$i]['completed_visits'] ?? 0), (int) ($rows[$i]['walk_ins'] ?? 0)],
        ], $names, array_keys($names));
        $money = array_map(static fn (string $name, int $i) => [
            'label' => $name,
            'values' => [(int) ($rows[$i]['billed_minor'] ?? 0), (int) ($rows[$i]['net_collected_minor'] ?? 0)],
        ], $names, array_keys($names));

        return [
            Cards::grouped(__('manager_advanced.compare.volume'), $groups, $counts),
            $this->currency === null ? null : Cards::grouped(__('manager_advanced.compare.money'), [(string) __('manager_advanced.kpi.billed'), (string) __('manager_advanced.kpi.net_collected')], $money, 'money', $this->currency),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>|null>
     */
    private function dimensions(string $code, array $rows): array
    {
        return [
            Cards::ranked(__('manager_advanced.library.completed_by_'.($code === 'service_analysis' ? 'service' : 'employee')), array_map(static fn (array $row): array => [
                'label' => (string) ($row['name'] ?? '—'),
                'value' => (int) ($row['completed'] ?? 0),
                'previous' => (int) ($row['previous_completed'] ?? 0),
            ], $rows), 'number'),
            Cards::ranked(__('manager_advanced.cards.service_time'), array_map(static fn (array $row): array => [
                'label' => (string) ($row['name'] ?? '—'),
                'value' => (int) ($row['completed'] ?? 0) > 0 ? round((int) $row['minutes'] / (int) $row['completed'], 1) : 0,
            ], $rows), 'duration', null, false, null),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>|null>
     */
    private function funnel(array $result, array $rows): array
    {
        $rates = [];

        foreach ((array) $result['kpis'] as $kpi) {
            if (in_array($kpi['key'], ['arrival_rate', 'completion_rate'], true) && is_numeric($kpi['value'])) {
                $rates[] = Cards::radial((string) __('manager_advanced.kpi.'.$kpi['key']), (float) $kpi['value'], null, true, 'good');
            }
        }

        return [
            Cards::ranked(__('manager_advanced.library.funnel'), array_map(static fn (array $row): array => [
                'label' => (string) __('manager_advanced.funnel.'.$row['stage_key']),
                'value' => (int) $row['count'],
            ], $rows), 'number', null, false, true, 'half', 3, false),
            Cards::radials(__('manager_advanced.library.conversion'), $rates, 'half'),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>|null>
     */
    private function retention(array $result): array
    {
        $kpis = [];

        foreach ((array) $result['kpis'] as $kpi) {
            $kpis[(string) $kpi['key']] = is_numeric($kpi['value']) ? (float) $kpi['value'] : null;
        }

        return [Cards::radials(__('manager_advanced.cards.return_rate'), [
            Cards::radial((string) __('manager_advanced.kpi.return_rate'), $kpis['return_rate'] ?? null, $kpis['previous_return_rate'] ?? null, true),
        ], 'half')];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>|null>
     */
    private function queue(array $rows): array
    {
        $axis = array_map(static fn (array $row): array => ['label' => CarbonImmutable::parse((string) $row['date'])->locale(app()->getLocale())->isoFormat('D MMM')], $rows);
        $tickets = array_map(static fn (array $row): int => (int) $row['tickets'], $rows);
        $waits = array_map(static fn (array $row): ?int => is_numeric($row['average_first_call_seconds'] ?? null) ? (int) $row['average_first_call_seconds'] : null, $rows);

        return [
            count($rows) > 1 ? Cards::columns(__('manager_advanced.kpi.tickets'), $axis, [(string) __('manager_advanced.kpi.tickets')], [$tickets], 'number', null, 'half') : null,
            count($rows) > 1 && array_filter($waits) !== [] ? [
                'type' => 'line',
                'title' => (string) __('manager_advanced.kpi.first_call'),
                'span' => 'half',
                'badge' => null,
                'buckets' => $axis,
                'series' => ['label' => (string) __('manager_advanced.kpi.first_call'), 'values' => $waits],
                'format' => 'seconds',
            ] : null,
        ];
    }
}
