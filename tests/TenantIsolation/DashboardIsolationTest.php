<?php

declare(strict_types=1);

use App\Kernel\Time\DateRange;
use App\Modules\Booking\Application\DashboardAppointments;
use App\Modules\Customers\Application\DashboardNewestCustomers;
use App\Modules\Reports\Application\ManagerOverview;
use App\Modules\Sales\Application\DashboardRecentSales;

/*
|--------------------------------------------------------------------------
| The Manager overview, across tenants
|--------------------------------------------------------------------------
|
| Every figure and every "now" card on the dashboard is a read of the BOUND
| center's database. Center B's overview, next bookings, newest customers and
| latest invoices never contain anything Center A recorded.
|
*/

it('never shows one center\'s bookings, customers, sales or figures on another\'s overview', function (): void {
    $alpha = $this->registerCenter('Overview Alpha', 'owner@overview-alpha.test');
    $beta = $this->registerCenter('Overview Beta', 'owner@overview-beta.test');

    $this->asCenter($alpha['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->bookFor($seed, $owner, $this->seedCustomer('Alpha Only Amal', '0750 555 0303'), 1, '10:00');
        $this->issuedInvoice($seed, $owner);
        $this->walkInVisit($seed, $owner);

        expect(app(DashboardAppointments::class)->upcoming($owner))->toHaveCount(1)
            ->and(app(DashboardRecentSales::class)->latest($owner))->toHaveCount(1);
    });

    $this->asCenter($beta['tenant'], function (): void {
        $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sections = app(ManagerOverview::class)->build($owner, DateRange::resolve('today', null, null, 'Asia/Baghdad'), 'en')['sections'];

        expect(app(DashboardAppointments::class)->upcoming($owner))->toBe([])
            ->and(app(DashboardRecentSales::class)->latest($owner))->toBe([])
            ->and(array_column(app(DashboardNewestCustomers::class)->latest($owner), 'name'))->not->toContain('Alpha Only Amal')
            ->and($sections['sales']['billed'])->toBe(0)
            ->and($sections['visits']['arrivals'])->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
