<?php

declare(strict_types=1);

namespace App\View\Reports;

use App\Modules\Reports\Application\ReportPeriod;
use App\View\Charts\ValueFormat;
use App\View\Reports\Standard\Cards;
use App\View\Reports\Standard\CommerceLayouts;
use App\View\Reports\Standard\CustomerLayouts;
use App\View\Reports\Standard\OperationsLayouts;
use Carbon\CarbonImmutable;

/**
 * A Standard report view as the page draws it: KPI tiles with their
 * comparison, then sections of chart cards and detail tables in the order a
 * manager reads them (trends, distribution, comparison, peak times, details).
 *
 * A period with no activity is one clean empty state, not a wall of empty
 * charts; its KPI tiles stay only when the previous period had something to
 * compare with.
 */
final class StandardReportView
{
    public function __construct(
        private readonly CommerceLayouts $commerce,
        private readonly OperationsLayouts $operations,
        private readonly CustomerLayouts $customers,
    ) {}

    /**
     * @param  array<string, mixed>  $analytics  StandardAnalytics::build()
     * @param  callable(string): ?string  $customerUrl
     * @return array{code: string, empty: bool, kpis: list<array<string, mixed>>, sections: list<array<string, mixed>>, currency: string|null, other_currencies: list<string>, comparison: string, as_of: string, as_of_iso: string}
     */
    public function present(array $analytics, ReportPeriod $period, string $locale, callable $customerUrl): array
    {
        $facts = $analytics['facts'];
        $comparison = (string) __('manager_reports.std.compare.'.$period->comparisonKey());
        $cards = new Cards($analytics['buckets'], $analytics['previous_buckets'], $facts['currency'], $comparison);
        $timezone = $period->current->timezone;

        $layout = match ($analytics['code']) {
            'business_overview' => $this->commerce->overview($facts, $cards),
            'sales_payments' => $this->commerce->sales($facts, $cards),
            'finance_movements' => $this->commerce->finance($facts, $cards),
            'booking_activity' => $this->operations->bookings($facts, $cards, $locale),
            'visit_service_delivery' => $this->operations->services($facts, $cards),
            'employee_delivery' => $this->operations->team($facts, $cards),
            'queue_operations' => $this->operations->queue($facts, $cards, $locale),
            'customer_activity' => $this->customers->customers($facts, $cards, $locale, $timezone, $customerUrl),
            'benefit_usage' => $this->customers->benefits($facts, $cards),
            'review_summary' => $this->customers->reviews($facts, $cards),
            default => ['kpis' => [], 'sections' => []],
        };

        $empty = (bool) $facts['empty'];
        $comparable = array_filter($layout['kpis'], static fn (array $kpi): bool => $kpi['previous'] !== null && (float) $kpi['previous'] !== 0.0) !== [];
        $asOf = CarbonImmutable::parse((string) $analytics['as_of'])->setTimezone($timezone);

        return [
            'code' => (string) $analytics['code'],
            'empty' => $empty,
            'kpis' => $empty && ! $comparable ? [] : $layout['kpis'],
            'sections' => $empty ? [] : $layout['sections'],
            'currency' => $facts['currency'],
            'other_currencies' => array_map(
                static fn (array $other): string => ValueFormat::make('money', $other['currency'], $locale)->full($other['minor']),
                $facts['other_currencies'],
            ),
            'comparison' => $comparison,
            'as_of' => $asOf->locale($locale)->isoFormat('HH:mm'),
            'as_of_iso' => $asOf->toIso8601String(),
        ];
    }
}
