<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Livewire\Center\AdvancedReports;
use App\Livewire\Center\AdvancedReports\Compare;
use App\Livewire\Center\AdvancedReports\Library;
use App\Livewire\Center\AdvancedReports\Workspace;
use App\Modules\Payments\Application\Actions\CollectDeskPayment;
use App\Modules\Payments\Domain\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Advanced Reports — the page
|--------------------------------------------------------------------------
|
| The paid workspace: locked without `reports_advanced` (the shared upgrade
| state, no data read), its own "no access" state, one toolbar every section
| follows, lazy sections with skeletons, and an AI panel that never breaks
| the page when no provider is configured (the local and test case).
|
*/

it('shows the upgrade state without the entitlement and the no-access state without report.view', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);

        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(AdvancedReports::class)
            ->assertOk()
            ->assertSee(__('manager_features.ui.eyebrow'))
            ->assertSee(__('platform_labels.entitlement.reports_advanced'))
            ->assertDontSeeHtml('adv-toolbar')
            ->assertDontSee(__('manager_advanced.ai.title'));

        // A section cannot be loaded around the lock either: it refuses on the server.
        Livewire::withoutLazyLoading()->actingAs($this->ownerWithCatalogAccess())
            ->test(Workspace::class)
            ->assertSee(__('manager_advanced.errors.forbidden'))
            ->assertDontSee(__('manager_advanced.sections.summary'));

        $this->grantEntitlement('reports_advanced', null);
        $noAccess = $this->staffWith([Permission::AppointmentView], 'no-reports@advanced.test');

        Livewire::actingAs($noAccess)
            ->test(AdvancedReports::class)
            ->assertOk()
            ->assertSee(__('manager_advanced.states.forbidden_title'))
            ->assertDontSeeHtml('adv-toolbar');
    });
});

it('renders the workspace page on the center host in every interface language, AI unavailable and nothing technical', function (string $locale, string $direction): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $locale, $direction): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $this->actingAs($owner);

        $html = $this->get("http://{$slug}.localhost:8000/manager/advanced-reports?locale={$locale}&range=last_90_days&compare=last_year")
            ->assertOk()
            ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
            ->assertSee(trans('manager_advanced.page.title', [], $locale))
            ->assertSee(trans('manager_advanced.range.last_90_days', [], $locale))
            ->assertSee('aria-pressed="true">'.trans('manager_advanced.compare_mode.last_year', [], $locale).'</button>', false)
            ->assertSee(trans('manager_advanced.views.compare', [], $locale))
            // Lazy sections paint chart skeletons first.
            ->assertSee('adv-skeleton', false)
            ->assertSee(trans('manager_advanced.ai.unavailable', [], $locale))
            ->getContent();

        // No raw key, no configuration detail, no dead AI button.
        expect(preg_match('/\bmanager_advanced\.[a-z_]+/', strip_tags($html)))->toBe(0)
            ->and(str_contains(strtolower(strip_tags($html)), 'api key'))->toBeFalse()
            ->and(str_contains(strip_tags($html), 'OpenAI'))->toBeFalse()
            ->and(str_contains($html, 'wire:click="generate"'))->toBeFalse();
    });
})->with([
    'English' => ['en', 'ltr'],
    'Arabic' => ['ar', 'rtl'],
    'Kurdish Sorani' => ['ckb', 'rtl'],
]);

it('draws every section from real facts, and a clean empty state without them', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $owner = $this->ownerWithCatalogAccess();
        $seed = $this->seedBookableCenter();

        Livewire::withoutLazyLoading()->actingAs($owner)
            ->test(Workspace::class, ['range' => 'today'])
            ->assertSee(__('manager_advanced.states.empty_title'))
            ->assertDontSee(__('manager_advanced.sections.summary'));

        $customer = $this->seedCustomer('Huda Page', '0750 555 3001');
        $this->customerVisit($seed, $owner, $customer);
        $this->customerVisit($seed, $owner, $customer);

        Livewire::withoutLazyLoading()->actingAs($owner)
            ->test(Workspace::class, ['range' => 'today'])
            ->assertSee(__('manager_advanced.sections.summary'))
            ->assertSee(__('manager_advanced.sections.trends'))
            ->assertSee(__('manager_advanced.sections.services'))
            ->assertSee(__('manager_advanced.sections.employees'))
            ->assertSee('Ahmed')
            ->assertSeeHtml('class="chart chart--kit chart--line"')
            ->assertSeeHtml('class="chart chart--kit chart--ranked"')
            ->assertDontSee(__('manager_advanced.sections.bookings'))
            ->assertDontSee(__('manager_advanced.states.empty_title'));

        Livewire::withoutLazyLoading()->actingAs($owner)
            ->test(Compare::class, ['range' => 'today'])
            ->assertSee(__('manager_advanced.compare.modes.periods'))
            ->assertSee(__('manager_advanced.metric.arrivals'))
            ->call('setMode', 'employees')
            ->assertSet('mode', 'employees')
            ->assertSee(__('manager_advanced.compare.pick_two'))
            ->call('toggle', 'not-a-uuid')
            ->assertSet('selected', fn (array $selected): bool => ! in_array('not-a-uuid', $selected, true));

        $library = Livewire::withoutLazyLoading()->actingAs($owner)
            ->test(Library::class, ['report' => 'employee_analysis', 'range' => 'today'])
            ->assertSee(__('manager_advanced.reports.employee_analysis'))
            ->assertSee(__('manager_advanced.library.detail'))
            ->assertSee('Ahmed')
            ->assertSeeHtml('compare_from=');

        Livewire::withoutLazyLoading()->actingAs($owner)
            ->test(Library::class, ['report' => 'no_such_report'])
            ->assertSee(__('manager_advanced.states.unknown_title'));

        expect($library->html())->not->toContain('manager_advanced.');
    });
});

it('keeps the toolbar honest: presets, custom limits, comparison modes and views', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $this->seedBookableCenter();
        $tomorrow = CarbonImmutable::now('Asia/Baghdad')->addDay()->toDateString();

        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(AdvancedReports::class)
            ->call('setRange', 'last_month')
            ->assertSet('range', 'last_month')
            ->call('setRange', 'not_a_preset')
            ->assertSet('range', 'last_month')
            ->call('setComparison', 'last_year')
            ->assertSet('comparison', 'last_year')
            ->call('setComparison', 'next_decade')
            ->assertSet('comparison', 'previous')
            ->call('setRange', 'custom')
            ->assertSet('customOpen', true)
            ->set('customFrom', '2026-01-01')
            ->set('customTo', $tomorrow)
            ->call('applyCustomRange')
            ->assertHasErrors(['customTo'])
            ->assertSet('range', 'last_month')
            ->call('setView', 'compare')
            ->assertSet('view', 'compare')
            ->call('setView', 'anything')
            ->assertSet('view', 'insights')
            ->call('resetFilters')
            ->assertSet('range', 'this_month')
            ->assertSet('comparison', 'previous');

        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(AdvancedReports::class, ['report' => 'queue_trends'])
            ->assertSet('view', 'reports')
            ->assertSee(__('manager_advanced.reports.queue_trends'));
    });
});

it('exports the report CSV for the page comparison period, audited, and refuses a bad one', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $this->actingAs($owner, 'web');
        $today = CarbonImmutable::now('Asia/Baghdad')->toDateString();
        $lastYear = CarbonImmutable::now('Asia/Baghdad')->subYear()->toDateString();

        $this->get("http://{$slug}.localhost:8000/manager/advanced-reports/period_comparison/export.csv?from={$today}&to={$today}&compare_from={$lastYear}&compare_to={$lastYear}")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $audit = TenantAuditLog::query()->where('action', 'advanced_report.exported')->latest('id')->firstOrFail();

        expect($audit->meta['compare_from'] ?? null)->toBe($lastYear)
            ->and($audit->meta['compare_to'] ?? null)->toBe($lastYear);

        $this->get("http://{$slug}.localhost:8000/manager/advanced-reports/period_comparison/export.csv?from={$today}&to={$today}&compare_from={$today}&compare_to={$lastYear}")
            ->assertSessionHasErrors('compare_to');
    });
});

it('draws sales and booking intelligence with each chart type from real bookings, invoices and payments', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
        $owner = $this->ownerWithCatalogAccess();
        $seed = $this->seedBookableCenter();
        $yesterday = CarbonImmutable::now('Asia/Baghdad')->subDay()->toDateString();

        // Three bookings scheduled yesterday at noon: one completed, one cancelled, one still booked.
        foreach (['completed', 'cancelled', 'booked'] as $i => $status) {
            $appointment = $this->bookFor($seed, $owner, $this->seedCustomer('Booker '.$i, '0750 555 60'.$i.'0'), 1, sprintf('%02d:00', 10 + $i));
            $appointment->forceFill([
                'status' => $status,
                'starts_at' => $this->localTime($seed['branch'], $yesterday, '12:00'),
                'ends_at' => $this->localTime($seed['branch'], $yesterday, '12:30'),
            ])->save();
        }

        $invoice = $this->issuedInvoice($seed, $owner);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::Cash, 5000);
        app(CollectDeskPayment::class)($invoice, $owner, PaymentMethod::ManualElectronic, 5000, 'Bank transfer');

        $html = Livewire::withoutLazyLoading()->actingAs($owner)
            ->test(Workspace::class, ['range' => 'last_7_days'])
            ->assertSee(__('manager_advanced.sections.sales'))
            ->assertSee(__('manager_advanced.sections.bookings'))
            ->assertSee(__('manager_advanced.cards.billed_vs_collected'))
            ->assertSee(__('manager_advanced.cards.payment_methods'))
            ->assertSee(__('manager_advanced.cards.booking_status'))
            ->assertSee(__('manager_advanced.cards.busy_hours'))
            ->assertSee(__('manager_advanced.metric.cancellation_rate'))
            ->assertSeeHtml('class="chart__swatch" data-series="2"')
            ->assertSeeHtml('class="chart chart--kit chart--donut"')
            ->assertSeeHtml('class="chart chart--kit chart--radial"')
            ->assertSeeHtml('class="chart chart--kit chart--heatmap"')
            ->assertSee('20,000')
            ->html();

        expect(str_contains($html, 'manager_advanced.'))->toBeFalse();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
