<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Reports;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use App\Modules\Reports\Application\Analytics\Facts;
use App\Modules\Reports\Application\Analytics\StandardAnalytics;
use App\Modules\Reports\Application\ReportPeriod;
use App\View\Charts\ValueFormat;
use App\View\Reports\Standard\Cards;
use App\View\Reports\Standard\CustomerLayouts;
use App\View\Reports\StandardReportView;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Standard Reports analytics page
|--------------------------------------------------------------------------
|
| Every figure is read through the authorized report request (branch scope
| intersected, only the report's own filters applied) for the period AND its
| like-for-like comparison; the page shows real data only — a period without
| activity is one clean empty state, money is in its own currency, and the
| report the viewer may not read is refused without hiding the others.
|
*/

/** Moves an appointment onto a branch-local day, keeping its hour. */
function stdMoveBooking(Appointment $appointment, string $date, string $timezone, string $time = '10:00', ?string $status = null): void
{
    $start = CarbonImmutable::parse($date.' '.$time, $timezone)->utc();
    $appointment->forceFill(array_filter([
        'starts_at' => $start,
        'ends_at' => $start->addMinutes(30),
        'status' => $status,
    ]))->save();
}

/** @return array<string, mixed> */
function stdAnalytics(string $code, User $user, ReportPeriod $period, ?string $branch = null, array $filters = []): array
{
    return app(StandardAnalytics::class)->build($code, $user, $period, $branch, $filters, 'en');
}

it('compares every period preset like for like', function (): void {
    $now = CarbonImmutable::parse('2026-09-24 15:00', 'Asia/Baghdad');
    $expected = [
        'today' => ['2026-09-24', '2026-09-24', '2026-09-23', '2026-09-23', 'yesterday'],
        'last_7_days' => ['2026-09-18', '2026-09-24', '2026-09-11', '2026-09-17', 'previous_7_days'],
        'this_month' => ['2026-09-01', '2026-09-24', '2026-08-01', '2026-08-24', 'same_days_last_month'],
        'last_month' => ['2026-08-01', '2026-08-31', '2026-07-01', '2026-07-31', 'previous_month'],
        'this_year' => ['2026-01-01', '2026-09-24', '2025-01-01', '2025-09-24', 'same_days_last_year'],
    ];

    foreach ($expected as $preset => [$from, $to, $previousFrom, $previousTo, $comparison]) {
        $period = ReportPeriod::resolve($preset, null, null, 'Asia/Baghdad', $now);

        expect([$period->preset, $period->current->from->toDateString(), $period->current->to->toDateString()])->toBe([$preset, $from, $to])
            ->and([$period->previous->from->toDateString(), $period->previous->to->toDateString()])->toBe([$previousFrom, $previousTo])
            ->and($period->comparisonKey())->toBe($comparison);
    }

    // Custom: the immediately preceding range of the same length.
    $custom = ReportPeriod::resolve('custom', '2026-09-10', '2026-09-19', 'Asia/Baghdad', $now);
    expect([$custom->preset, $custom->previous->from->toDateString(), $custom->previous->to->toDateString()])->toBe(['custom', '2026-08-31', '2026-09-09']);

    // Last month in March compares February with the whole of January.
    $march = ReportPeriod::resolve('last_month', null, null, 'Asia/Baghdad', CarbonImmutable::parse('2026-03-05 09:00', 'Asia/Baghdad'));
    expect([$march->current->from->toDateString(), $march->current->to->toDateString(), $march->previous->from->toDateString(), $march->previous->to->toDateString()])
        ->toBe(['2026-02-01', '2026-02-28', '2026-01-01', '2026-01-31']);

    // Unreadable dates are this month, never an error page; an unknown preset too.
    expect(ReportPeriod::resolve('custom', 'yesterday', 'nope', 'Asia/Baghdad', $now)->preset)->toBe('this_month')
        ->and(ReportPeriod::resolve('forever', null, null, 'Asia/Baghdad', $now)->preset)->toBe('this_month')
        ->and(ReportPeriod::resolve('today', null, null, 'Asia/Baghdad', $now)->current->buckets('en'))->toHaveCount(24);
});

it('keeps one lead currency, discloses the others and never invents a rate', function (): void {
    // The most value across both periods leads; every other currency is
    // disclosed beside the figures, never added into them.
    expect(Facts::leadCurrency(['USD' => 1200], ['IQD' => 250000, 'USD' => 300]))->toBe('IQD')
        ->and(Facts::others(['IQD' => 250000, 'USD' => 1200, 'EUR' => 0], 'IQD'))->toBe([['currency' => 'USD', 'minor' => 1200]])
        ->and(Facts::rate(1, 0))->toBeNull()
        ->and(Facts::rate(1, 3))->toBe(33.3)
        // Completed, cancelled and no-show share one denominator.
        ->and(Facts::resolved(['booked' => 4, 'completed' => 2, 'cancelled' => 1, 'no_show' => 1]))->toBe(4);

    $period = ReportPeriod::resolve('today', null, null, 'Asia/Baghdad');
    $view = app(StandardReportView::class)->present([
        'code' => 'sales_payments',
        'buckets' => [],
        'previous_buckets' => [],
        'as_of' => CarbonImmutable::now('UTC')->toIso8601String(),
        'facts' => [
            'empty' => false,
            'currency' => 'IQD',
            'other_currencies' => [['currency' => 'USD', 'minor' => 1250]],
            'metrics' => ['billed' => Facts::metric(250000, 0, 'money')],
            'series' => [],
            'parts' => ['payment_methods' => ['cash' => 250000], 'payment_methods_previous' => [], 'categories' => [], 'kinds' => [], 'items' => [], 'branches' => []],
        ],
    ], $period, 'en', static fn (): ?string => null);

    expect($view['currency'])->toBe('IQD')
        ->and($view['other_currencies'])->toBe([ValueFormat::make('money', 'USD')->full(1250)])
        ->and($view['kpis'][0])->toMatchArray(['key' => 'billed', 'current' => 250000, 'previous' => 0, 'format' => 'money', 'currency' => 'IQD'])
        // A single payment method is a table row, not a one-slice donut.
        ->and(collect($view['sections'])->flatMap(static fn (array $section): array => $section['cards'])->map(static fn (array $card): string => $card['type'].':'.$card['id'])->all())
        ->toBe(['table:payment_methods']);
});

it('shows the difference beside a percentage, never adds averages into Other, and keeps every star step', function (): void {
    // A KPI tile reads current, previous, the relative change AND the
    // absolute difference.
    $tile = Blade::render('<x-chart.kpi label="Billed" :current="25000" :previous="20000" format="money" currency="IQD" difference />');
    expect($tile)->toContain('+25%')
        ->toContain('kpi__difference')
        ->toContain(ValueFormat::make('money', 'IQD')->difference(5000))
        ->toContain(ValueFormat::make('money', 'IQD')->full(20000));

    // Opt-in (the library's other callers are unchanged); from a zero base
    // the delta already IS the difference, and a rate moves in points.
    expect(Blade::render('<x-chart.kpi label="Billed" :current="25000" :previous="20000" format="money" currency="IQD" />'))->not->toContain('kpi__difference')
        ->and(Blade::render('<x-chart.kpi label="Billed" :current="25000" :previous="0" format="money" currency="IQD" difference />'))->not->toContain('kpi__difference')
        ->and(Blade::render('<x-chart.kpi label="Rate" :current="40" :previous="50" format="percent" :higher-is-better="false" difference />'))->not->toContain('kpi__difference');

    // Ratings are averages: the chart names the best eight and never sums
    // the rest into an "Other" bar; a tie goes to the better-sampled one.
    $cards = new Cards([], [], 'IQD', 'Previous period');
    $rows = array_map(static fn (int $i): array => ['name' => 'Employee '.$i, 'average' => 5 - $i / 10, 'count' => $i + 1], range(0, 9));
    $ratings = $cards->ranked('employee_ratings', CustomerLayouts::rated($rows), limit: 8, additive: false);

    expect($ratings['props']['limit'])->toBe(8)
        ->and(array_column($ratings['props']['items'], 'label'))->toBe(array_map(static fn (int $i): string => 'Employee '.$i, range(0, 7)))
        ->and(array_column(CustomerLayouts::rated([
            ['name' => 'Few', 'average' => 4.5, 'count' => 2],
            ['name' => 'Many', 'average' => 4.5, 'count' => 9],
        ]), 'label'))->toBe(['Many', 'Few']);

    // A 1–5 star distribution keeps every step, including the empty ones.
    $stars = $cards->ranked('rating_distribution', array_map(static fn (int $star): array => [
        'label' => (string) $star,
        'value' => $star === 5 ? 3 : ($star === 1 ? 1 : 0),
        'previous' => 0,
    ], [5, 4, 3, 2, 1]), share: true, sort: false, better: null, keepEmpty: true);

    expect(array_column($stars['props']['items'], 'label'))->toBe(['5', '4', '3', '2', '1']);
});

it('renders every report view the viewer may open from real data, in every language', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $today = $this->branchToday($seed['branch']);

        $booking = $this->bookFor($seed, $owner, $this->seedCustomer('Booked Bana', '0750 555 0101'), 1, '10:00');
        stdMoveBooking($booking, $today, $seed['branch']->timezone);
        $this->customerVisit($seed, $owner, $this->seedCustomer('Visiting Vian', '0750 555 0102'));
        $invoice = $this->issuedInvoice($seed, $owner);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 20000);

        foreach (array_keys(Reports::ORDER) as $code) {
            $html = Livewire::actingAs($owner)
                ->test(Reports::class, ['report' => $code])
                ->assertOk()
                ->assertSee(__('manager_reports.standard.'.$code.'.title'))
                ->assertSee(__('manager_reports.std.tabs.'.$code))
                ->html();

            expect(preg_match('/\bmanager_(reports|charts)\.[a-z_]+/', strip_tags($html)))->toBe(0, $code.' shows a raw key');
        }

        // The overview draws what happened: billed in its own currency, the
        // booking and the performed service, with charts carrying data tables.
        Livewire::actingAs($owner)
            ->test(Reports::class, ['report' => 'business_overview'])
            ->assertSee(ValueFormat::make('money', 'IQD')->full(20000))
            ->assertSee(__('manager_reports.std.metrics.cancellation_rate'))
            ->assertSeeHtml('aria-labelledby="std-line-billed"')
            ->assertSeeHtml('aria-labelledby="std-ranked-top_services_billed"')
            // One payment method is not a part-to-whole: no one-slice donut.
            ->assertDontSeeHtml('aria-labelledby="std-donut-payment_methods"')
            ->assertSeeHtml('class="chart__data"')
            ->assertDontSee(__('manager_reports.std.states.empty'));

        foreach (['ar', 'ckb'] as $locale) {
            app()->setLocale($locale);
            $html = Livewire::actingAs($owner)->test(Reports::class, ['report' => 'booking_activity'])
                ->assertOk()
                ->assertSee(trans('manager_reports.std.metrics.cancellation_rate', [], $locale))
                ->assertSee(trans('manager_reports.std.period.last_month', [], $locale))
                ->html();

            expect(preg_match('/\bmanager_(reports|charts)\.[a-z_]+/', strip_tags($html)))->toBe(0)
                ->and(str_contains(strip_tags($html), 'Cancellation rate'))->toBeFalse();
        }

        app()->setLocale('en');
    });
});

it('compares the period with the previous one and draws both on the same buckets', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $tz = $seed['branch']->timezone;
        $today = CarbonImmutable::parse($this->branchToday($seed['branch']), $tz);
        $period = ReportPeriod::resolve('custom', $today->subDays(9)->toDateString(), $today->toDateString(), $tz);

        // Two in this period (one cancelled, one completed), one in the ten days before.
        $customer = $this->seedCustomer('Comparing Chra', '0750 555 0103');
        stdMoveBooking($this->bookFor($seed, $owner, $customer, 1, '10:00'), $today->subDays(2)->toDateString(), $tz, '10:00', 'cancelled');
        stdMoveBooking($this->bookFor($seed, $owner, $customer, 2, '11:00'), $today->subDays(1)->toDateString(), $tz, '11:00', 'completed');
        stdMoveBooking($this->bookFor($seed, $owner, $customer, 3, '12:00'), $today->subDays(12)->toDateString(), $tz, '12:00', 'completed');

        $analytics = stdAnalytics('booking_activity', $owner, $period);
        $facts = $analytics['facts'];

        expect($facts['metrics']['scheduled'])->toMatchArray(['current' => 2, 'previous' => 1, 'better' => true])
            ->and($facts['metrics']['cancelled'])->toMatchArray(['current' => 1, 'previous' => 0, 'better' => false])
            // One cancelled of two outcomes; lower is better.
            ->and($facts['metrics']['cancellation_rate'])->toMatchArray(['current' => 50.0, 'previous' => 0.0, 'unit' => 'percent', 'better' => false])
            ->and($analytics['buckets'])->toHaveCount(10)
            ->and(array_sum($facts['series']['scheduled']['current']))->toBe(2)
            ->and(array_sum($facts['series']['scheduled']['previous']))->toBe(1)
            ->and($facts['series']['scheduled']['previous'])->toHaveCount(10)
            ->and($facts['parts']['services'][0])->toMatchArray(['current' => 2, 'previous' => 1])
            ->and(array_sum(array_map('array_sum', $facts['parts']['heat'])))->toBe(2);

        // The page's chart payload: the same buckets, the previous period
        // dashed underneath with its own dates, and the stacked statuses.
        $view = app(StandardReportView::class)->present($analytics, $period, 'en', static fn (): ?string => null);
        $cards = collect($view['sections'])->flatMap(static fn (array $section): array => $section['cards'])->keyBy(static fn (array $card): string => $card['type'].':'.$card['id']);

        expect($cards->get('line:scheduled')['props']['buckets'])->toHaveCount(10)
            ->and($cards->get('line:scheduled')['props']['previous']['label'])->toBe(__('manager_reports.std.compare.previous_period'))
            ->and($cards->get('line:scheduled')['props']['previous']['labels'])->toHaveCount(10)
            ->and($cards->has('stacked:status_over_time'))->toBeTrue()
            ->and($cards->has('donut:booking_status'))->toBeTrue()
            ->and($cards->get('radial:completion')['props'])->toMatchArray(['value' => 50.0, 'previous' => 100.0])
            ->and($cards->has('heatmap:peak_booking_times'))->toBeTrue()
            ->and($cards->get('table:services_booked')['props']['rows'])->toHaveCount(1)
            ->and(collect($view['kpis'])->firstWhere('key', 'cancellation_rate'))->toMatchArray(['current' => 50.0, 'previous' => 0.0, 'format' => 'percent', 'better' => false]);

        // The page draws the same period through the shared components.
        Livewire::withQueryParams(['range' => 'custom', 'from' => $period->current->from->toDateString(), 'to' => $period->current->to->toDateString()])
            ->actingAs($owner)
            ->test(Reports::class, ['report' => 'booking_activity'])
            ->assertOk()
            ->assertSeeHtml('aria-labelledby="std-stacked-status_over_time"')
            ->assertSeeHtml('aria-labelledby="std-donut-booking_status"')
            ->assertSeeHtml('aria-labelledby="std-radial-completion"')
            ->assertSeeHtml('aria-labelledby="std-heatmap-peak_booking_times"')
            ->assertSeeHtml('class="chart chart--kit chart--line"')
            ->assertSee(__('manager_reports.std.compare.previous_period'));

        // "Upcoming" exists only while the period is running.
        expect($facts['metrics'])->toHaveKey('upcoming')
            ->and(stdAnalytics('booking_activity', $owner, ReportPeriod::resolve('last_month', null, null, $tz))['facts']['metrics'])->not->toHaveKey('upcoming');

        // "This year" across 29 February: 63 days (months) against 62 a year
        // before (days) — the comparison is re-bucketed into months, so
        // January is compared with January, never with 1 January.
        $leap = stdAnalytics('booking_activity', $owner, ReportPeriod::resolve('this_year', null, null, $tz, CarbonImmutable::parse('2028-03-03 12:00', $tz)));
        expect(array_column($leap['buckets'], 'key'))->toBe(['2028-01', '2028-02', '2028-03'])
            ->and(array_column($leap['previous_buckets'], 'key'))->toBe(['2027-01', '2027-02', '2027-03']);
    });
});

it('narrows by status, category and employee, and never by a filter the report does not support', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $tz = $seed['branch']->timezone;
        $today = $this->branchToday($seed['branch']);
        $period = ReportPeriod::resolve('custom', $today, $today, $tz);
        $category = ServiceCategory::query()->firstOrFail();
        $customer = $this->seedCustomer('Filtered Faris', '0750 555 0104');

        stdMoveBooking($this->bookFor($seed, $owner, $customer, 1, '10:00'), $today, $tz, '10:00', 'cancelled');
        stdMoveBooking($this->bookFor($seed, $owner, $customer, 2, '11:00'), $today, $tz, '11:00');
        $this->customerVisit($seed, $owner, $customer);

        $scheduled = static fn (array $filters): int => (int) stdAnalytics('booking_activity', $owner, $period, null, $filters)['facts']['metrics']['scheduled']['current'];

        expect($scheduled([]))->toBe(2)
            ->and($scheduled(['status' => 'cancelled']))->toBe(1)
            ->and($scheduled(['category' => $category->uuid]))->toBe(2)
            ->and($scheduled(['category' => (string) Str::uuid()]))->toBe(0)
            ->and($scheduled(['employee' => $seed['employee']->uuid]))->toBe(2);

        // Performed work is routed by department, never by menu category
        // (ADR-037): the category never reaches the journey reader; the
        // Catalog names each performed service's category for the mix.
        $services = stdAnalytics('visit_service_delivery', $owner, $period, null, ['category' => (string) Str::uuid()]);
        expect($services['filters'])->toBe([])
            ->and($services['facts']['metrics']['completed_services']['current'])->toBe(1)
            ->and($services['facts']['parts']['categories'][0])->toMatchArray(['name' => 'Hair Services', 'current' => 1, 'previous' => 0])
            ->and($services['facts']['parts']['services'][0]['category'])->toBe('Hair Services');

        // Sales ignore a status the sales report does not support.
        expect(stdAnalytics('sales_payments', $owner, $period, null, ['status' => 'cancelled', 'category' => (string) Str::uuid()])['filters'])->toBe([]);

        // The page drops a URL value that is not a real option.
        Livewire::actingAs($owner)
            ->test(Reports::class, ['report' => 'booking_activity'])
            ->set('status', 'not-a-status')
            ->assertOk()
            ->assertSeeHtml('value="no_show"')
            ->assertSeeHtml('value="'.$category->uuid.'"');
    });
});

it('stays inside the viewer branch scope and compares branches only within it', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $second = $this->seedBranch();
        $this->openEveryDay($second);
        $owner = $this->ownerWithCatalogAccess();
        $today = $this->branchToday($seed['branch']);
        $period = ReportPeriod::resolve('custom', $today, $today, $seed['branch']->timezone);

        $this->walkInVisit($seed, $owner);
        $this->walkInVisit(['branch' => $second, 'service' => $seed['service']], $owner);

        $manager = $this->seedStaffMember(SystemRole::Manager, [(int) $seed['branch']->getKey()]);
        $mine = stdAnalytics('visit_service_delivery', $manager, $period);
        // Asking for the other branch never widens the scope: no window, no data.
        $theirs = stdAnalytics('visit_service_delivery', $manager, $period, $second->uuid);
        $all = stdAnalytics('visit_service_delivery', $owner, $period);

        expect($mine['branches'])->toBe(1)
            ->and($mine['facts']['metrics']['arrivals']['current'])->toBe(1)
            ->and($theirs['branches'])->toBe(0)
            ->and($theirs['facts']['metrics']['arrivals']['current'])->toBe(0)
            ->and($theirs['facts']['empty'])->toBeTrue()
            ->and($all['branches'])->toBe(2)
            ->and($all['facts']['metrics']['arrivals']['current'])->toBe(2);
    });
});

it('refuses the report the viewer may not read, keeps the others, and exports only with report.export', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $category = ServiceCategory::query()->firstOrFail();
        $today = $this->branchToday($seed['branch']);
        $period = ReportPeriod::resolve('custom', $today, $today, $seed['branch']->timezone);
        $viewer = $this->staffWith([Permission::ReportView, Permission::AppointmentView], 'bookings-only@reports.test');

        expect(fn () => stdAnalytics('sales_payments', $viewer, $period))->toThrow(AuthorizationException::class)
            ->and(stdAnalytics('booking_activity', $viewer, $period)['code'])->toBe('booking_activity');

        // Sales is refused with a way to the report this role CAN read;
        // no CSV without report.export.
        Livewire::actingAs($viewer)
            ->test(Reports::class, ['report' => 'sales_payments'])
            ->assertOk()
            ->assertSee(__('manager_reports.states.report_forbidden_title'))
            ->assertSee(__('manager_reports.standard.booking_activity.title'))
            ->assertDontSee(__('manager_reports.std.tabs.sales_payments'));

        Livewire::actingAs($viewer)
            ->test(Reports::class, ['report' => 'booking_activity'])
            ->assertOk()
            ->assertDontSeeHtml('/export.csv');

        // With report.export the CSV carries the report's own filters, audited.
        $owner = $this->ownerWithCatalogAccess();
        $this->actingAs($owner, 'web');

        $this->get("http://{$slug}.localhost:8000/manager/reports/booking_activity/export.csv?from={$today}&to={$today}&category={$category->uuid}&status=cancelled")
            ->assertOk()
            ->assertDownload("booking_activity-{$today}-{$today}.csv");

        $audit = TenantAuditLog::query()->where('action', 'report.exported')->latest('id')->firstOrFail();
        expect($audit->meta['filters'])->toBe(['status' => 'cancelled', 'category' => $category->uuid]);

        $this->get("http://{$slug}.localhost:8000/manager/reports/sales_payments/export.csv?from={$today}&to={$today}&status=cancelled")->assertOk();
        $audit = TenantAuditLog::query()->where('action', 'report.exported')->latest('id')->firstOrFail();
        expect($audit->meta['filters'])->toBe([]);
    });
});

it('shows one clean empty state for a period without activity, and money in its own currency', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        Livewire::actingAs($owner)
            ->test(Reports::class, ['report' => 'sales_payments'])
            ->assertOk()
            ->assertSee(__('manager_reports.std.states.empty'))
            ->assertDontSeeHtml('<section class="chart-card std-card')
            ->assertDontSeeHtml('class="std-kpis"');

        // One invoice: the KPI is IQD with no decimals, the previous period is
        // a zero — no invented percentage from a zero base.
        $this->issuedInvoice($seed, $owner);

        $component = Livewire::actingAs($owner)->test(Reports::class, ['report' => 'sales_payments'])->call('refresh');
        $component->assertOk()
            ->assertDontSee(__('manager_reports.std.states.empty'))
            ->assertSee(ValueFormat::make('money', 'IQD')->full(20000))
            ->assertDontSee('20,000.00')
            ->assertSeeHtml('class="std-kpis"');

        $billed = collect(app(StandardReportView::class)->present(
            stdAnalytics('sales_payments', $owner, ReportPeriod::resolve('this_month', null, null, $seed['branch']->timezone)),
            ReportPeriod::resolve('this_month', null, null, $seed['branch']->timezone),
            'en',
            static fn (): ?string => null,
        )['kpis'])->firstWhere('key', 'billed');

        expect($billed)->toMatchArray(['current' => 20000, 'previous' => 0, 'format' => 'money', 'currency' => 'IQD', 'better' => true]);
    });
});

it('keeps the upgrade page and the no-access state apart from the reports', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $owner = $this->ownerWithCatalogAccess();

        // Not in the plan: the shared locked state, no report loaded.
        Livewire::actingAs($owner)
            ->test(Reports::class)
            ->assertOk()
            ->assertSee(__('platform_labels.entitlement.reports_standard'))
            ->assertDontSee(__('manager_reports.std.sections.trends'));

        $this->grantEntitlement('reports_standard', null);

        Livewire::actingAs($this->staffWith([Permission::AppointmentView], 'no-reports@reports.test'))
            ->test(Reports::class)
            ->assertOk()
            ->assertSee(__('manager_reports.states.forbidden_title'))
            ->assertDontSeeHtml('std-toolbar');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
