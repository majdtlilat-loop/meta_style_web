<?php

declare(strict_types=1);

use App\Livewire\Center\Reports\ReportsData;
use App\Modules\Catalog\Application\StandardReportCategoryOptions;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Reports\Application\ReportPeriod;

/*
|--------------------------------------------------------------------------
| Standard Reports analytics, across tenants
|--------------------------------------------------------------------------
|
| Every figure, dimension, top-customer name and filter option on the
| Standard Reports page is a read of the BOUND center's database, and the
| one-minute cache is keyed by tenant: Center B never sees a booking, a
| customer name, a category or a cached figure that belongs to Center A.
|
*/

it('never shows one center\'s analytics, customers, categories or cached figures to another', function (): void {
    $alpha = $this->registerCenter('Reports Alpha', 'owner@reports-alpha.test');
    $beta = $this->registerCenter('Reports Beta', 'owner@reports-beta.test');
    $alphaCategory = null;

    $this->asCenter($alpha['tenant'], function () use (&$alphaCategory): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->customerVisit($seed, $owner, $this->seedCustomer('Alpha Only Aras', '0750 555 0404'));
        $alphaCategory = ServiceCategory::query()->firstOrFail()->uuid;
        $today = $this->branchToday($seed['branch']);
        $period = ReportPeriod::resolve('custom', $today, $today, $seed['branch']->timezone);

        // Cached for a minute under Alpha's key.
        $customers = app(ReportsData::class)->get('customer_activity', $owner, $period, null, [], 'en');
        expect($customers['facts']['metrics']['customers']['current'])->toBe(1)
            ->and(array_column($customers['facts']['parts']['top'], 'name'))->toContain('Alpha Only Aras');
    });

    $this->asCenter($beta['tenant'], function () use ($alphaCategory): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $today = $this->branchToday($seed['branch']);
        $period = ReportPeriod::resolve('custom', $today, $today, $seed['branch']->timezone);

        $customers = app(ReportsData::class)->get('customer_activity', $owner, $period, null, [], 'en');
        $services = app(ReportsData::class)->get('visit_service_delivery', $owner, $period, null, [], 'en');

        expect($customers['facts']['metrics']['customers']['current'])->toBe(0)
            ->and($customers['facts']['parts']['top'])->toBe([])
            ->and($services['facts']['metrics']['completed_services']['current'])->toBe(0)
            ->and(array_column(app(StandardReportCategoryOptions::class)->all(), 'uuid'))->not->toContain($alphaCategory);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
