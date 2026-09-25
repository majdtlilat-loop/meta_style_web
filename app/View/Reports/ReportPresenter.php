<?php

declare(strict_types=1);

namespace App\View\Reports;

use App\Kernel\Platform\Currencies\PlatformCurrencies;
use App\View\Label;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\Lang;

/**
 * A report result as the Standard and Advanced pages draw it.
 *
 * The domain returns stable KEYS alongside its English labels (the API, CSV
 * and RAYAN keep the English); everything a person reads is translated here
 * by key — report, KPI, column, glossary, coverage, unavailable — and every
 * cell is formatted by its column's type: money in its own currency, enum
 * values through their labels, durations, percentages, local dates. Raw
 * keys and minor units never reach the page.
 */
class ReportPresenter
{
    /** How a column is shown. Money uses the row's own `currency`, else the report's. */
    private const COLUMNS = [
        'source' => 'enum:source',
        'state' => 'enum:ticket_state',
        'direction' => 'enum:direction',
        'stage' => 'stage',
        'metric' => 'metric',
        'name' => 'text',
        'category' => 'text',
        'branch' => 'text',
        'date' => 'date',
        'rating' => 'rating_bucket',
        'count' => 'number',
        'quantity' => 'number',
        'completed' => 'number',
        'minutes' => 'number',
        'points' => 'number',
        'scheduled_bookings' => 'number',
        'completed_visits' => 'number',
        'walk_ins' => 'number',
        'previous_completed' => 'number',
        'previous_minutes' => 'number',
        'denominator' => 'number',
        'rank' => 'number',
        'tickets' => 'number',
        'total_minor' => 'money',
        'minor' => 'money',
        'billed_minor' => 'money',
        'net_collected_minor' => 'money',
        'collected_minor' => 'money',
        'refunded_minor' => 'money',
        'net_minor' => 'money',
        'current' => 'metric_value',
        'previous' => 'metric_value',
        'change_percent' => 'change',
        'completed_change_percent' => 'change',
        'average_first_call_seconds' => 'duration',
        'average_service_start_seconds' => 'duration',
    ];

    /** Carried for formatting, never shown as a column of their own. */
    private const HIDDEN = ['metric_key', 'stage_key', 'format', 'currency'];

    public function __construct(private readonly PlatformCurrencies $currencies) {}

    /**
     * @param  array<string, mixed>  $result  ReportResult::toArray()
     * @param  'standard'|'advanced'  $product
     * @return array<string, mixed>
     */
    public function present(array $result, string $product, string $locale, string $timezone): array
    {
        $code = (string) $result['code'];
        $currency = is_string($result['currency']) ? $result['currency'] : null;
        $glossary = [];

        foreach ($result['glossary'] as $key => $text) {
            $glossary[(string) $key] = $this->translated('manager_reports.glossary.'.$key, (string) $text);
        }

        $kpis = array_map(fn (array $kpi): array => [
            'key' => (string) $kpi['key'],
            'label' => $this->kpiLabel($code, (string) $kpi['key'], (string) $kpi['label']),
            'value' => $this->value($kpi['value'], (string) $kpi['format'], $currency),
            'money' => $kpi['format'] === 'money_minor' && $kpi['value'] !== null && $currency !== null ? ['minor' => (int) $kpi['value'], 'currency' => $currency] : null,
            'help' => $glossary[(string) $kpi['key']] ?? null,
        ], $result['kpis']);

        return [
            'code' => $code,
            'title' => $this->title($product, $code, (string) $result['title']),
            'kpis' => $kpis,
            'trend' => $this->trend($result['series'], (string) $result['coverage']['from'], (string) $result['coverage']['to'], $locale),
            'bars' => $this->bars($code, $result['rows'], $currency),
            'table' => $this->table($code, $result['rows'], $currency, $locale),
            'glossary' => array_map(fn (string $key, string $text): array => ['term' => $this->term($key), 'definition' => $text], array_keys($glossary), array_values($glossary)),
            'coverage' => $this->coverage($result['coverage'], $locale),
            'as_of' => CarbonImmutable::parse((string) $result['as_of'])->setTimezone($timezone)->locale($locale)->isoFormat('D MMM YYYY, HH:mm'),
            'as_of_iso' => (string) $result['as_of'],
            'unavailable' => array_map(
                fn (string $key, string $text): string => $this->translated('manager_reports.unavailable.'.$key, $text),
                $result['unavailable_keys'] ?? array_keys($result['unavailable']),
                $result['unavailable'],
            ),
            'mixed_currencies' => $currency === null && $this->hasMoney($result['kpis']),
        ];
    }

    public function title(string $product, string $code, string $fallback): string
    {
        return $this->translated('manager_reports.'.$product.'.'.$code.'.title', $fallback);
    }

    private function kpiLabel(string $code, string $key, string $fallback): string
    {
        if (Lang::has('manager_reports.kpi_for.'.$code.'.'.$key)) {
            return (string) __('manager_reports.kpi_for.'.$code.'.'.$key);
        }

        return $this->translated('manager_reports.kpi.'.$key, $fallback);
    }

    private function term(string $key): string
    {
        return $this->translated('manager_reports.terms.'.$key, $this->translated('manager_reports.kpi.'.$key, ucfirst(str_replace('_', ' ', $key))));
    }

    /**
     * A KPI value in its format. Money without a single currency is not a
     * number at all: mixed currencies are said, never summed.
     */
    private function value(mixed $value, string $format, ?string $currency): string
    {
        if ($value === null) {
            return $format === 'money_minor' && $currency === null ? __('manager_reports.format.mixed_currencies') : '—';
        }

        return match ($format) {
            'money_minor' => $currency !== null ? $this->money((int) $value, $currency) : __('manager_reports.format.mixed_currencies'),
            'duration_seconds' => $this->duration((int) $value),
            'percent' => __('manager_reports.format.percent', ['value' => number_format((float) $value, 1)]),
            'rating' => __('manager_reports.format.rating', ['value' => number_format((float) $value, 1)]),
            'decimal' => number_format((float) $value, 1),
            default => number_format((float) $value),
        };
    }

    private function money(int $minor, string $currency): string
    {
        return $this->currencies->format($minor, strtoupper($currency), app()->getLocale());
    }

    private function duration(int $seconds): string
    {
        return __('manager_reports.format.duration', ['minutes' => number_format(intdiv($seconds, 60)), 'seconds' => str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT)]);
    }

    /**
     * The daily series over EVERY day of the period (a day with nothing is a
     * zero, not a gap), in months beyond two months of days.
     *
     * @param  list<array<string, mixed>>  $series
     * @return array{buckets: list<array{label: string}>, values: list<int>, figure: string}|null
     */
    private function trend(array $series, string $from, string $to, string $locale): ?array
    {
        if ($series === []) {
            return null;
        }

        $values = [];
        foreach ($series as $point) {
            $values[(string) $point['date']] = ($values[(string) $point['date']] ?? 0) + (int) $point['value'];
        }

        $start = CarbonImmutable::parse($from);
        $end = CarbonImmutable::parse($to);
        $monthly = $start->diffInDays($end) + 1 > 62;
        $buckets = [];
        $points = [];

        foreach (CarbonPeriod::create($monthly ? $start->startOfMonth() : $start, $monthly ? '1 month' : '1 day', $monthly ? $end->startOfMonth() : $end) as $day) {
            $day = CarbonImmutable::instance($day);
            $key = $monthly ? $day->format('Y-m') : $day->toDateString();
            $buckets[] = ['label' => $monthly ? $day->locale($locale)->isoFormat('MMM YYYY') : $day->locale($locale)->isoFormat('D MMM')];
            $sum = 0;

            foreach ($values as $date => $value) {
                if (str_starts_with($date, $key)) {
                    $sum += $value;
                }
            }

            $points[] = $sum;
        }

        return ['buckets' => $buckets, 'values' => $points, 'figure' => number_format(array_sum($points))];
    }

    /**
     * The one ranked chart a report's rows support, if any.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{items: list<array<string, mixed>>, currency: string|null}|null
     */
    private function bars(string $code, array $rows, ?string $currency): ?array
    {
        if ($rows === []) {
            return null;
        }

        $single = $this->rowCurrency($rows);
        $items = match ($code) {
            'booking_activity' => $this->barItems($rows, fn (array $row): string => $this->enum('source', (string) $row['source']), 'count'),
            'queue_operations' => $this->barItems($rows, fn (array $row): string => $this->enum('ticket_state', (string) $row['state']), 'count'),
            'visit_service_delivery', 'employee_delivery', 'service_analysis', 'employee_analysis' => $this->barItems($rows, static fn (array $row): string => (string) $row['name'], 'completed',
                fn (array $row): ?string => isset($row['previous_completed']) ? __('manager_reports.chart.previous_value', ['value' => number_format((int) $row['previous_completed'])]) : null),
            'review_summary' => $this->barItems(array_reverse($rows), static fn (array $row): string => str_repeat('★', (int) $row['rating']), 'count', null, false),
            'booking_funnel_analysis' => $this->barItems($rows, fn (array $row): string => $this->stage($row), 'count', null, false),
            'branch_comparison' => $this->barItems($rows, static fn (array $row): string => (string) $row['branch'], 'scheduled_bookings'),
            'benefit_usage', 'benefit_trends' => isset($rows[0]['points']) ? $this->barItems($rows, fn (array $row): string => $this->enum('loyalty_kind', (string) $row['kind']).' · '.$this->enum('direction', (string) $row['direction']), 'points') : [],
            'sales_payments' => $single !== null ? $this->barItems($rows, static fn (array $row): string => (string) $row['name'], 'total_minor') : [],
            'finance_movements' => $single !== null ? $this->barItems($rows, fn (array $row): string => $this->enum('method', (string) $row['name']), 'minor') : [],
            default => [],
        };

        if ($items === []) {
            return null;
        }

        return ['items' => array_slice($items, 0, 10), 'currency' => in_array($code, ['sales_payments', 'finance_movements'], true) ? $single : null];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function barItems(array $rows, callable $label, string $field, ?callable $meta = null, bool $ranked = true): array
    {
        $items = [];

        foreach ($rows as $row) {
            $value = (float) ($row[$field] ?? 0);

            if ($value <= 0) {
                continue;
            }

            $items[] = ['label' => $label($row), 'value' => $value, 'meta' => $meta !== null ? $meta($row) : null];
        }

        // Ranked by size, except where the order IS the meaning (a funnel,
        // a rating scale).
        if ($ranked) {
            usort($items, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{columns: list<array{key: string, label: string, numeric: bool}>, rows: list<list<array<string, mixed>>>, caption: string}|null
     */
    private function table(string $code, array $rows, ?string $currency, string $locale): ?array
    {
        if ($rows === []) {
            return null;
        }

        $keys = array_values(array_filter(array_keys($rows[0]), static fn (string $key): bool => ! in_array($key, self::HIDDEN, true)));
        $columns = array_map(fn (string $key): array => [
            'key' => $key,
            'label' => $this->translated('manager_reports.columns_for.'.$code.'.'.$key, $this->translated('manager_reports.columns.'.$key, ucfirst(str_replace('_', ' ', $key)))),
            'numeric' => in_array($this->type($code, $key), ['number', 'money', 'metric_value', 'change', 'duration'], true),
        ], $keys);

        $cells = array_map(fn (array $row): array => array_map(fn (string $key): array => $this->cell($code, $key, $row, $currency, $locale), $keys), $rows);

        return ['columns' => $columns, 'rows' => $cells, 'caption' => trans_choice('manager_reports.sections.rows', count($cells), ['count' => number_format(count($cells))])];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{text: string, money: array{minor: int, currency: string}|null}
     */
    private function cell(string $code, string $key, array $row, ?string $currency, string $locale): array
    {
        $value = $row[$key] ?? null;
        $type = $this->type($code, $key);
        $rowCurrency = is_string($row['currency'] ?? null) && $row['currency'] !== '' ? (string) $row['currency'] : $currency;

        if ($value === null || $value === '') {
            return ['text' => $type === 'money' && $rowCurrency === null ? __('manager_reports.format.mixed_currencies') : '—', 'money' => null];
        }

        if ($type === 'money') {
            return $rowCurrency !== null
                ? ['text' => $this->money((int) $value, $rowCurrency), 'money' => ['minor' => (int) $value, 'currency' => $rowCurrency]]
                : ['text' => __('manager_reports.format.mixed_currencies'), 'money' => null];
        }

        if ($type === 'metric_value') {
            $format = (string) ($row['format'] ?? 'number');

            return $format === 'money_minor' && $rowCurrency !== null
                ? ['text' => $this->money((int) $value, $rowCurrency), 'money' => ['minor' => (int) $value, 'currency' => $rowCurrency]]
                : ['text' => $this->value($value, $format, $rowCurrency), 'money' => null];
        }

        $text = match (true) {
            str_starts_with($type, 'enum:') => $this->enum(substr($type, 5), (string) $value),
            $type === 'stage' => $this->stage($row),
            $type === 'metric' => $this->kpiLabel($this->metricReport($code), (string) ($row['metric_key'] ?? ''), (string) $value),
            $type === 'date' => CarbonImmutable::parse((string) $value)->locale($locale)->isoFormat('ddd D MMM YYYY'),
            $type === 'rating_bucket' => str_repeat('★', (int) $value),
            $type === 'number' => number_format((float) $value),
            $type === 'change' => (is_numeric($value) && (float) $value > 0 ? '+' : '').__('manager_reports.format.percent', ['value' => number_format((float) $value, 1)]),
            $type === 'duration' => $this->duration((int) $value),
            default => is_scalar($value) ? (string) $value : '—',
        };

        return ['text' => $text, 'money' => null];
    }

    private function type(string $code, string $key): string
    {
        return match (true) {
            $key === 'kind' && in_array($code, ['benefit_usage', 'benefit_trends'], true) => 'enum:loyalty_kind',
            $key === 'kind' => 'enum:kind',
            $key === 'name' && $code === 'finance_movements' => 'enum:method',
            default => self::COLUMNS[$key] ?? (is_numeric($key) ? 'number' : 'text'),
        };
    }

    /** The standard report whose KPIs an advanced comparison row names. */
    private function metricReport(string $code): string
    {
        return match ($code) {
            'period_comparison' => 'business_overview',
            'customer_cohorts' => 'customer_activity',
            'benefit_trends' => 'benefit_usage',
            default => $code,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function stage(array $row): string
    {
        $key = (string) ($row['stage_key'] ?? '');

        return $this->translated('manager_reports.values.stage.'.$key, (string) ($row['stage'] ?? $key));
    }

    private function enum(string $group, string $value): string
    {
        return match ($group) {
            'ticket_state' => Label::for('ticket_state', $value),
            'method' => $value === '' ? __('manager_reports.values.method_other') : Label::for('pos_payment_method', $value),
            default => $this->translated('manager_reports.values.'.$group.'.'.$value, $value === '' ? '—' : ucfirst(str_replace('_', ' ', $value))),
        };
    }

    /**
     * @param  array<string, mixed>  $coverage
     * @return list<array{label: string, value: string}>
     */
    private function coverage(array $coverage, string $locale): array
    {
        $items = [[
            'label' => __('manager_reports.coverage.period'),
            'value' => CarbonImmutable::parse((string) $coverage['from'])->locale($locale)->isoFormat('D MMM YYYY').' – '.CarbonImmutable::parse((string) $coverage['to'])->locale($locale)->isoFormat('D MMM YYYY'),
        ]];

        foreach ($coverage as $key => $value) {
            if (in_array($key, ['from', 'to'], true) || ! is_numeric($value)) {
                continue;
            }

            $items[] = [
                'label' => $this->translated('manager_reports.coverage.'.$key, ucfirst(str_replace('_', ' ', (string) $key))),
                'value' => number_format((float) $value),
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rowCurrency(array $rows): ?string
    {
        $codes = array_values(array_unique(array_filter(array_map(static fn (array $row): ?string => is_string($row['currency'] ?? null) ? $row['currency'] : null, $rows))));

        return count($codes) === 1 ? $codes[0] : null;
    }

    /**
     * @param  list<array<string, mixed>>  $kpis
     */
    private function hasMoney(array $kpis): bool
    {
        foreach ($kpis as $kpi) {
            if ($kpi['format'] === 'money_minor') {
                return true;
            }
        }

        return false;
    }

    private function translated(string $key, string $fallback): string
    {
        return Lang::has($key) ? (string) __($key) : $fallback;
    }
}
