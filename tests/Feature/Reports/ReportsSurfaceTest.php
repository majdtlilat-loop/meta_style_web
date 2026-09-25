<?php

declare(strict_types=1);

use App\Livewire\Center\AdvancedReports;
use App\Livewire\Center\AdvancedReports\AiInsights;
use App\Livewire\Center\Reports;
use App\Modules\Rayan\Application\AiProviderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\Support\FakeAiProvider;

it('renders responsive Standard and Advanced report surfaces with an accessible RAYAN drawer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $owner = $this->ownerWithCatalogAccess();
        Auth::guard('web')->login($owner);

        Livewire::test(Reports::class)
            ->assertOk()
            ->assertSee('Operational reporting')
            ->assertSee('CSV')
            ->assertSee('Print')
            ->assertDontSee('Ask RAYAN');

        Livewire::test(AdvancedReports::class)
            ->assertOk()
            ->assertSee('Not in your plan')
            ->assertDontSee('Ask RAYAN');

        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();

        // No AI provider is configured here (as locally): the page works and
        // its AI panel only says analysis is unavailable — no dead button.
        Livewire::test(AdvancedReports::class)
            ->assertOk()
            ->assertSee('AI analysis is currently unavailable.')
            ->assertDontSee('Ask RAYAN');

        // With a provider, the AI panel (its own component) opens the drawer.
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with(new FakeAiProvider));

        Livewire::test(AiInsights::class)
            ->assertOk()
            ->assertSee('Ask RAYAN')
            ->call('openRayan')
            ->assertSeeHtml('role="dialog"')
            ->assertSeeHtml('aria-modal="true"')
            ->assertSee('Uses Advanced Report AI allowance');
    });
});

it('serves Standard and Advanced reports through the API and CSV export surfaces', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);
    $today = CarbonImmutable::now()->toDateString();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->configureReportingConnection();
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/reports/booking_activity?from={$today}&to={$today}")
        ->assertOk()
        ->assertJsonPath('data.report.code', 'booking_activity');

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/advanced-reports/employee_analysis?from={$today}&to={$today}")
        ->assertOk()
        ->assertJsonPath('data.report.code', 'employee_analysis');

    $this->withHeaders($headers)
        ->getJson('/api/v1/tenant/usage')
        ->assertOk()
        ->assertJsonPath('data.usage.advanced_reports.0.resource', 'advanced_report_ai_runs');

    // The Manager lives on the center's own host under /manager (the old
    // /center/... paths no longer exist), signed in like every Manager page —
    // a browser session, not the API token the calls above carried.
    $slug = $center['registration']->requested_slug;
    $this->flushHeaders();

    $this->asCenter($center['tenant'], function () use ($owner, $slug, $today): void {
        // Named: the token calls above made `sanctum` the default guard.
        $this->actingAs($owner, 'web');

        $this->get("http://{$slug}.localhost:8000/manager/reports/booking_activity/export.csv?from={$today}&to={$today}")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload("booking_activity-{$today}-{$today}.csv");

        $this->get("http://{$slug}.localhost:8000/manager/advanced-reports/employee_analysis/export.csv?from={$today}&to={$today}")
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertDownload("employee_analysis-{$today}-{$today}.csv");
    });
});

it('keeps Standard Reports available when the optional reporting connection is missing', function (): void {
    $center = $this->registerCenter();
    $today = CarbonImmutable::now()->toDateString();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        config()->set('database.connections.reporting_template.host', null);
        config()->set('database.connections.reporting_template.username', null);
    });

    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/reports/booking_activity?from={$today}&to={$today}")
        ->assertOk();

    $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/advanced-reports/employee_analysis?from={$today}&to={$today}")
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'REPORTING.UNAVAILABLE');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
