<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application\Analytics;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Booking\Contracts\BookingReportReader;
use App\Modules\Catalog\Contracts\ReportServiceCategories;
use App\Modules\Customers\Contracts\CustomerReportReader;
use App\Modules\Finance\Contracts\FinanceReportReader;
use App\Modules\Loyalty\Contracts\BenefitReportReader;
use App\Modules\Payments\Contracts\PaymentReportReader;
use App\Modules\Queue\Contracts\QueueReportReader;
use App\Modules\Reports\Application\ReportPeriod;
use App\Modules\Reviews\Contracts\ReviewReportReader;
use App\Modules\Sales\Contracts\SalesReportReader;
use App\Modules\ServiceJourney\Contracts\JourneyReportReader;

/**
 * One report view's reads: each source reader asked at most ONCE per period
 * (current and previous), however many cards use its answer.
 *
 * Holds the two authorized requests (branch scope already intersected, the
 * report's supported filters already applied) and the time buckets both
 * periods are drawn in. Optional cross-domain sections ask `may()` — a
 * permission the viewer holds — before they read anything.
 */
final class AnalyticsReads
{
    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    /**
     * @param  list<array{key: string, label: string}>  $buckets
     * @param  list<array{key: string, label: string}>  $previousBuckets
     */
    public function __construct(
        public readonly User $viewer,
        public readonly ReportPeriod $period,
        public readonly ReportReadRequest $current,
        public readonly ReportReadRequest $previous,
        public readonly array $buckets,
        public readonly array $previousBuckets,
        private readonly BookingReportReader $bookings,
        private readonly JourneyReportReader $journeys,
        private readonly SalesReportReader $sales,
        private readonly PaymentReportReader $payments,
        private readonly CustomerReportReader $customers,
        private readonly QueueReportReader $queue,
        private readonly BenefitReportReader $benefits,
        private readonly ReviewReportReader $reviews,
        private readonly FinanceReportReader $finance,
        private readonly Entitlements $entitlements,
        private readonly ReportServiceCategories $categories,
    ) {}

    /**
     * The menu category of each service, from the Catalog (ADR-037 keeps
     * categories out of Journey and Queue).
     *
     * @param  list<int>  $serviceIds
     * @return array<int, array{id: int, name: string}|null>
     */
    public function serviceCategories(array $serviceIds): array
    {
        return $this->categories->forServices($serviceIds, $this->current->target);
    }

    /** Whether the center's plan includes a feature (history stays readable without it). */
    public function owns(string $feature): bool
    {
        return $this->entitlements->enabled($feature);
    }

    public function may(Permission $permission): bool
    {
        return $this->viewer->hasPermission($permission);
    }

    /** Whether the period is drawn in hours (a single day). */
    public function hourly(): bool
    {
        return $this->buckets !== [] && strlen($this->buckets[0]['key']) === 13;
    }

    /**
     * Whether a filter beyond the branch narrows this report. A source that
     * cannot apply it (sales know no employee) must then stay out, rather
     * than show unfiltered figures beside filtered ones.
     */
    public function filtered(): bool
    {
        return array_filter($this->current->filters, static fn (mixed $value): bool => $value !== null && $value !== '') !== [];
    }

    /** How many branches the request reaches (after scope and the branch filter). */
    public function branchCount(): int
    {
        return count($this->current->windows);
    }

    /** @return array<int, string> branch id => name, in scope order */
    public function branchNames(): array
    {
        $names = [];

        foreach ($this->current->windows as $window) {
            $names[$window->branchId] = $window->branchName;
        }

        return $names;
    }

    /** @return array<string, mixed> */
    public function bookings(bool $previous = false): array
    {
        return $this->read('bookings', $previous, fn (ReportReadRequest $request): array => $this->bookings->summary($request));
    }

    /** @return array<string, mixed> */
    public function journeys(bool $previous = false): array
    {
        return $this->read('journeys', $previous, fn (ReportReadRequest $request): array => $this->journeys->summary($request));
    }

    /** @return array<string, mixed> */
    public function sales(bool $previous = false): array
    {
        return $this->read('sales', $previous, fn (ReportReadRequest $request): array => $this->sales->summary($request));
    }

    /**
     * Cash movement only (no outstanding balances or customer values).
     *
     * @return array<string, mixed>
     */
    public function payments(bool $previous = false): array
    {
        return $this->read('payments', $previous, fn (ReportReadRequest $request): array => $this->payments->totals($request));
    }

    /**
     * The full payment report, with `outstanding`.
     *
     * @return array<string, mixed>
     */
    public function paymentSummary(bool $previous = false): array
    {
        return $this->read('payment_summary', $previous, fn (ReportReadRequest $request): array => $this->payments->summary($request));
    }

    /** @return array<string, mixed> */
    public function customers(bool $previous = false): array
    {
        return $this->read('customers', $previous, fn (ReportReadRequest $request): array => $this->customers->summary($request));
    }

    /** @return array<string, mixed> */
    public function queue(bool $previous = false): array
    {
        return $this->read('queue', $previous, fn (ReportReadRequest $request): array => $this->queue->summary($request));
    }

    /** @return array<string, mixed> */
    public function benefits(bool $previous = false): array
    {
        return $this->read('benefits', $previous, fn (ReportReadRequest $request): array => $this->benefits->summary($request));
    }

    /** @return array<string, mixed> */
    public function reviews(bool $previous = false): array
    {
        return $this->read('reviews', $previous, fn (ReportReadRequest $request): array => $this->reviews->summary($request));
    }

    /** @return array<string, mixed> */
    public function finance(bool $previous = false): array
    {
        return $this->read('finance', $previous, fn (ReportReadRequest $request): array => $this->finance->summary($request));
    }

    /**
     * @param  callable(ReportReadRequest): array<string, mixed>  $reader
     * @return array<string, mixed>
     */
    private function read(string $source, bool $previous, callable $reader): array
    {
        $key = $source.($previous ? ':previous' : ':current');

        return $this->memo[$key] ??= $reader($previous ? $this->previous : $this->current);
    }
}
