<?php

declare(strict_types=1);

use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Usage\Models\UsageCounter;
use App\Kernel\Usage\Usage;
use App\Modules\AdvancedReports\Application\AnalyzeAdvancedReport;
use App\Modules\AdvancedReports\Application\PeriodWindows;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Application\RayanReportAnalyst;
use App\Modules\Rayan\Domain\Models\AiRun;
use App\Modules\Reports\Application\ReportRequestFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAiProvider;

it('uses the independent Advanced Report allowance and shared token metering', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 1);
        $this->setAllowance('ai_runs', 0);
        $this->configureReportingConnection();

        $fake = new FakeAiProvider;
        $fake->willSay('Completed visits were stable; the sample is small.');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        $user = $this->ownerWithCatalogAccess();
        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Reporting);
        $answer = app(AnalyzeAdvancedReport::class)('employee_analysis', $user, $request, 'What changed?');

        expect($answer->ok)->toBeTrue()
            ->and(app(Usage::class)->counter('advanced_report_ai_runs')->used)->toBe(1)
            ->and(app(Usage::class)->counter('ai_runs')->used)->toBe(0)
            ->and(app(Usage::class)->counter('ai_input_tokens')->used)->toBe(120)
            ->and(app(Usage::class)->counter('ai_output_tokens')->used)->toBe(40);

        $run = AiRun::query()->sole();

        expect($run->source)->toBe(RayanReportAnalyst::SOURCE)
            ->and($run->report_code)->toBe('employee_analysis')
            ->and($run->conversation_id)->toBeNull()
            ->and($fake->received[0]['tools'])->toBe([])
            ->and($fake->received[0]['transcript'][0]->text)->toContain('report_context')
            ->not->toContain('contact_phone');
    });
});

it('refuses before calling the provider when the Advanced Report allowance is exhausted', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 0);
        $this->configureReportingConnection();

        $fake = new FakeAiProvider;
        $fake->willSay('This must not be returned.');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        $user = $this->ownerWithCatalogAccess();
        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Reporting);
        $answer = app(AnalyzeAdvancedReport::class)('employee_analysis', $user, $request, 'Summarize this.');

        expect($answer->ok)->toBeFalse()
            ->and($answer->failureCode)->toBe('quota')
            ->and($fake->turns())->toBe(0)
            ->and(app(Usage::class)->counter('ai_runs')->used)->toBe(0);
    });
});

it('cannot overspend the last Advanced Report AI unit under a competing consumer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 2);
        $this->configureReportingConnection();

        $usage = app(Usage::class);
        $usage->consume('advanced_report_ai_runs', RayanReportAnalyst::SOURCE, 'warmup');
        $counter = UsageCounter::query()->where('resource', 'advanced_report_ai_runs')->firstOrFail();
        [$second, $close] = secondTenantConnection('advanced_report_ai_race');

        $affected = $second->table('usage_counters')
            ->where('resource', 'advanced_report_ai_runs')
            ->where('period_start', $counter->period_start)
            ->whereRaw('used + 1 <= allowance_snapshot')
            ->update(['used' => DB::raw('used + 1')]);

        expect($affected)->toBe(1);
        $close();

        $fake = new FakeAiProvider;
        $fake->willSay('This must never be called.');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));
        $user = $this->ownerWithCatalogAccess();
        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Reporting);
        $answer = app(AnalyzeAdvancedReport::class)('employee_analysis', $user, $request, 'Summarize this.');

        expect($answer->ok)->toBeFalse()
            ->and($answer->failureCode)->toBe('quota')
            ->and($fake->turns())->toBe(0)
            ->and($usage->counter('advanced_report_ai_runs')->used)->toBe(2)
            ->and($usage->counter('ai_runs')->used)->toBe(0);
    });
});

it('answers structured insights from the safe report context, allow-listed, on the same separate allowance', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 3);
        $this->configureReportingConnection();

        $fake = new FakeAiProvider;
        $fake->willSay('{"summary":["Visits were stable."],"changes":"One change.","issues":[],"trends":["Mornings are busiest."],"review":["Check staffing."],"sql":["SELECT * FROM customers"]}');
        $fake->willSay('Plain prose, not the JSON that was asked for.');
        $fake->willSay('```json'.PHP_EOL.'{"summary":[],"changes":[],"issues":[],"trends":[],"review":[]}'.PHP_EOL.'```');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        $user = $this->ownerWithCatalogAccess();
        $today = CarbonImmutable::now()->toDateString();
        $lastYear = CarbonImmutable::now()->subYear()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Reporting);
        $comparison = PeriodWindows::shift($request, $lastYear, $lastYear);
        $answer = app(AnalyzeAdvancedReport::class)->insights('period_comparison', $user, $request, 'ckb', $comparison);

        expect($answer->ok)->toBeTrue()
            ->and($answer->sections)->toBe([
                'summary' => ['Visits were stable.'],
                'changes' => ['One change.'],
                'trends' => ['Mornings are busiest.'],
                'review' => ['Check staffing.'],
            ])
            ->and($fake->received[0]['tools'])->toBe([])
            ->and($fake->received[0]['instructions'])->toContain('Kurdish (Sorani)')
            ->and(json_decode($fake->received[0]['transcript'][0]->text, true)['report_context']['comparison_period'])->toBe(['from' => $lastYear, 'to' => $lastYear])
            ->and(app(Usage::class)->counter('advanced_report_ai_runs')->used)->toBe(1);

        // A reply that is not the structured shape is kept as the summary — never discarded, never invented.
        $fallback = app(AnalyzeAdvancedReport::class)->insights('period_comparison', $user, $request);

        expect($fallback->sections)->toBe(['summary' => ['Plain prose, not the JSON that was asked for.']])
            ->and(app(Usage::class)->counter('advanced_report_ai_runs')->used)->toBe(2);

        // The asked-for object with every heading empty: nothing to highlight —
        // never the raw JSON presented as a summary.
        $nothing = app(AnalyzeAdvancedReport::class)->insights('period_comparison', $user, $request);

        expect($nothing->ok)->toBeTrue()
            ->and($nothing->sections)->toBe([])
            ->and(app(Usage::class)->counter('advanced_report_ai_runs')->used)->toBe(3);
    });
});

it('opens no run and spends nothing when no provider is configured', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 3);
        $this->configureReportingConnection();

        $user = $this->ownerWithCatalogAccess();
        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Reporting);
        $analyze = app(AnalyzeAdvancedReport::class);

        expect($analyze->available())->toBeFalse();

        $answer = $analyze->insights('employee_analysis', $user, $request);

        expect($answer->ok)->toBeFalse()
            ->and($answer->failureCode)->toBe('assistant_unavailable')
            ->and(AiRun::query()->count())->toBe(0)
            ->and(app(Usage::class)->counter('advanced_report_ai_runs')->used)->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
