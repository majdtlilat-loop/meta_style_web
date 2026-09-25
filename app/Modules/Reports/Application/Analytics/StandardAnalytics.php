<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Time\DateRange;
use App\Modules\Booking\Contracts\BookingReportReader;
use App\Modules\Catalog\Contracts\ReportServiceCategories;
use App\Modules\Customers\Contracts\CustomerReportReader;
use App\Modules\Finance\Contracts\FinanceReportReader;
use App\Modules\Loyalty\Contracts\BenefitReportReader;
use App\Modules\Payments\Contracts\PaymentReportReader;
use App\Modules\Queue\Contracts\QueueReportReader;
use App\Modules\Reports\Application\ReportPeriod;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\StandardReportCatalog;
use App\Modules\Reports\Application\StandardReports;
use App\Modules\Reviews\Contracts\ReviewReportReader;
use App\Modules\Sales\Contracts\SalesReportReader;
use App\Modules\ServiceJourney\Contracts\JourneyReportReader;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/**
 * The analytics behind one Standard report view: the period and its
 * comparison, both read through the same authorized request factory as the
 * report itself (branch scope intersected, only the report's supported
 * filters applied), each source reader asked once per period.
 *
 * Returns FACTS — numbers in their units, series on the period's buckets,
 * dimensions with current and previous values. Wording, formatting and the
 * choice of chart belong to the page (App\View\Reports).
 */
final readonly class StandardAnalytics
{
    /** @var array<string, class-string> */
    private const VIEWS = [
        'business_overview' => OverviewFacts::class,
        'sales_payments' => SalesFacts::class,
        'booking_activity' => BookingFacts::class,
        'visit_service_delivery' => ServiceFacts::class,
        'employee_delivery' => TeamFacts::class,
        'customer_activity' => CustomerFacts::class,
        'benefit_usage' => BenefitFacts::class,
        'review_summary' => ReviewFacts::class,
        'queue_operations' => QueueFacts::class,
        'finance_movements' => FinanceFacts::class,
    ];

    public function __construct(
        private StandardReports $reports,
        private StandardReportCatalog $catalog,
        private ReportRequestFactory $requests,
        private BookingReportReader $bookings,
        private JourneyReportReader $journeys,
        private SalesReportReader $sales,
        private PaymentReportReader $payments,
        private CustomerReportReader $customers,
        private QueueReportReader $queue,
        private BenefitReportReader $benefits,
        private ReviewReportReader $reviews,
        private FinanceReportReader $finance,
        private Entitlements $entitlements,
        private ReportServiceCategories $categories,
    ) {}

    /**
     * @param  array<string, int|string|null>  $filters  anything the page holds; only the report's own reach the readers
     * @return array{code: string, filters: array<string, int|string|null>, branches: int, buckets: list<array{key: string, label: string}>, previous_buckets: list<array{key: string, label: string}>, facts: array<string, mixed>, as_of: string}
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function build(string $code, User $user, ReportPeriod $period, ?string $branchUuid, array $filters, string $locale): array
    {
        $this->reports->authorize($code, $user);
        $builder = self::VIEWS[$code] ?? throw new InvalidArgumentException('Unknown report.');
        $supported = $this->catalog->filtersFor($code, $filters);
        $branches = $branchUuid !== null && $branchUuid !== '' ? [$branchUuid] : [];

        $current = $this->requests->make($user->branchScope(), $period->current->from->toDateString(), $period->current->to->toDateString(), $branches, ReadTarget::Primary, $supported);
        $previous = $this->requests->make($user->branchScope(), $period->previous->from->toDateString(), $period->previous->to->toDateString(), $branches, ReadTarget::Primary, $supported);
        $buckets = self::labels($period->current->buckets($locale));
        $previousBuckets = self::sameGrain(self::labels($period->previous->buckets($locale)), $buckets, $period->previous, $locale);

        $reads = new AnalyticsReads(
            $user, $period, $current, $previous, $buckets, $previousBuckets,
            $this->bookings, $this->journeys, $this->sales, $this->payments, $this->customers,
            $this->queue, $this->benefits, $this->reviews, $this->finance, $this->entitlements, $this->categories,
        );

        /** @var OverviewFacts|SalesFacts|BookingFacts|ServiceFacts|TeamFacts|CustomerFacts|BenefitFacts|ReviewFacts|QueueFacts|FinanceFacts $view */
        $view = new $builder;

        return [
            'code' => $code,
            'filters' => $supported,
            'branches' => count($current->windows),
            'buckets' => $buckets,
            'previous_buckets' => $previousBuckets,
            'facts' => $view->build($reads),
            'as_of' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }

    /** Whether a report code has an analytics view. */
    public function supports(string $code): bool
    {
        return isset(self::VIEWS[$code]);
    }

    /**
     * @param  list<array{key: string, label: string, start: CarbonImmutable, end: CarbonImmutable}>  $buckets
     * @return list<array{key: string, label: string}>
     */
    private static function labels(array $buckets): array
    {
        return array_map(static fn (array $bucket): array => ['key' => $bucket['key'], 'label' => $bucket['label']], $buckets);
    }

    /**
     * The previous period in the CURRENT period's grain. The grain follows
     * the length (days up to 62, months beyond), and a comparison one day
     * longer or shorter — "this year" across a 29 February — can cross that
     * line; position-by-position alignment of months against days would
     * compare the wrong things.
     *
     * @param  list<array{key: string, label: string}>  $previous
     * @param  list<array{key: string, label: string}>  $current
     * @return list<array{key: string, label: string}>
     */
    private static function sameGrain(array $previous, array $current, DateRange $range, string $locale): array
    {
        if ($current === [] || $previous === [] || strlen($current[0]['key']) === strlen($previous[0]['key'])) {
            return $previous;
        }

        $monthly = strlen($current[0]['key']) === 7;
        $step = $monthly ? $range->from->startOfMonth() : $range->from;
        $rebuilt = [];

        for (; $step->lessThanOrEqualTo($range->to); $step = $monthly ? $step->addMonthNoOverflow() : $step->addDay()) {
            $rebuilt[] = $monthly
                ? ['key' => $step->format('Y-m'), 'label' => $step->locale($locale)->isoFormat('MMM YYYY')]
                : ['key' => $step->format('Y-m-d'), 'label' => $step->locale($locale)->isoFormat('D MMM')];
        }

        return $rebuilt;
    }
}
