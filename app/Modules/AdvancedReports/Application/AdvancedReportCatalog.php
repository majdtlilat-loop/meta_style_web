<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Application;

final class AdvancedReportCatalog
{
    /** @var array<string, array{title: string, description: string, permissions: list<string>}> */
    private const REPORTS = [
        'period_comparison' => ['title' => 'Period comparison', 'description' => 'Current performance against an equal immediately preceding period.', 'permissions' => ['appointment.view', 'journey.view', 'sale.view', 'payment.view', 'finance.view']],
        'branch_comparison' => ['title' => 'Branch comparison', 'description' => 'Identical local-calendar windows with totals and denominators per accessible branch.', 'permissions' => ['appointment.view', 'journey.view', 'sale.view', 'payment.view', 'finance.view']],
        'service_analysis' => ['title' => 'Service analysis', 'description' => 'Performed service mix, observed duration and change from the prior period.', 'permissions' => ['journey.view']],
        'employee_analysis' => ['title' => 'Employee analysis', 'description' => 'Actual-performer work, duration and sample coverage without a composite score.', 'permissions' => ['journey.view']],
        'booking_funnel_analysis' => ['title' => 'Booking funnel analysis', 'description' => 'Bookings to arrival and arrival to completion, with explicit denominators.', 'permissions' => ['appointment.view', 'journey.view']],
        'customer_cohorts' => ['title' => 'Customer cohorts', 'description' => 'New and returning customer mix compared with the prior period.', 'permissions' => ['customer.view', 'journey.view']],
        'customer_retention' => ['title' => 'Customer retention', 'description' => 'Observed returning-customer rate and visit frequency with sample size.', 'permissions' => ['customer.view', 'journey.view']],
        'customer_value' => ['title' => 'Customer value', 'description' => 'Anonymized net-collected customer value distribution from authoritative payments and refunds.', 'permissions' => ['customer.view', 'payment.view']],
        'queue_trends' => ['title' => 'Queue trends', 'description' => 'Daily ticket volume and observed waiting-time movement.', 'permissions' => ['queue.view']],
        'benefit_trends' => ['title' => 'Benefit trends', 'description' => 'Loyalty, package and membership usage against the prior period.', 'permissions' => ['loyalty.view', 'package.view', 'membership.view']],
    ];

    /** @return array<string, array{title: string, description: string, permissions: list<string>}> */
    public function all(): array
    {
        return self::REPORTS;
    }

    /** @return array{title: string, description: string, permissions: list<string>}|null */
    public function find(string $code): ?array
    {
        return self::REPORTS[$code] ?? null;
    }
}
