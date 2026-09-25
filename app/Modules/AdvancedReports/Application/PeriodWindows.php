<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Application;

use App\Kernel\Reporting\BranchWindow;
use App\Kernel\Reporting\ReportReadRequest;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Re-windows an AUTHORIZED report request without ever widening it.
 *
 * Every Advanced comparison starts from one request built by
 * ReportRequestFactory (branch scope already intersected). A comparison
 * period, a single branch or an entity filter is derived from that request
 * here — the same branches, other dates; fewer branches; a narrower filter —
 * so a comparison can never read a branch the current period could not.
 */
final class PeriodWindows
{
    /**
     * The same branches on other inclusive local dates, each converted
     * through that branch's own timezone.
     *
     * @param  array<string, int|string|null>|null  $filters  null keeps the request's filters
     */
    public static function shift(ReportReadRequest $request, string $fromDate, string $toDate, ?array $filters = null): ReportReadRequest
    {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $toDate);

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $to->lessThan($from)) {
            throw new InvalidArgumentException('The comparison date range is invalid.');
        }

        $windows = array_map(static fn (BranchWindow $window): BranchWindow => new BranchWindow(
            $window->branchId,
            $window->branchUuid,
            $window->branchName,
            $window->timezone,
            CarbonImmutable::parse($from->toDateString().' 00:00:00', $window->timezone)->utc(),
            CarbonImmutable::parse($to->toDateString().' 00:00:00', $window->timezone)->addDay()->utc(),
        ), $request->windows);

        return new ReportReadRequest($request->target, $from->toDateString(), $to->toDateString(), $windows, $filters ?? $request->filters);
    }

    /** The equal, immediately preceding period (the API default). */
    public static function previous(ReportReadRequest $request): ReportReadRequest
    {
        $from = CarbonImmutable::parse($request->fromDate);
        $days = (int) $from->diffInDays(CarbonImmutable::parse($request->toDate)) + 1;
        $previousTo = $from->subDay();

        return self::shift($request, $previousTo->subDays($days - 1)->toDateString(), $previousTo->toDateString());
    }

    /**
     * Only the listed branches — a subset of the request's own windows, in
     * the order asked. An unknown or out-of-scope uuid simply matches nothing.
     *
     * @param  list<string>  $branchUuids
     */
    public static function only(ReportReadRequest $request, array $branchUuids): ReportReadRequest
    {
        $windows = [];

        foreach ($branchUuids as $uuid) {
            foreach ($request->windows as $window) {
                if ($window->branchUuid === $uuid) {
                    $windows[] = $window;
                }
            }
        }

        return new ReportReadRequest($request->target, $request->fromDate, $request->toDate, $windows, $request->filters);
    }

    /**
     * The same request narrowed by one more filter (an employee or a service
     * uuid, resolved inside each reader's branch-scoped SQL).
     *
     * @param  array<string, int|string|null>  $filters
     */
    public static function filtered(ReportReadRequest $request, array $filters): ReportReadRequest
    {
        return new ReportReadRequest($request->target, $request->fromDate, $request->toDate, $request->windows, [...$request->filters, ...$filters]);
    }
}
