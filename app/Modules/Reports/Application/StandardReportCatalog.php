<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

/**
 * The Standard Reports, in the order a center reads them.
 *
 * `title` and `description` are the English identity used by the API, CSV
 * and RAYAN; a surface translates by the report CODE. `filters` names the
 * optional narrowing a report's readers actually support — a filter a
 * report ignores is never offered for it.
 */
final class StandardReportCatalog
{
    /** @var array<string, array{title: string, description: string, permissions: list<string>, filters: list<string>}> */
    private const REPORTS = [
        'business_overview' => ['title' => 'Business overview', 'description' => 'Bookings, visits, billed value, collection and expenses in one operational view.', 'permissions' => ['appointment.view', 'journey.view', 'sale.view', 'payment.view', 'finance.view'], 'filters' => []],
        'booking_activity' => ['title' => 'Booking activity', 'description' => 'Scheduled work, status outcomes and booking sources.', 'permissions' => ['appointment.view'], 'filters' => ['source', 'status', 'employee', 'service', 'category']],
        'visit_service_delivery' => ['title' => 'Visit & service delivery', 'description' => 'Arrivals, walk-ins, completed visits and performed services.', 'permissions' => ['journey.view'], 'filters' => ['employee', 'service']],
        'employee_delivery' => ['title' => 'Employee delivery', 'description' => 'Actual performers, completed work and observed service time.', 'permissions' => ['journey.view'], 'filters' => ['employee', 'service']],
        'queue_operations' => ['title' => 'Queue operations', 'description' => 'Ticket throughput and observed wait-to-call and wait-to-service times.', 'permissions' => ['queue.view'], 'filters' => ['employee', 'service']],
        'sales_payments' => ['title' => 'Sales & payments', 'description' => 'Invoices, collection, refunds, outstanding and payment methods.', 'permissions' => ['sale.view', 'payment.view'], 'filters' => []],
        'finance_movements' => ['title' => 'Finance movements', 'description' => 'Authoritative collection, refund, expense and reversal movements.', 'permissions' => ['finance.view'], 'filters' => []],
        'customer_activity' => ['title' => 'Customer activity', 'description' => 'New and returning customers with visit frequency, without contact details.', 'permissions' => ['customer.view', 'journey.view'], 'filters' => []],
        'review_summary' => ['title' => 'Review summary', 'description' => 'Visible review volume, rating distribution and supported dimensions.', 'permissions' => ['review.view'], 'filters' => []],
        'benefit_usage' => ['title' => 'Benefit usage', 'description' => 'Loyalty movements, package sessions and membership benefit use.', 'permissions' => ['loyalty.view', 'package.view', 'membership.view'], 'filters' => []],
    ];

    /** @return array<string, array{title: string, description: string, permissions: list<string>, filters: list<string>}> */
    public function all(): array
    {
        return self::REPORTS;
    }

    /** @return array{title: string, description: string, permissions: list<string>, filters: list<string>}|null */
    public function find(string $code): ?array
    {
        return self::REPORTS[$code] ?? null;
    }

    /**
     * Only the filters this report supports, from a caller's full set: a
     * stale `source` in a URL can never narrow a report that ignores it.
     *
     * @param  array<string, int|string|null>  $filters
     * @return array<string, int|string|null>
     */
    public function filtersFor(string $code, array $filters): array
    {
        $supported = self::REPORTS[$code]['filters'] ?? [];

        return array_filter(
            $filters,
            static fn (int|string|null $value, string $key): bool => in_array($key, $supported, true) && $value !== null && $value !== '',
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
