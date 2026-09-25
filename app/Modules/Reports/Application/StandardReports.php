<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Booking\Contracts\BookingReportReader;
use App\Modules\Customers\Contracts\CustomerReportReader;
use App\Modules\Finance\Contracts\FinanceReportReader;
use App\Modules\Loyalty\Contracts\BenefitReportReader;
use App\Modules\Payments\Contracts\PaymentReportReader;
use App\Modules\Queue\Contracts\QueueReportReader;
use App\Modules\Reports\Data\ReportKpi;
use App\Modules\Reports\Data\ReportResult;
use App\Modules\Reviews\Contracts\ReviewReportReader;
use App\Modules\Sales\Contracts\SalesReportReader;
use App\Modules\ServiceJourney\Contracts\JourneyReportReader;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

final readonly class StandardReports
{
    public function __construct(
        private ReportsAccess $access,
        private StandardReportCatalog $catalog,
        private BookingReportReader $bookings,
        private JourneyReportReader $journeys,
        private QueueReportReader $queue,
        private SalesReportReader $sales,
        private PaymentReportReader $payments,
        private FinanceReportReader $finance,
        private CustomerReportReader $customers,
        private ReviewReportReader $reviews,
        private BenefitReportReader $benefits,
    ) {}

    public function run(string $code, User $user, ReportReadRequest $request): ReportResult
    {
        $definition = $this->authorize($code, $user);

        // Only the narrowing this report supports reaches its readers.
        $request = new ReportReadRequest($request->target, $request->fromDate, $request->toDate, $request->windows, $this->catalog->filtersFor($code, $request->filters));

        return match ($code) {
            'business_overview' => $this->overview($definition, $request),
            'booking_activity' => $this->booking($definition, $request),
            'visit_service_delivery' => $this->visits($definition, $request),
            'employee_delivery' => $this->employees($definition, $request),
            'queue_operations' => $this->queue($definition, $request),
            'sales_payments' => $this->commerce($definition, $request),
            'finance_movements' => $this->finance($definition, $request),
            'customer_activity' => $this->customers($definition, $request),
            'review_summary' => $this->reviews($definition, $request),
            'benefit_usage' => $this->benefits($definition, $request),
            default => throw new InvalidArgumentException('Unknown report.'),
        };
    }

    /**
     * Everything a Standard report needs before a single fact is read: the
     * product (`reports_standard`), `report.view`, and every source-domain
     * permission the report lists. The page's analytics and the CSV run the
     * same check, so neither can show what the other would refuse.
     *
     * @return array{title: string, description: string, permissions: list<string>, filters: list<string>}
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException an unknown report code
     */
    public function authorize(string $code, User $user): array
    {
        $this->access->standard($user);
        $definition = $this->catalog->find($code);

        if ($definition === null) {
            throw new InvalidArgumentException('Unknown report.');
        }

        foreach ($definition['permissions'] as $permission) {
            $required = Permission::tryFrom($permission);

            if (! $required instanceof Permission || ! $user->hasPermission($required)) {
                throw new AuthorizationException('You may not view the source data required by this report.');
            }
        }

        return $definition;
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function overview(array $definition, ReportReadRequest $request): ReportResult
    {
        $bookings = $this->bookings->summary($request);
        $journeys = $this->journeys->summary($request);
        $sales = $this->sales->summary($request);
        $payments = $this->payments->summary($request);
        $finance = $this->finance->summary($request);
        $currency = $this->singleCurrency([...array_keys($sales['billed']), ...array_keys($payments['collected']), ...array_keys($finance['net'])]);

        return $this->result('business_overview', $definition, [
            new ReportKpi('scheduled_bookings', 'Scheduled bookings', $bookings['total']),
            new ReportKpi('completed_visits', 'Completed visits', $journeys['status']['completed'] ?? 0),
            new ReportKpi('walk_ins', 'Walk-ins', $journeys['sources']['walk_in'] ?? 0),
            new ReportKpi('billed', 'Billed', $this->money($sales['billed'], $currency), 'money_minor'),
            new ReportKpi('net_collected', 'Net collected', $this->money($payments['net'], $currency), 'money_minor'),
            new ReportKpi('net_movement', 'Net movement', $this->money($finance['net'], $currency), 'money_minor'),
        ], $this->series($bookings['daily']), [], $request, $currency, [
            'scheduled_bookings' => 'Appointments whose scheduled start falls in the selected branch-local period.',
            'completed_visits' => 'Service journeys marked completed after arrival in the selected period.',
            'billed' => 'Published invoice value excluding voided sales.',
            'net_collected' => 'Succeeded collections less succeeded refunds.',
            'net_movement' => 'All authoritative finance entries, inflows less outflows.',
        ], [], ['profitability' => 'Profitability and margin are unavailable because costs of service are not recorded.']);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function booking(array $definition, ReportReadRequest $request): ReportResult
    {
        $data = $this->bookings->summary($request);
        $kpis = [new ReportKpi('scheduled', 'Scheduled', $data['total']), new ReportKpi('created', 'Created', $data['created'])];

        foreach (['completed', 'cancelled', 'no_show'] as $status) {
            $kpis[] = new ReportKpi($status, ucfirst(str_replace('_', ' ', $status)), $data['status'][$status] ?? 0);
        }

        return $this->result('booking_activity', $definition, $kpis, $this->series($data['daily']), $this->dimensionRows($data['sources'], 'source'), $request, null, [
            'scheduled' => 'Appointments whose scheduled start is inside the selected branch-local dates.',
            'created' => 'Appointments created inside the selected branch-local dates.',
        ]);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function visits(array $definition, ReportReadRequest $request): ReportResult
    {
        $data = $this->journeys->summary($request);
        $stages = $data['stages'];

        return $this->result('visit_service_delivery', $definition, [
            new ReportKpi('arrivals', 'Arrivals', $data['total']),
            new ReportKpi('completed_visits', 'Completed visits', $data['status']['completed'] ?? 0),
            new ReportKpi('aborted_visits', 'Aborted visits', $data['status']['aborted'] ?? 0),
            new ReportKpi('walk_ins', 'Walk-ins', $data['sources']['walk_in'] ?? 0),
            new ReportKpi('completed_services', 'Completed services', $stages['completed'] ?? 0),
            new ReportKpi('service_minutes', 'Observed service minutes', $stages['minutes'] ?? 0),
        ], $this->series($data['daily']), $this->namedRows($stages['services'] ?? []), $request, null, [
            'service_minutes' => 'Elapsed minutes between actual stage start and completion.',
        ]);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function employees(array $definition, ReportReadRequest $request): ReportResult
    {
        $data = $this->journeys->summary($request);
        $employees = $data['stages']['employees'] ?? [];

        return $this->result('employee_delivery', $definition, [
            new ReportKpi('actual_performers', 'Actual performers', count($employees)),
            new ReportKpi('completed_services', 'Completed services', $data['stages']['completed'] ?? 0),
            new ReportKpi('service_minutes', 'Observed service minutes', $data['stages']['minutes'] ?? 0),
        ], [], $this->namedRows($employees), $request, null, [
            'actual_performer' => 'The employee recorded on the completed JourneyStage, never merely the booked employee.',
        ], ['employees' => count($employees)], ['employee_collection' => 'Collected revenue by employee is unavailable because payments are not attributed to performers.']);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function queue(array $definition, ReportReadRequest $request): ReportResult
    {
        $data = $this->queue->summary($request);

        return $this->result('queue_operations', $definition, [
            new ReportKpi('tickets', 'Tickets issued', $data['total']),
            new ReportKpi('closed', 'Tickets closed', $data['closed']),
            new ReportKpi('first_call', 'Average first call', $data['average_first_call_seconds'], 'duration_seconds'),
            new ReportKpi('service_start', 'Average time to service', $data['average_service_start_seconds'], 'duration_seconds'),
        ], [], $this->dimensionRows($data['states'], 'state'), $request, null, [
            'first_call' => 'Mean elapsed time from ticket issue to first call for tickets with a first call timestamp.',
            'service_start' => 'Mean elapsed time from ticket issue to actual service start for tickets with a service start timestamp.',
        ], ['first_call_sample' => $data['first_call_sample'], 'service_start_sample' => $data['service_start_sample']], ['queue_abandonment' => 'Queue abandonment is unavailable because no abandonment event is recorded.']);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function commerce(array $definition, ReportReadRequest $request): ReportResult
    {
        $sales = $this->sales->summary($request);
        $payments = $this->payments->summary($request);
        $currency = $this->singleCurrency([...$sales['currencies'], ...array_keys($payments['net'])]);
        $billed = $this->money($sales['billed'], $currency);

        return $this->result('sales_payments', $definition, [
            new ReportKpi('invoices', 'Invoices', $sales['invoices']),
            new ReportKpi('voided', 'Voided sales', $sales['voided']),
            new ReportKpi('billed', 'Billed', $billed, 'money_minor'),
            new ReportKpi('average_ticket', 'Average ticket', $this->average($billed, $currency !== null ? (int) ($sales['billed_invoices'][$currency] ?? 0) : 0), 'money_minor'),
            new ReportKpi('collected', 'Collected', $this->money($payments['collected'], $currency), 'money_minor'),
            new ReportKpi('refunded', 'Refunded', $this->money($payments['refunded'], $currency), 'money_minor'),
            new ReportKpi('net_collected', 'Net collected', $this->money($payments['net'], $currency), 'money_minor'),
            new ReportKpi('outstanding', 'Outstanding', $this->money($payments['outstanding'], $currency), 'money_minor'),
        ], [], $sales['items'], $request, $currency, [
            'outstanding' => 'Current unpaid balance of non-voided invoices issued in the selected period, using all succeeded settlements for those invoices. Refunds do not reopen an invoice.',
        ], ['currencies' => count($sales['currencies'])]);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function finance(array $definition, ReportReadRequest $request): ReportResult
    {
        $data = $this->finance->summary($request);
        $currency = $this->singleCurrency(array_keys($data['net']));

        return $this->result('finance_movements', $definition, [
            new ReportKpi('collections', 'Collections', $this->money($data['kinds']['collection'] ?? [], $currency), 'money_minor'),
            new ReportKpi('refunds', 'Refunds', $this->money($data['kinds']['refund'] ?? [], $currency), 'money_minor'),
            new ReportKpi('expenses', 'Expenses', $this->money($data['kinds']['expense'] ?? [], $currency), 'money_minor'),
            new ReportKpi('expense_reversals', 'Expense reversals', $this->money($data['kinds']['expense_reversal'] ?? [], $currency), 'money_minor'),
            new ReportKpi('net_movement', 'Net movement', $this->money($data['net'], $currency), 'money_minor'),
            new ReportKpi('reconciliation_variance', 'Reconciliation variance', $this->money($data['variance'], $currency), 'money_minor'),
        ], [], $this->moneyRows($data['methods']), $request, $currency, [
            'net_movement' => 'Finance ledger inflows less outflows; invoicing alone is not a money movement.',
        ]);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function customers(array $definition, ReportReadRequest $request): ReportResult
    {
        $data = $this->customers->summary($request);

        return $this->result('customer_activity', $definition, [
            new ReportKpi('customers', 'Customers served', $data['customers']),
            new ReportKpi('new', 'New customers', $data['new']),
            new ReportKpi('returning', 'Returning customers', $data['returning']),
            new ReportKpi('visits', 'Visits', $data['visits']),
            new ReportKpi('visit_frequency', 'Visits per customer', $data['visit_frequency'], 'decimal'),
        ], [], [], $request, null, [
            'new' => 'A customer with no earlier observed visit in the accessible branch scope.',
            'returning' => 'A customer with an earlier observed visit in the accessible branch scope.',
        ], ['customers' => $data['customers']], ['contact_details' => 'Contact details are intentionally excluded from reports and exports.']);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function reviews(array $definition, ReportReadRequest $request): ReportResult
    {
        $data = $this->reviews->summary($request);

        return $this->result('review_summary', $definition, [
            new ReportKpi('reviews', 'Visible reviews', $data['count']),
            new ReportKpi('average', 'Average rating', $data['average'], 'rating'),
        ], [], $this->dimensionRows($data['distribution'], 'rating'), $request, null, [
            'visible_reviews' => 'Submitted and flagged reviews. Hidden reviews do not contribute.',
        ], ['reviews' => $data['count']]);
    }

    /** @param array{title: string, description: string, permissions: list<string>, filters: list<string>} $definition */
    private function benefits(array $definition, ReportReadRequest $request): ReportResult
    {
        $data = $this->benefits->summary($request);
        $loyaltyIn = 0;
        $loyaltyOut = 0;

        foreach ($data['loyalty'] as $movement) {
            if ($movement['direction'] === 'in') {
                $loyaltyIn += $movement['points'];
            } else {
                $loyaltyOut += $movement['points'];
            }
        }

        return $this->result('benefit_usage', $definition, [
            new ReportKpi('loyalty_in', 'Loyalty points in', $loyaltyIn),
            new ReportKpi('loyalty_out', 'Loyalty points out', $loyaltyOut),
            new ReportKpi('package_redemptions', 'Package sessions redeemed', $data['packages']['redemption'] ?? 0),
            new ReportKpi('membership_uses', 'Membership benefit uses', $data['memberships']['use'] ?? 0),
            new ReportKpi('memberships_activated', 'Memberships activated', $data['activations']['memberships'] ?? 0),
            new ReportKpi('packages_activated', 'Packages activated', $data['activations']['packages'] ?? 0),
        ], [], [...$data['loyalty']], $request, null, [
            'coverage' => $data['coverage'],
        ]);
    }

    /**
     * @param  array{title: string, description: string, permissions: list<string>, filters: list<string>}  $definition
     * @param  list<ReportKpi>  $kpis
     * @param  list<array<string, int|float|string|null>>  $series
     * @param  list<array<string, int|float|string|null>>  $rows
     * @param  array<string, string>  $glossary
     * @param  array<string, int|float|string|null>  $coverage
     * @param  array<string, string>  $unavailable
     */
    private function result(string $code, array $definition, array $kpis, array $series, array $rows, ReportReadRequest $request, ?string $currency, array $glossary, array $coverage = [], array $unavailable = []): ReportResult
    {
        return new ReportResult($code, $definition['title'], $definition['description'], $kpis, $series, $rows, $glossary, [
            'from' => $request->fromDate,
            'to' => $request->toDate,
            'branches' => count($request->windows),
            ...$coverage,
        ], $currency, CarbonImmutable::now('UTC'), $unavailable);
    }

    /** @param array<string, int> $values
     * @return list<array{date: string, value: int}>
     */
    private function series(array $values): array
    {
        return array_map(static fn (string $date, int $value): array => ['date' => $date, 'value' => $value], array_keys($values), array_values($values));
    }

    /** @param array<int|string, int> $values
     * @return list<array<string, int|string>>
     */
    private function dimensionRows(array $values, string $dimension): array
    {
        return array_map(static fn (int|string $key, int $value): array => [$dimension => (string) $key, 'count' => $value], array_keys($values), array_values($values));
    }

    /** @param array<int, array{name: string, completed: int, minutes: int}> $values
     * @return list<array<string, int|string>>
     */
    private function namedRows(array $values): array
    {
        return array_values(array_map(static fn (array $row): array => $row, $values));
    }

    /** @param array<string, array<string, int>> $values
     * @return list<array<string, int|string>>
     */
    private function moneyRows(array $values): array
    {
        $rows = [];

        foreach ($values as $name => $currencies) {
            foreach ($currencies as $currency => $minor) {
                $rows[] = ['name' => $name, 'currency' => $currency, 'minor' => $minor];
            }
        }

        return $rows;
    }

    /**
     * The one currency every money figure is in, or null when there are
     * several (never added into a fictional total). A period with no money
     * at all is in the center's own currency, so a zero reads as a zero.
     *
     * @param  list<string>  $currencies
     */
    private function singleCurrency(array $currencies): ?string
    {
        $currencies = array_values(array_unique(array_filter($currencies)));

        if ($currencies === []) {
            return Currency::default()->value;
        }

        return count($currencies) === 1 ? $currencies[0] : null;
    }

    /**
     * Billed value over the NON-VOIDED invoices of the same currency — the
     * only denominator that matches the numerator.
     */
    private function average(?int $billed, int $invoices): ?int
    {
        return $billed !== null && $invoices > 0 ? (int) round($billed / $invoices) : null;
    }

    /** @param array<string, int> $amounts */
    private function money(array $amounts, ?string $currency): ?int
    {
        return $currency !== null ? ($amounts[$currency] ?? 0) : null;
    }
}
