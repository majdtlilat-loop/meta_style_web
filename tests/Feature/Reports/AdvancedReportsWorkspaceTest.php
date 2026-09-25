<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Reporting\ReadTarget;
use App\Livewire\Center\AdvancedReports\Workspace;
use App\Modules\AdvancedReports\Application\AdvancedWorkspace;
use App\Modules\AdvancedReports\Application\PeriodWindows;
use App\Modules\AdvancedReports\Application\SectionFacts;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\View\AdvancedReports\AdvancedPeriod;
use App\View\AdvancedReports\Metrics;
use App\View\AdvancedReports\WorkspacePresenter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Advanced Reports — the insights workspace
|--------------------------------------------------------------------------
|
| Every figure comes from the reporting target, the current period AND its
| comparison period read once each; a section exists only for a viewer who
| may read its source records; losing the entitlement refuses on the server.
|
*/

/**
 * Three visits today (two by Ahmed, one by Sara) and one yesterday (Ahmed),
 * seeded by the bound test case: `advancedWorkspaceFacts()->call($this)`.
 */
function advancedWorkspaceFacts(): Closure
{
    return function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');
        $amal = $this->seedCustomer('Amal Workspace', '0750 555 1001');
        $noor = $this->seedCustomer('Noor Workspace', '0750 555 1002');

        $this->customerVisit($seed, $owner, $amal);
        $this->customerVisit($seed, $owner, $amal);
        $this->customerVisit(['employee' => $sara] + $seed, $owner, $noor);
        $yesterday = $this->customerVisit($seed, $owner, $noor);
        $yesterday->forceFill(['arrived_at' => CarbonImmutable::now('UTC')->subDay()])->save();
    };
}

it('builds the workspace from reporting facts, the period against its comparison period', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        advancedWorkspaceFacts()->call($this);
        $owner = $this->ownerWithCatalogAccess();

        $period = AdvancedPeriod::resolve('today', null, null, 'previous', 'Asia/Baghdad');
        $current = app(ReportRequestFactory::class)->make($owner->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [], ReadTarget::Reporting);
        $comparison = PeriodWindows::shift($current, $period->compareFrom->toDateString(), $period->compareTo->toDateString());
        $workspace = app(AdvancedWorkspace::class)->build($owner, $current, $comparison);

        expect(array_keys($workspace['sections']))->toBe(array_keys(SectionFacts::SECTIONS))
            ->and($workspace['comparison'])->toBe(['from' => $period->compareFrom->toDateString(), 'to' => $period->compareTo->toDateString()])
            ->and($workspace['sections']['visits']['current']['total'])->toBe(3)
            ->and($workspace['sections']['visits']['previous']['total'])->toBe(1);

        $now = Metrics::values(array_map(static fn (array $s): array => $s['current'], $workspace['sections']), 'IQD');
        $then = Metrics::values(array_map(static fn (array $s): array => $s['previous'], $workspace['sections']), 'IQD');

        expect($now['arrivals'])->toBe(3)
            ->and($then['arrivals'])->toBe(1)
            ->and($now['completed_services'])->toBe(3)
            ->and($now['customers'])->toBe(2)
            ->and($now['bookings'])->toBe(0)
            // A rate with no denominator is not 0 %: it does not exist.
            ->and($now['cancellation_rate'])->toBeNull();

        $page = (new WorkspacePresenter($period, 'en'))->present($workspace);
        $kpis = collect($page['kpis'])->keyBy('key');
        $sections = collect($page['sections'])->keyBy('key');
        $employees = collect($sections['employees']['cards'])->firstWhere('type', 'table');

        expect($page['empty'])->toBeFalse()
            ->and($kpis['arrivals']['current'])->toBe(3)
            ->and($kpis['arrivals']['previous'])->toBe(1)
            ->and($kpis['arrivals']['higher'])->toBeTrue()
            ->and($kpis->has('bookings'))->toBeFalse()
            ->and($sections->keys()->all())->toContain('services', 'employees')
            ->and($sections->has('bookings'))->toBeFalse()
            ->and(array_column($employees['rows'], 0))->toBe(['Ahmed', 'Sara'])
            ->and($employees['rows'][0][1])->toBe('2')
            ->and($employees['rows'][0][2])->toBe('1')
            ->and(collect($page['trends'])->pluck('key')->all())->toContain('arrivals');

        // The reporting target is the only one a section reads.
        $primary = app(ReportRequestFactory::class)->make($owner->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [], ReadTarget::Primary);
        expect(fn () => app(SectionFacts::class)->read('visits', $primary))->toThrow(InvalidArgumentException::class);
    });
});

it('reads only the sections a role may see, and refuses without the entitlement', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        advancedWorkspaceFacts()->call($this);

        $limited = $this->staffWith([Permission::ReportView, Permission::JourneyView], 'visits-only@advanced.test');
        $period = AdvancedPeriod::resolve('this_month', null, null, 'last_year', 'Asia/Baghdad');
        $current = app(ReportRequestFactory::class)->make($limited->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [], ReadTarget::Reporting);
        $comparison = PeriodWindows::shift($current, $period->compareFrom->toDateString(), $period->compareTo->toDateString());
        $workspace = app(AdvancedWorkspace::class)->build($limited, $current, $comparison);

        // Customers need customer.view as well; sales, payments and the rest their own permission.
        expect(array_keys($workspace['sections']))->toBe(['visits'])
            ->and($workspace['comparison']['from'])->toBe($period->from->subYearNoOverflow()->toDateString());

        $this->revokeEntitlement('reports_advanced');

        expect(fn () => app(AdvancedWorkspace::class)->build($this->ownerWithCatalogAccess(), $current, $comparison))
            ->toThrow(EntitlementRequired::class);
    });
});

it('focuses the workspace on one employee or service, reading only what that filter narrows', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        advancedWorkspaceFacts()->call($this);
        $owner = $this->ownerWithCatalogAccess();
        $sara = Employee::query()->get()->first(static fn (Employee $employee): bool => $employee->name->get() === 'Sara');

        $period = AdvancedPeriod::resolve('today', null, null, 'previous', 'Asia/Baghdad');
        $current = app(ReportRequestFactory::class)->make($owner->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [], ReadTarget::Reporting);
        $comparison = PeriodWindows::shift($current, $period->compareFrom->toDateString(), $period->compareTo->toDateString());
        $focused = app(AdvancedWorkspace::class)->build($owner, $current, $comparison, ['employee' => (string) $sara->uuid, 'tenant' => 'ignored']);

        // Sara performed one of today's three visits; money, customers, reviews and
        // benefits are not attributed to an employee, so they are not read at all.
        expect(array_keys($focused['sections']))->toBe(['bookings', 'visits', 'queue'])
            ->and($focused['focus'])->toBe(['employee' => (string) $sara->uuid])
            ->and($focused['sections']['visits']['current']['total'])->toBe(1)
            ->and($focused['sections']['visits']['previous']['total'])->toBe(0);

        $component = Livewire::withoutLazyLoading()->actingAs($owner)
            ->test(Workspace::class, ['range' => 'today'])
            ->set('focus', 'employee:'.$sara->uuid)
            ->assertSet('focus', 'employee:'.$sara->uuid)
            ->assertSee(__('manager_advanced.focus.clear'))
            ->assertSee(__('manager_advanced.sections.employees'))
            ->assertDontSee(__('manager_advanced.sections.customers'));

        expect(str_contains($component->html(), 'manager_advanced.'))->toBeFalse();

        // A value that is not one of the viewer's options is dropped, never read.
        $component->set('focus', 'employee:'.(string) Str::uuid())
            ->assertSet('focus', '')
            ->assertDontSee(__('manager_advanced.focus.clear'))
            ->set('focus', 'branch:'.(string) $sara->uuid)
            ->assertSet('focus', '');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
