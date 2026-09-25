<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Time\DateRange;
use App\Modules\Reports\Application\ManagerOverview;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\StandardReports;
use App\Modules\Sales\Application\Actions\CloseSale;
use Carbon\CarbonImmutable;

/*
|--------------------------------------------------------------------------
| The Manager overview
|--------------------------------------------------------------------------
|
| A section exists only with its permission AND the center's entitlement,
| inside the viewer's branch scope; the average ticket divides billed value
| by the NON-VOIDED invoices of that currency (the Standard Reports' own
| definition); and a one-day range is drawn in hours.
|
*/

function overviewToday(string $timezone = 'Asia/Baghdad'): DateRange
{
    return DateRange::resolve('today', null, null, $timezone);
}

it('shows a section only with its permission and the center entitlement', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        // Resolved afresh for every read, as each request is: the entitlement
        // resolver memoises per instance, and the plan changes in between.
        $sections = fn (): array => app(ManagerOverview::class)->build($owner, overviewToday(), 'en')['sections'];

        // The trial plan sells booking and pos; nobody sells the queue by default.
        expect(array_keys($sections()))->toContain('bookings', 'visits', 'sales', 'payments', 'customers')
            ->and(array_key_exists('queue', $sections()))->toBeFalse();

        $this->grantQueueEntitlements(['queue_management']);
        expect(array_key_exists('queue', $sections()))->toBeTrue();

        // Losing `pos` removes BOTH money sections — payments included.
        $this->revokeEntitlement('pos');
        $withoutPos = $sections();
        expect(array_key_exists('sales', $withoutPos))->toBeFalse()
            ->and(array_key_exists('payments', $withoutPos))->toBeFalse()
            ->and(array_key_exists('bookings', $withoutPos))->toBeTrue();
    });
});

it('gates customers on customer AND journey access, and bookings on the broad appointment view', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();
        $overview = app(ManagerOverview::class);

        $customersOnly = $this->staffWith([Permission::CustomerView], 'customers@overview.test');
        $both = $this->staffWith([Permission::CustomerView, Permission::JourneyView], 'both@overview.test');
        $ownOnly = $this->staffWith([Permission::AppointmentViewOwn], 'own@overview.test');

        expect($overview->build($customersOnly, overviewToday(), 'en')['sections'])->toBe([])
            ->and(array_keys($overview->build($both, overviewToday(), 'en')['sections']))->toBe(['visits', 'customers'])
            // A view-own employee never sees the whole book's counts.
            ->and($overview->build($ownOnly, overviewToday(), 'en')['sections'])->toBe([]);
    });
});

it('counts only the branches in scope, and a chosen branch only narrows', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $second = $this->seedBranch();
        $this->openEveryDay($second);
        $owner = $this->ownerWithCatalogAccess();

        $this->walkInVisit($seed, $owner);
        $this->walkInVisit(['branch' => $second, 'service' => $seed['service']], $owner);

        $manager = $this->seedStaffMember(SystemRole::Manager, [(int) $seed['branch']->getKey()]);
        $overview = app(ManagerOverview::class);

        expect($overview->build($owner, overviewToday(), 'en')['sections']['visits']['arrivals'])->toBe(2)
            ->and($overview->build($owner, overviewToday(), 'en', $second->uuid)['sections']['visits']['arrivals'])->toBe(1)
            ->and($overview->build($manager, overviewToday(), 'en')['sections']['visits']['arrivals'])->toBe(1)
            // Asking for a branch outside the scope yields nothing, never more.
            ->and($overview->build($manager, overviewToday(), 'en', $second->uuid)['sections']['visits']['arrivals'])->toBe(0);
    });
});

it('averages billed value over non-voided invoices, exactly like the Standard Reports', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $this->issuedInvoice($seed, $owner);
        $this->issuedInvoice($seed, $owner);
        $voided = $this->issuedInvoice($seed, $owner);
        app(CloseSale::class)->void($voided->sale()->firstOrFail(), $owner, 'Entered twice');

        $sales = app(ManagerOverview::class)->build($owner, overviewToday(), 'en')['sections']['sales'];

        // 2 × 20,000 billed over 2 non-voided invoices — not over all 3.
        expect($sales['billed'])->toBe(40000)
            ->and($sales['invoices'])->toBe(2)
            ->and($sales['voided'])->toBe(1)
            ->and($sales['average'])->toBe(20000);

        $today = $this->branchToday($seed['branch']);
        $request = app(ReportRequestFactory::class)->make($owner->branchScope(), $today, $today, [], ReadTarget::Primary);
        $report = app(StandardReports::class)->run('sales_payments', $owner, $request);
        $average = collect($report->kpis)->firstWhere('key', 'average_ticket')?->value;

        expect($average)->toBe($sales['average']);
    });
});

it('draws a one-day range in 24 hourly buckets, placed on the branch clock', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter('Asia/Baghdad');
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer('Hourly Huda', '0750 555 0101');
        $appointment = $this->bookFor($seed, $owner, $customer, 1, '10:00');

        // "Today" as seen tomorrow at noon, Baghdad time.
        $day = CarbonImmutable::parse($appointment->localDate().' 12:00', 'Asia/Baghdad');
        $range = DateRange::resolve('today', null, null, 'Asia/Baghdad', $day);
        $overview = app(ManagerOverview::class)->build($owner, $range, 'en');
        $series = $overview['sections']['bookings']['series'];

        expect($overview['buckets'])->toHaveCount(24)
            ->and($overview['buckets'][10]['label'])->toBe('10:00')
            ->and($series)->toHaveCount(24)
            ->and($series[10])->toBe(1)
            ->and(array_sum($series))->toBe(1)
            ->and($overview['sections']['bookings']['series_previous'])->toHaveCount(24);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
