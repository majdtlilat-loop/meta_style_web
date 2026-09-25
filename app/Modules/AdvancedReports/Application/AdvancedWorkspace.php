<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Reports\Application\ReportsAccess;
use Carbon\CarbonImmutable;

/**
 * The Advanced insights workspace: every section the viewer may read, for
 * the selected period AND its comparison period, in one pass.
 *
 * Each reader runs exactly twice (current, comparison) and every card, chart
 * and table of the workspace is drawn from these two results — no per-card
 * query. The comparison request is re-derived from the authorized current
 * request, so it covers the very same branches on other dates.
 */
final readonly class AdvancedWorkspace
{
    public function __construct(
        private ReportsAccess $access,
        private SectionFacts $facts,
    ) {}

    /** The focus filters a workspace accepts (uuids the readers resolve in SQL). */
    public const FOCUS = ['employee', 'service'];

    /**
     * @param  array<string, string>  $focus  optional ['employee' => uuid] or
     *                                        ['service' => uuid]: only the facts that
     *                                        filter genuinely narrows are read — bookings
     *                                        (reserved lines), visits (performed stages)
     *                                        and queue. Sales, payments, customers,
     *                                        reviews and benefits are not attributed to
     *                                        an employee or a service (docs/28 §4), so a
     *                                        focused workspace does not read them.
     * @return array{sections: array<string, array{current: array<string, mixed>, previous: array<string, mixed>}>, period: array{from: string, to: string}, comparison: array{from: string, to: string}, branches: int, focus: array<string, string>, as_of: string}
     */
    public function build(User $user, ReportReadRequest $current, ReportReadRequest $comparison, array $focus = []): array
    {
        $this->access->advanced($user);

        $focus = array_filter(
            array_intersect_key($focus, array_flip(self::FOCUS)),
            static fn (string $uuid): bool => $uuid !== '',
        );

        if ($focus !== []) {
            $current = PeriodWindows::filtered($current, $focus);
        }

        $previous = PeriodWindows::shift($current, $comparison->fromDate, $comparison->toDate);
        $sections = [];

        foreach ($this->facts->permitted($user, $focus === [] ? null : AdvancedComparison::MODES['employees']) as $section) {
            $sections[$section] = [
                'current' => $this->facts->read($section, $current, true),
                'previous' => $this->facts->read($section, $previous),
            ];
        }

        return [
            'sections' => $sections,
            'period' => ['from' => $current->fromDate, 'to' => $current->toDate],
            'comparison' => ['from' => $previous->fromDate, 'to' => $previous->toDate],
            'branches' => count($current->windows),
            'focus' => $focus,
            'as_of' => CarbonImmutable::now('UTC')->toIso8601String(),
        ];
    }
}
