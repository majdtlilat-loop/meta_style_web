<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Kernel\Reporting\ReadTarget;
use App\Livewire\Center\AdvancedReports\Compare;
use App\Livewire\Center\AdvancedReports\Library;
use App\Modules\AdvancedReports\Application\AdvancedComparison;
use App\Modules\AdvancedReports\Application\AdvancedReportCatalog;
use App\Modules\AdvancedReports\Application\AdvancedReports;
use App\Modules\AdvancedReports\Application\PeriodWindows;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\View\AdvancedReports\AdvancedPeriod;
use App\View\AdvancedReports\ComparePresenter;
use App\View\AdvancedReports\Metrics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Advanced Reports — comparison datasets
|--------------------------------------------------------------------------
|
| Branch vs branch, employee vs employee and service vs service are the same
| reporting reads narrowed from ONE authorized request: a branch window the
| request already holds, or an actual-performer / performed-service filter
| resolved inside the readers' own SQL. A comparison never widens scope.
|
*/

/**
 * Main branch: Ahmed performs the haircut twice, Sara a beard trim once.
 * Second branch: Mona performs the haircut once.
 */
function advancedComparisonFacts(): Closure
{
    return function (): array {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $second = $this->seedBranch('Riverside');
        $this->openEveryDay($second);
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');
        $beard = $this->seedService('Beard trim', 20, 10000, $sara);
        $mona = $this->seedBookableEmployee($seed['service'], $second, 'Mona');
        $customer = $this->seedCustomer('Rania Compare', '0750 555 2001');

        $this->customerVisit($seed, $owner, $customer);
        $this->customerVisit($seed, $owner, $customer);
        $this->customerVisit(['employee' => $sara] + $seed, $owner, $customer, ['completed'], [$beard->uuid]);
        $this->customerVisit(['branch' => $second, 'employee' => $mona] + $seed, $owner, $customer);

        return ['seed' => $seed, 'second' => $second, 'sara' => $sara, 'mona' => $mona, 'beard' => $beard];
    };
}

it('compares branches, employees and services from the reporting target, each narrowed from one authorized request', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $facts = advancedComparisonFacts()->call($this);
        $owner = $this->ownerWithCatalogAccess();
        $period = AdvancedPeriod::resolve('today', null, null, 'previous', 'Asia/Baghdad');
        $current = app(ReportRequestFactory::class)->make($owner->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [], ReadTarget::Reporting);
        $comparison = app(AdvancedComparison::class);

        $branches = $comparison->compare('branches', $owner, $current, [$facts['seed']['branch']->uuid, $facts['second']->uuid]);
        $branchMetrics = array_map(static fn (array $entity): array => Metrics::values($entity['facts'], 'IQD'), $branches['entities']);

        expect($branches['sections'])->toBe(AdvancedComparison::MODES['branches'])
            ->and(array_column($branches['entities'], 'name'))->toBe([$facts['seed']['branch']->name->get(), 'Riverside'])
            ->and($branchMetrics[0]['arrivals'])->toBe(3)
            ->and($branchMetrics[1]['arrivals'])->toBe(1);

        $employees = $comparison->compare('employees', $owner, $current, [$facts['seed']['employee']->uuid, $facts['sara']->uuid, $facts['mona']->uuid]);
        $employeeMetrics = array_map(static fn (array $entity): array => Metrics::values($entity['facts'], 'IQD'), $employees['entities']);

        // Only what an employee filter truly narrows: no sales, payments or customers.
        expect($employees['sections'])->toBe(['bookings', 'visits', 'queue'])
            ->and(array_column($employeeMetrics, 'completed_services'))->toBe([2, 1, 1])
            ->and(array_keys($employees['entities'][1]['facts']['visits']['stages']['services']))->toHaveCount(1);

        $services = $comparison->compare('services', $owner, $current, [$facts['seed']['service']->uuid, $facts['beard']->uuid]);
        $serviceMetrics = array_map(static fn (array $entity): array => Metrics::values($entity['facts'], 'IQD'), $services['entities']);

        expect(array_column($serviceMetrics, 'completed_services'))->toBe([3, 1]);

        $presented = (new ComparePresenter($period, 'en'))->entities('services', $services['entities'], [
            $facts['seed']['service']->uuid => 'Haircut',
            $facts['beard']->uuid => 'Beard trim',
        ]);
        $row = collect($presented['table'])->firstWhere('label', Metrics::label('completed_services'));

        expect($presented['ready'])->toBeTrue()
            ->and($presented['entities'])->toBe(['Haircut', 'Beard trim'])
            ->and($row['cells'][0]['value'])->toBe('3')
            ->and($row['cells'][1]['value'])->toBe('1')
            ->and($row['cells'][1]['tone'])->toBe('bad')
            ->and(collect($presented['trends'])->firstWhere('key', 'arrivals')['series'])->toHaveCount(2)
            // Only two or more entities make a comparison.
            ->and((new ComparePresenter($period, 'en'))->entities('services', array_slice($services['entities'], 0, 1), [])['ready'])->toBeFalse();
    });
});

it('never widens a comparison past the viewer branch scope', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $facts = advancedComparisonFacts()->call($this);
        $manager = $this->seedStaffMember(SystemRole::Manager, [(int) $facts['seed']['branch']->getKey()]);
        $period = AdvancedPeriod::resolve('today', null, null, 'previous', 'Asia/Baghdad');
        $current = app(ReportRequestFactory::class)->make($manager->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [$facts['second']->uuid], ReadTarget::Reporting);

        // Asking for the other branch by uuid yields no window at all …
        expect($current->windows)->toBe([]);

        $scoped = app(ReportRequestFactory::class)->make($manager->branchScope(), $period->from->toDateString(), $period->to->toDateString(), [], ReadTarget::Reporting);
        $branches = app(AdvancedComparison::class)->compare('branches', $manager, $scoped, [$facts['seed']['branch']->uuid, $facts['second']->uuid]);

        // … and a branch comparison simply leaves it out.
        expect(array_column($branches['entities'], 'key'))->toBe([$facts['seed']['branch']->uuid]);

        // Mona only works at the other branch: comparing her reads nothing.
        $employees = app(AdvancedComparison::class)->compare('employees', $manager, $scoped, [$facts['mona']->uuid]);
        expect(Metrics::values($employees['entities'][0]['facts'], 'IQD')['completed_services'])->toBe(0);

        // A comparison period keeps the current request's branches, never its own.
        $wide = PeriodWindows::shift($scoped, CarbonImmutable::now('Asia/Baghdad')->subDays(7)->toDateString(), CarbonImmutable::now('Asia/Baghdad')->subDay()->toDateString());
        expect(array_column(array_map(static fn ($window): array => $window->toArray(), $wide->windows), 'uuid'))->toBe([$facts['seed']['branch']->uuid]);

        $report = app(AdvancedReports::class)->run('period_comparison', $this->ownerWithCatalogAccess(), $scoped, $wide);
        expect($report->coverage['comparison_from'])->toBe($wide->fromDate)
            ->and($report->coverage['branches'])->toBe(1)
            ->and($report->glossary['comparison'])->toContain($wide->fromDate);
    });
});

it('renders every comparison mode and every catalog report of the library from real facts', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        advancedComparisonFacts()->call($this);
        $owner = $this->ownerWithCatalogAccess();

        $compare = Livewire::withoutLazyLoading()->actingAs($owner)
            ->test(Compare::class, ['range' => 'today'])
            ->assertSee(__('manager_advanced.compare.modes.branches'))
            ->call('setMode', 'branches')
            ->assertSee('Riverside')
            ->assertSeeHtml('chart--multiline')
            ->assertSee(__('manager_advanced.compare.volume'))
            ->call('setMode', 'employees')
            ->assertSet('selected', fn (array $selected): bool => count($selected) === 2)
            ->assertSee(__('manager_advanced.compare.mix_services'))
            ->assertSee(__('manager_advanced.compare.baseline', ['name' => 'Ahmed']))
            ->call('setMode', 'services')
            ->assertSee('Beard trim');

        expect($compare->html())->not->toContain('manager_advanced.');

        foreach (array_keys(app(AdvancedReportCatalog::class)->all()) as $code) {
            $html = Livewire::withoutLazyLoading()->actingAs($owner)
                ->test(Library::class, ['report' => $code, 'range' => 'today'])
                ->assertSee(__('manager_advanced.reports.'.$code))
                ->assertDontSee(__('manager_advanced.errors.failed'))
                ->html();

            expect(str_contains($html, 'manager_advanced.'))->toBeFalse($code.' shows a raw key');
        }
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
