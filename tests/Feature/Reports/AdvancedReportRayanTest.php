<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Reporting\ReadTarget;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Usage\Usage;
use App\Livewire\Center\AdvancedReports;
use App\Livewire\Center\AdvancedReports\AiInsights;
use App\Modules\AdvancedReports\Application\AnalyzeAdvancedReport;
use App\Modules\Rayan\Application\AiProviderRegistry;
use App\Modules\Rayan\Domain\Models\AiRun;
use App\Modules\Reports\Application\ReportRequestFactory;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\Support\FakeAiProvider;

/*
|--------------------------------------------------------------------------
| AI Insights (contextual RAYAN) on the Advanced Reports page
|--------------------------------------------------------------------------
|
| The panel lives in its own component (AdvancedReports\AiInsights), so an
| analysis never re-reads the report sections around it. It answers in the
| language the manager reads (Kurdish named as Sorani, never by its code), is
| throttled like the API route — a Livewire action never passes through route
| middleware — says only "unavailable" when no provider is configured, and
| turns a provider failure into a translated state, never a raw code or a
| made-up answer. Tests use the provider fake; the real call is external-only.
|
*/

it('asks for the answer in the viewer language, naming Kurdish as Sorani', function (?string $locale, string $language): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function () use ($locale, $language): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 5);
        $this->configureReportingConnection();

        $fake = new FakeAiProvider;
        $fake->willSay('Stable.');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        $user = $this->ownerWithCatalogAccess();
        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($user->branchScope(), $today, $today, [], ReadTarget::Reporting);
        $answer = app(AnalyzeAdvancedReport::class)('employee_analysis', $user, $request, 'What changed?', $locale);

        expect($answer->ok)->toBeTrue()
            ->and($fake->received[0]['instructions'])->toContain('Write the whole answer in '.$language)
            ->and($fake->received[0]['instructions'])->not->toContain('CKB');
    });
})->with([
    'English by default' => [null, 'English'],
    'Arabic' => ['ar', 'Arabic'],
    'Kurdish Sorani' => ['ckb', 'Kurdish (Sorani)'],
]);

it('throttles the Livewire analysis path before any provider call', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 50);
        $this->configureReportingConnection();

        $fake = new FakeAiProvider;
        $fake->willSay('This must not be called.');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        $user = $this->ownerWithCatalogAccess();
        $key = 'advanced-report-analysis:'.app(TenantContext::class)->id().'|'.$user->getKey();

        expect(AdvancedReports::ASKS_PER_MINUTE)->toBe(10);

        for ($i = 0; $i < AiInsights::ASKS_PER_MINUTE; $i++) {
            RateLimiter::hit($key, 60);
        }

        $limited = strstr(__('manager_advanced.ai.failure.rate_limited'), ':seconds', true);

        Livewire::actingAs($user)
            ->test(AiInsights::class, ['report' => 'employee_analysis'])
            ->call('openRayan')
            ->set('question', 'Summarize this.')
            ->call('ask')
            ->assertSet('analysisMessages', [])
            ->assertSet('analysisError', fn (string $error): bool => str_starts_with($error, (string) $limited))
            ->call('generate')
            ->assertSet('insights', [])
            ->assertSet('insightsError', fn (string $error): bool => str_starts_with($error, (string) $limited));

        expect($fake->turns())->toBe(0);

        RateLimiter::clear($key);
    });
});

it('shows the panel, the drawer and what is left of the separate allowance when a provider is configured', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 7);
        $this->configureReportingConnection();
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with(new FakeAiProvider));

        Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(AiInsights::class, ['report' => 'employee_analysis'])
            ->assertSee(__('manager_advanced.ai.title'))
            ->assertSee(__('manager_advanced.ai.generate'))
            ->assertSee('Ask RAYAN')
            ->assertSee(trans_choice('manager_advanced.ai.remaining', 7, ['count' => 7, 'total' => 7]))
            ->call('openRayan')
            ->assertSeeHtml('role="dialog"')
            ->assertSeeHtml('aria-modal="true"')
            ->assertSee(__('manager_advanced.ai.allowance'));
    });
});

it('says only that AI analysis is unavailable when no provider is configured, and spends nothing', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 5);
        $this->configureReportingConnection();

        // The registered OpenAI adapter has no key here: honestly unavailable.
        $html = Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(AiInsights::class)
            ->assertSee(__('manager_advanced.ai.unavailable'))
            ->assertDontSee('Ask RAYAN')
            ->assertDontSee(__('manager_advanced.ai.generate'))
            ->call('generate')
            ->assertSet('insights', [])
            ->assertSet('insightsError', __('manager_advanced.ai.unavailable'))
            ->html();

        expect(str_contains(strtolower($html), 'api key'))->toBeFalse()
            ->and(str_contains(strtolower($html), 'openai'))->toBeFalse()
            ->and(AiRun::query()->count())->toBe(0)
            ->and(app(Usage::class)->counter('advanced_report_ai_runs')->used)->toBe(0);
    });
});

it('turns a provider failure into a translated state, never a raw code or an invented answer', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 5);
        $this->configureReportingConnection();

        $fake = new FakeAiProvider;
        $fake->willFail('provider_timeout');
        $fake->willFail('provider_timeout');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        $html = Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(AiInsights::class, ['report' => 'employee_analysis'])
            ->call('generate')
            ->assertSet('insights', [])
            ->assertSet('insightsError', __('manager_advanced.ai.failure.failed'))
            ->call('openRayan')
            ->set('question', 'Why?')
            ->call('ask')
            ->assertSet('analysisMessages', [])
            ->assertSet('question', 'Why?')
            ->assertSet('analysisError', __('manager_advanced.ai.failure.failed'))
            ->html();

        expect(str_contains($html, 'provider_timeout'))->toBeFalse()
            ->and(AiRun::query()->where('failure_code', 'provider_timeout')->count())->toBe(2);
    });
});

it('presents structured insights from the safe context, allow-listed and as plain text', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 5);
        $this->configureReportingConnection();

        $fake = new FakeAiProvider;
        $fake->willSay("```json\n".json_encode([
            'summary' => ['Completed visits held steady.'],
            'changes' => ['No material change against the comparison period.'],
            'issues' => ['<script>alert(1)</script>'],
            'trends' => [],
            'review' => ['Check the sample size before acting.'],
            'drop_table' => ['Not a heading the page asked for.'],
        ])."\n```");
        $fake->willSay('{"summary":[],"changes":[],"issues":[],"trends":[],"review":[]}');
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));

        $component = Livewire::actingAs($this->ownerWithCatalogAccess())
            ->test(AiInsights::class)
            ->call('generate')
            ->assertSet('insightsError', '')
            ->assertSee(__('manager_advanced.ai.sections.summary'))
            ->assertSee('Completed visits held steady.')
            ->assertSee(__('manager_advanced.ai.sections.review'))
            ->assertDontSee(__('manager_advanced.ai.sections.trends'))
            ->assertDontSee('Not a heading the page asked for.')
            ->assertDontSeeHtml('<script>alert(1)</script>');

        expect(array_keys($component->get('insights')))->toBe(['summary', 'changes', 'issues', 'review']);

        // Asked again, the report supports no heading: a quiet "nothing stands
        // out" state — not the raw JSON, and the earlier points are gone.
        $component->call('generate')
            ->assertSet('insights', [])
            ->assertSee(__('manager_advanced.ai.nothing'))
            ->assertDontSee('Completed visits held steady.')
            ->assertDontSee('&quot;summary&quot;', false);

        $sent = $fake->received[0];
        $payload = json_decode($sent['transcript'][0]->text, true);

        // The fixed insights request over the Phase 14 structured context — no tools, no SQL.
        expect($sent['tools'])->toBe([])
            ->and($sent['instructions'])->toContain('Reply with ONE JSON object')
            ->and(array_keys($payload['report_context']))->toBe(['report', 'period', 'comparison_period', 'filters', 'branch_scope', 'currency', 'kpis', 'series', 'dimensions', 'coverage', 'unavailable_metrics', 'as_of'])
            ->and($payload['report_context']['report']['code'])->toBe('period_comparison')
            ->and($payload['report_context']['comparison_period'])->not->toBeNull()
            ->and(strtolower($sent['transcript'][0]->text))->not->toContain('select ');
    });
});

it('authorizes every run: a role cannot analyse a report whose records it may not read', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $this->grantEntitlement('reports_standard', null);
        $this->grantEntitlement('reports_advanced', null);
        $this->setAllowance('advanced_report_ai_runs', 5);
        $this->configureReportingConnection();

        $fake = new FakeAiProvider;
        app()->instance(AiProviderRegistry::class, app(AiProviderRegistry::class)->with($fake));
        $visitsOnly = $this->staffWith([Permission::ReportView, Permission::JourneyView], 'visits-ai@advanced.test');

        // The choice lists only what the role may read, and an unlisted code is ignored.
        Livewire::actingAs($visitsOnly)
            ->test(AiInsights::class)
            ->assertSee(__('manager_advanced.reports.employee_analysis'))
            ->assertDontSee(__('manager_advanced.reports.period_comparison'))
            ->call('useReport', 'customer_value')
            ->assertSet('target', '')
            // A forged choice selects nothing either: the run falls back to a report the role may read.
            ->set('target', 'customer_value')
            ->call('generate');

        $context = json_decode($fake->received[0]['transcript'][0]->text, true)['report_context'];

        expect($fake->turns())->toBe(1)
            ->and($context['report']['code'])->toBe('service_analysis');

        Livewire::actingAs($visitsOnly)
            ->test(AiInsights::class, ['report' => 'customer_value'])
            ->assertSee(__('manager_advanced.reports.service_analysis'))
            ->assertDontSee(__('manager_advanced.reports.customer_value'));

        // The action itself refuses the report before any provider call.
        $today = CarbonImmutable::now()->toDateString();
        $request = app(ReportRequestFactory::class)->make($visitsOnly->branchScope(), $today, $today, [], ReadTarget::Reporting);

        expect(fn () => app(AnalyzeAdvancedReport::class)->insights('customer_value', $visitsOnly, $request))->toThrow(AuthorizationException::class)
            ->and($fake->turns())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
