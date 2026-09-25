<?php

declare(strict_types=1);

namespace App\View\AdvancedReports;

use App\View\Charts\ValueFormat;
use App\View\Label;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * One catalog report (the API / CSV / RAYAN identity) for the Reports view:
 * translated KPIs (with the comparison value where the report carries one),
 * the charts that fit its rows, a typed detail table, and its coverage,
 * definitions and unavailable metrics — translated by stable key, the domain
 * keeps its English for the API, CSV and RAYAN.
 */
final class LibraryPresenter
{
    private const MONEY = ['billed_minor', 'net_collected_minor', 'total_minor', 'minor', 'collected_minor', 'refunded_minor', 'net_minor'];

    private const CHANGE = ['change_percent', 'completed_change_percent'];

    private const SECONDS = ['average_first_call_seconds', 'average_service_start_seconds'];

    private const MINUTES = ['minutes', 'previous_minutes'];

    private const HIDDEN = ['format', 'metric_key', 'stage_key', 'currency'];

    /** Rows printed in the detail table; the CSV carries every row. */
    private const MAX_ROWS = 200;

    public function __construct(private readonly string $locale) {}

    /**
     * @param  array<string, mixed>  $result  ReportResult::toArray()
     * @return array<string, mixed>
     */
    public function present(array $result): array
    {
        $code = (string) $result['code'];
        $currency = is_string($result['currency'] ?? null) ? $result['currency'] : null;
        $rows = (array) $result['rows'];
        $comparison = array_filter([(string) ($result['coverage']['comparison_from'] ?? ''), (string) ($result['coverage']['comparison_to'] ?? '')]);
        $columns = $this->columns($code, $rows);
        $shown = array_slice($rows, 0, self::MAX_ROWS);

        return [
            'code' => $code,
            'title' => (string) __('manager_advanced.reports.'.$code),
            'currency' => $currency,
            'kpis' => $this->kpis($code, $result, $rows, $currency),
            'cards' => (new LibraryCharts($currency))->for($code, $result),
            'table' => $columns === [] ? null : $this->table($columns, $shown, $currency),
            'row_count' => count($rows),
            'comparison' => count($comparison) === 2 ? CarbonImmutable::parse($comparison[0])->locale($this->locale)->isoFormat('D MMM').' – '.CarbonImmutable::parse($comparison[1])->locale($this->locale)->isoFormat('D MMM YYYY') : null,
            'glossary' => $this->notes('glossary', (array) $result['glossary']),
            'unavailable' => $this->notes('unavailable', array_combine((array) $result['unavailable_keys'], (array) $result['unavailable']) ?: []),
            'as_of' => CarbonImmutable::parse((string) $result['as_of'])->locale($this->locale)->isoFormat('D MMM YYYY, HH:mm'),
        ];
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function kpis(string $code, array $result, array $rows, ?string $currency): array
    {
        $previous = [];

        foreach ($rows as $row) {
            if (isset($row['metric_key'])) {
                $previous[(string) $row['metric_key']] = is_numeric($row['previous'] ?? null) ? $row['previous'] + 0 : null;
            }
        }

        return array_map(function (array $kpi) use ($code, $previous, $currency): array {
            $key = (string) $kpi['key'];
            $format = $this->format((string) $kpi['format']);

            return [
                'label' => $this->kpiLabel($code, $key),
                'current' => is_numeric($kpi['value']) ? $kpi['value'] + 0 : null,
                'previous' => $previous[$key] ?? null,
                'format' => $format,
                'currency' => $format === 'money' ? $currency : null,
                'higher' => self::higher($key),
                'value' => $format === 'money' && $currency === null ? (string) __('manager_advanced.values.mixed_currencies') : ((string) $kpi['format'] === 'rating' && is_numeric($kpi['value']) ? number_format((float) $kpi['value'], 1).' / 5' : null),
            ];
        }, (array) $result['kpis']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{key: string, label: string, numeric: bool}>
     */
    private function columns(string $code, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $columns = [];

        foreach (array_keys($rows[0]) as $key) {
            if (in_array($key, self::HIDDEN, true)) {
                continue;
            }

            $override = 'manager_advanced.columns_for.'.$code.'.'.$key;
            $columns[] = [
                'key' => (string) $key,
                'label' => Lang::has($override) ? (string) __($override) : (Lang::has('manager_advanced.columns.'.$key) ? (string) __('manager_advanced.columns.'.$key) : Str::headline((string) $key)),
                'numeric' => ! in_array($key, ['name', 'branch', 'metric', 'stage', 'source', 'state', 'kind', 'direction', 'date'], true),
            ];
        }

        return $columns;
    }

    /**
     * The detail table for the sortable partial: formatted text per cell and
     * the raw value it sorts by (numbers as numbers, dates as ISO dates, any
     * other text as shown in the viewer's language).
     *
     * @param  list<array{key: string, label: string, numeric: bool}>  $columns
     * @param  list<array<string, mixed>>  $rows
     * @return array{title: string, columns: list<array{key: string, label: string, numeric: bool}>, rows: list<list<string>>, sort: list<list<int|float|string|null>>, page_size: int}
     */
    private function table(array $columns, array $rows, ?string $currency): array
    {
        $text = [];
        $sort = [];

        foreach ($rows as $row) {
            $cells = $this->row($row, $currency);
            $line = [];
            $keys = [];

            foreach ($columns as $column) {
                $raw = $row[$column['key']] ?? null;
                $shown = $cells[$column['key']] ?? '—';
                $line[] = $shown;
                $keys[] = match (true) {
                    $raw === null || $raw === '' => null,
                    is_numeric($raw) && $column['numeric'] => $raw + 0,
                    $column['key'] === 'date' => (string) $raw,
                    default => $shown,
                };
            }

            $text[] = $line;
            $sort[] = $keys;
        }

        return ['title' => (string) __('manager_advanced.library.detail'), 'columns' => $columns, 'rows' => $text, 'sort' => $sort, 'page_size' => 15];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, string>
     */
    private function row(array $row, ?string $reportCurrency): array
    {
        $cells = [];
        $rowCurrency = is_string($row['currency'] ?? null) && $row['currency'] !== '' ? $row['currency'] : $reportCurrency;

        foreach ($row as $key => $value) {
            if (in_array($key, self::HIDDEN, true)) {
                continue;
            }

            $cells[(string) $key] = match (true) {
                $value === null || $value === '' => '—',
                $key === 'metric' => $this->kpiLabel('', (string) ($row['metric_key'] ?? ''), (string) $value),
                $key === 'stage' => Lang::has('manager_advanced.funnel.'.($row['stage_key'] ?? '')) ? (string) __('manager_advanced.funnel.'.$row['stage_key']) : (string) $value,
                $key === 'source' => (string) __('manager_advanced.source.'.$value),
                $key === 'state' => Label::for('ticket_state', (string) $value),
                $key === 'kind' => Lang::has('manager_advanced.kind.'.$value) ? (string) __('manager_advanced.kind.'.$value) : (Lang::has('manager_advanced.loyalty.'.$value) ? (string) __('manager_advanced.loyalty.'.$value) : Str::headline((string) $value)),
                $key === 'direction' => (string) __('manager_advanced.direction.'.$value),
                $key === 'date' => CarbonImmutable::parse((string) $value)->locale($this->locale)->isoFormat('D MMM YYYY'),
                $key === 'rating' => trans_choice('manager_advanced.values.stars', (int) $value, ['count' => (int) $value]),
                in_array($key, ['current', 'previous'], true) => $this->typed($value, $this->format((string) ($row['format'] ?? 'number')), $reportCurrency),
                in_array($key, self::MONEY, true) => $this->typed($value, 'money', $rowCurrency),
                in_array($key, self::CHANGE, true) => ValueFormat::signedPercent((float) $value),
                in_array($key, self::SECONDS, true) => $this->typed($value, 'seconds', null),
                in_array($key, self::MINUTES, true) => $this->typed($value, 'duration', null),
                is_numeric($value) => ValueFormat::make()->full($value + 0),
                default => (string) $value,
            };
        }

        return $cells;
    }

    private function typed(mixed $value, string $format, ?string $currency): string
    {
        if (! is_numeric($value)) {
            return '—';
        }

        if ($format === 'money' && $currency === null) {
            return (string) __('manager_advanced.values.mixed_currencies');
        }

        return ValueFormat::make($format, $currency)->full($value + 0);
    }

    /**
     * Whether more of a catalog KPI is good news (true), bad news (false) or
     * neither (null). A metric the workspace defines keeps that judgement —
     * including "no judgement": walk-ins, queue tickets or service minutes
     * going up are never painted green just because they rose.
     */
    private static function higher(string $key): ?bool
    {
        if (array_key_exists($key, Metrics::DEFINITIONS)) {
            return Metrics::higherIsBetter($key);
        }

        return match (true) {
            in_array($key, ['cancelled', 'no_show', 'refunds'], true) => false,
            in_array($key, ['actual_performers', 'branches', 'net_movement', 'loyalty_in', 'loyalty_out', 'expenses', 'expense_reversals', 'reconciliation_variance', 'previous_return_rate'], true) => null,
            default => true,
        };
    }

    /** The chart value format for a domain KPI format. */
    private function format(string $format): string
    {
        return match ($format) {
            'money_minor' => 'money',
            'percent' => 'percent',
            'duration_seconds' => 'seconds',
            default => 'number',
        };
    }

    private function kpiLabel(string $code, string $key, ?string $fallback = null): string
    {
        foreach (['manager_advanced.kpi_for.'.$code.'.'.$key, 'manager_advanced.kpi.'.$key, 'manager_advanced.metric.'.$key] as $candidate) {
            if ($key !== '' && Lang::has($candidate)) {
                return (string) __($candidate);
            }
        }

        return $fallback ?? Str::headline($key);
    }

    /**
     * Definitions / unavailable statements translated by key, the English
     * domain text when a key has no translation yet.
     *
     * @param  array<array-key, mixed>  $entries
     * @return list<array{term: string, text: string}>
     */
    private function notes(string $group, array $entries): array
    {
        $notes = [];

        foreach ($entries as $key => $text) {
            if ($key === 'comparison') {
                continue;
            }

            $translated = 'manager_advanced.'.$group.'.'.$key;
            $notes[] = [
                'term' => Lang::has('manager_advanced.terms.'.$key) ? (string) __('manager_advanced.terms.'.$key) : $this->kpiLabel('', (string) $key, Str::headline((string) $key)),
                'text' => Lang::has($translated) ? (string) __($translated) : (string) $text,
            ];
        }

        return $notes;
    }
}
