<?php

declare(strict_types=1);

use App\Kernel\Reporting\ReadTarget;
use App\Modules\AdvancedReports\Application\AdvancedComparison;
use App\Modules\AdvancedReports\Application\AdvancedWorkspace;
use App\Modules\AdvancedReports\Application\PeriodWindows;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\View\AdvancedReports\AdvancedPeriod;
use App\View\AdvancedReports\Metrics;

/*
|--------------------------------------------------------------------------
| Advanced Reports, across tenants
|--------------------------------------------------------------------------
|
| The workspace and every comparison read the BOUND center's reporting
| database. Center B's figures never contain Center A's visits — not even
| when B asks by the uuid of A's employee or branch, or focuses on A's employee.
|
*/

it('never reads one center\'s facts into another center\'s workspace or comparison', function (): void {
    $alpha = $this->registerCenter('Advanced Alpha', 'owner@advanced-alpha.test');
    $beta = $this->registerCenter('Advanced Beta', 'owner@advanced-beta.test');

    $alphaIds = $this->asCenter($alpha['tenant'], function (): array {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $aya = $this->seedCustomer('Alpha Only Aya', '0750 555 4001');
        $this->customerVisit($seed, $owner, $aya);
        $this->customerVisit($seed, $owner, $aya);

        // Pinned: Alpha's own workspace does see its two visits.
        $period = AdvancedPeriod::resolve('today', null, null, 'previous', 'Asia/Baghdad');
        $current = app(ReportRequestFactory::class)->make($owner->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [], ReadTarget::Reporting);
        $workspace = app(AdvancedWorkspace::class)->build($owner, $current, PeriodWindows::shift($current, $period->compareFrom->toDateString(), $period->compareTo->toDateString()));
        expect($workspace['sections']['visits']['current']['total'])->toBe(2);

        // Pinned: focused on Alpha's own employee, the same two visits.
        $focused = app(AdvancedWorkspace::class)->build($owner, $current, PeriodWindows::shift($current, $period->compareFrom->toDateString(), $period->compareTo->toDateString()), ['employee' => (string) $seed['employee']->uuid]);
        expect($focused['sections']['visits']['current']['total'])->toBe(2);

        return ['branch' => $seed['branch']->uuid, 'employee' => $seed['employee']->uuid];
    });

    $this->asCenter($beta['tenant'], function () use ($alphaIds): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $period = AdvancedPeriod::resolve('today', null, null, 'previous', 'Asia/Baghdad');
        $current = app(ReportRequestFactory::class)->make($owner->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [], ReadTarget::Reporting);
        $comparison = PeriodWindows::shift($current, $period->compareFrom->toDateString(), $period->compareTo->toDateString());

        $workspace = app(AdvancedWorkspace::class)->build($owner, $current, $comparison);
        $now = Metrics::values(array_map(static fn (array $s): array => $s['current'], $workspace['sections']), 'IQD');

        expect($now['arrivals'])->toBe(0)
            ->and($now['customers'])->toBe(0);

        // Alpha's branch uuid is no window of Beta's; Alpha's employee does no work here.
        $branches = app(AdvancedComparison::class)->compare('branches', $owner, $current, [$alphaIds['branch'], $seed['branch']->uuid]);
        $employees = app(AdvancedComparison::class)->compare('employees', $owner, $current, [$alphaIds['employee']]);

        expect(array_column($branches['entities'], 'key'))->toBe([$seed['branch']->uuid])
            ->and(Metrics::values($employees['entities'][0]['facts'], 'IQD')['completed_services'])->toBe(0);

        // Beta's workspace focused on Alpha's employee reads nothing of Alpha's.
        $focused = app(AdvancedWorkspace::class)->build($owner, $current, $comparison, ['employee' => (string) $alphaIds['employee']]);

        expect($focused['sections']['visits']['current']['total'])->toBe(0)
            ->and($focused['sections']['bookings']['current']['total'])->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
