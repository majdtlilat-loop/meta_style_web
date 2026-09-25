<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Reporting\ReadTarget;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\StandardReportCatalog;
use App\Modules\Reports\Application\StandardReports;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

it('runs every Standard Report on Primary through its source readers', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $user = $this->ownerWithCatalogAccess();
        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Primary);
        $reports = app(StandardReports::class);

        foreach (array_keys(app(StandardReportCatalog::class)->all()) as $code) {
            $result = $reports->run($code, $user, $request);

            expect($result->code)->toBe($code)
                ->and($result->asOf->timezoneName)->toBe('UTC');
        }
    });
});

it('keeps Standard Reports behind both entitlement and permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $user = $this->staffWith([Permission::AppointmentView]);
        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Primary);

        expect(fn () => app(StandardReports::class)->run('booking_activity', $user, $request))->toThrow(EntitlementRequired::class);

        $this->grantEntitlement('reports_standard', null);

        expect(fn () => app(StandardReports::class)->run('booking_activity', $user, $request))->toThrow(AuthorizationException::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
