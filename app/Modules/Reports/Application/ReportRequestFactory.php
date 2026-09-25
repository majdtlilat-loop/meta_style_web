<?php

declare(strict_types=1);

namespace App\Modules\Reports\Application;

use App\Kernel\Authorization\BranchScope;
use App\Kernel\Reporting\BranchWindow;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Branches\Contracts\ReportBranchReader;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final readonly class ReportRequestFactory
{
    public function __construct(private ReportBranchReader $branches) {}

    /**
     * @param  list<string>  $branchUuids
     * @param  array<string, int|string|null>  $filters
     */
    public function make(
        BranchScope $scope,
        string $fromDate,
        string $toDate,
        array $branchUuids,
        ReadTarget $target,
        array $filters = [],
    ): ReportReadRequest {
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $fromDate);
        $to = CarbonImmutable::createFromFormat('!Y-m-d', $toDate);

        if (! $from instanceof CarbonImmutable || ! $to instanceof CarbonImmutable || $to->isBefore($from)) {
            throw new InvalidArgumentException('The report date range is invalid.');
        }

        $days = $from->diffInDays($to) + 1;
        $maximum = max(1, (int) config('reports.interactive_max_days', 366));

        if ($days > $maximum) {
            throw new InvalidArgumentException("Interactive reports are limited to {$maximum} days.");
        }

        $branches = $this->branches->accessible($scope, $branchUuids, $target);
        $windows = array_map(static function (array $branch) use ($fromDate, $toDate): BranchWindow {
            $timezone = $branch['timezone'];
            $fromUtc = CarbonImmutable::parse($fromDate.' 00:00:00', $timezone)->utc();
            $untilUtc = CarbonImmutable::parse($toDate.' 00:00:00', $timezone)->addDay()->utc();

            return new BranchWindow(
                branchId: $branch['id'],
                branchUuid: $branch['uuid'],
                branchName: $branch['name'],
                timezone: $timezone,
                fromUtc: $fromUtc,
                untilUtc: $untilUtc,
            );
        }, $branches);

        return new ReportReadRequest($target, $fromDate, $toDate, $windows, $filters);
    }
}
