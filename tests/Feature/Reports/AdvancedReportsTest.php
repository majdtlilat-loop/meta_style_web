<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Reporting\ReadTarget;
use App\Modules\AdvancedReports\Application\AdvancedReportCatalog;
use App\Modules\AdvancedReports\Application\AdvancedReports;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Reports\Application\ReportRequestFactory;
use Carbon\CarbonImmutable;

it('runs every Advanced Report on Reporting and never needs the RAYAN entitlement', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $user = $this->ownerWithCatalogAccess();
        $this->configureReportingConnection();

        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Reporting);

        foreach (array_keys(app(AdvancedReportCatalog::class)->all()) as $code) {
            expect(app(AdvancedReports::class)->run($code, $user, $request)->code)->toBe($code);
        }

        expect(app(Entitlements::class)->enabled('rayan_ai'))->toBeFalse();
    });
});

it('builds reporting windows only for branches in the users scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $main = Branch::query()->where('is_main', true)->firstOrFail();
        $this->seedBranch();
        $manager = $this->seedStaffMember(SystemRole::Manager, [(int) $main->getKey()]);
        $this->configureReportingConnection();
        $today = CarbonImmutable::now()->toDateString();

        $request = app(ReportRequestFactory::class)->make($manager->branchScope(), $today, $today, [], ReadTarget::Reporting);

        expect($request->windows)->toHaveCount(1)
            ->and($request->windows[0]->branchId)->toBe((int) $main->getKey());
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
