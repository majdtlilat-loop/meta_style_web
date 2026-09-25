<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Reporting\ReadTarget;
use App\Livewire\Center\AdvancedReports;
use App\Livewire\Center\Reports;
use App\Modules\Reports\Application\ReportRequestFactory;
use App\Modules\Reports\Application\StandardReports;
use App\Modules\Reports\Data\ReportResult;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Standard and Advanced report pages
|--------------------------------------------------------------------------
|
| Three different "no"s (no product, no report access, not THIS report), a
| default that is a report the viewer may actually open, translated labels
| instead of domain English or raw keys, and filters that are real values
| of the thing they filter.
|
*/

function pageKpi(ReportResult $result, string $key): mixed
{
    return collect($result->kpis)->firstWhere('key', $key)?->value;
}

it('opens the first report the viewer may read, and refuses only the others', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $user = $this->staffWith([Permission::ReportView, Permission::AppointmentView], 'bookings@reports.test');

        // Business overview needs five permissions this role lacks: the page
        // opens on Booking activity instead of a global lock.
        Livewire::actingAs($user)
            ->test(Reports::class)
            ->assertOk()
            ->assertSee(__('manager_reports.standard.booking_activity.title'))
            ->assertDontSee(__('manager_reports.standard.business_overview.title'))
            ->assertDontSee(__('manager_reports.states.report_forbidden_title'));

        Livewire::actingAs($user)
            ->test(Reports::class, ['report' => 'sales_payments'])
            ->assertOk()
            ->assertSee(__('manager_reports.states.report_forbidden_title'))
            // The report the role CAN read is still offered.
            ->assertSee(__('manager_reports.standard.booking_activity.title'));
    });
});

it('separates "no report access" from "not in the plan"', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $noAccess = $this->staffWith([Permission::AppointmentView], 'no-access@reports.test');

        Livewire::actingAs($noAccess)
            ->test(Reports::class)
            ->assertOk()
            ->assertSee(__('manager_reports.states.forbidden_title'));

        // The owner may read reports; the center simply does not have them.
        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(Reports::class)
            ->assertOk()
            ->assertSee(__('platform_labels.entitlement.reports_standard'))
            ->assertDontSee(__('manager_reports.states.forbidden_title'));

        $this->grantEntitlement('reports_standard', null);

        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(AdvancedReports::class)
            ->assertOk()
            // The shared locked-feature state (the page's own "Unlock…" intro was removed as generic copy).
            ->assertSee(__('manager_features.ui.eyebrow'))
            ->assertDontSee(__('manager_reports.actions.ask'));
    });
});

it('speaks the viewer language, with no raw key or domain English label', function (string $locale): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function () use ($locale): void {
        $this->grantEntitlement('reports_standard', null);
        $this->seedBookableCenter();
        app()->setLocale($locale);

        $html = Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(Reports::class, ['report' => 'booking_activity'])
            ->assertOk()
            ->assertSee(trans('manager_reports.page.title', [], $locale))
            // The analytics page labels its KPIs from its own group (std.metrics).
            ->assertSee(trans('manager_reports.std.metrics.scheduled', [], $locale))
            ->assertSee(trans('manager_reports.values.source.public_web', [], $locale))
            ->html();

        expect(preg_match('/\bmanager_reports\.[a-z_]+/', strip_tags($html)))->toBe(0);

        if ($locale !== 'en') {
            expect(str_contains(strip_tags($html), 'Booking activity'))->toBeFalse();
        }
    });
})->with(['en', 'ar', 'ckb']);

it('filters bookings by their real source code and by the reserved employee', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $other = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Other Stylist');
        $appointment = $this->bookFor($seed, $owner, $this->seedCustomer('Filtered Fatima', '0750 555 0202'), 1, '10:00');
        $day = $appointment->localDate();
        $run = function (array $filters) use ($owner, $day): ReportResult {
            $request = app(ReportRequestFactory::class)->make($owner->branchScope(), $day, $day, [], ReadTarget::Primary, $filters);

            return app(StandardReports::class)->run('booking_activity', $owner, $request);
        };

        // Staff bookings are `staff`; "online" is `public_web`, never `public`.
        expect(pageKpi($run([]), 'scheduled'))->toBe(1)
            ->and(pageKpi($run(['source' => 'staff']), 'scheduled'))->toBe(1)
            ->and(pageKpi($run(['source' => 'public_web']), 'scheduled'))->toBe(0)
            ->and(pageKpi($run(['employee' => $seed['employee']->uuid]), 'scheduled'))->toBe(1)
            ->and(pageKpi($run(['employee' => $other->uuid]), 'scheduled'))->toBe(0)
            ->and(pageKpi($run(['service' => $seed['service']->uuid]), 'scheduled'))->toBe(1);

        // A filter the report does not support never narrows it.
        $request = app(ReportRequestFactory::class)->make($owner->branchScope(), $day, $day, [], ReadTarget::Primary, ['source' => 'public_web']);
        expect(pageKpi(app(StandardReports::class)->run('business_overview', $owner, $request), 'scheduled_bookings'))->toBe(1);
    });
});

it('offers every booking source by its stored code', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);

        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(Reports::class, ['report' => 'booking_activity'])
            ->assertSeeHtml('value="public_web"')
            ->assertSeeHtml('value="whatsapp"')
            ->assertSeeHtml('value="customer_account"')
            ->assertDontSeeHtml('value="public"');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
