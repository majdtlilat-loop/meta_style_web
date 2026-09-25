<?php

declare(strict_types=1);

namespace App\Livewire\Center\AdvancedReports;

use App\Kernel\Authorization\Permission;
use App\Kernel\Tenancy\Contracts\TenantContext;
use App\Kernel\Usage\Usage;
use App\Modules\AdvancedReports\Application\AdvancedReportCatalog;
use App\Modules\AdvancedReports\Application\AnalyzeAdvancedReport;
use App\Modules\AdvancedReports\Data\AnalysisAnswer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Reactive;
use Livewire\Component;
use Throwable;

/**
 * AI Insights: contextual RAYAN analysis of one catalog report.
 *
 * The analysis is recomputed on the server from the same authorized report
 * (never from anything the browser holds) and sent as the Phase 14 structured
 * context only — no SQL, no tools, no action. It spends the separate
 * `advanced_report_ai_runs` allowance, is throttled like the API route
 * (`throttle:report-analysis`, 10 a minute per center and person — a Livewire
 * action never passes route middleware), and is written in the language the
 * manager reads. With no configured provider the panel says only that AI
 * analysis is unavailable; every other section of the page is unaffected.
 */
final class AiInsights extends Component
{
    use AdvancedScope;

    /** Mirrors the API's `report-analysis` limiter. */
    public const ASKS_PER_MINUTE = 10;

    #[Reactive]
    public string $report = '';

    #[Reactive]
    public string $range = 'this_month';

    #[Reactive]
    public ?string $from = null;

    #[Reactive]
    public ?string $to = null;

    #[Reactive]
    public string $comparison = 'previous';

    #[Reactive]
    public string $branch = '';

    /** The report analysed when the page offers a choice (insights view). */
    public string $target = '';

    public bool $rayanOpen = false;

    public string $question = '';

    /** @var list<array{role: string, text: string}> */
    public array $analysisMessages = [];

    public string $analysisError = '';

    /** @var array<string, list<string>> */
    public array $insights = [];

    public string $insightsFor = '';

    public string $insightsError = '';

    public function openRayan(): void
    {
        $this->rayanOpen = true;
    }

    public function closeRayan(): void
    {
        $this->rayanOpen = false;
    }

    public function useReport(string $code): void
    {
        if (isset($this->permitted(app(AdvancedReportCatalog::class))[$code])) {
            $this->target = $code;
            $this->insights = [];
            $this->insightsFor = '';
            $this->insightsError = '';
        }
    }

    public function ask(AnalyzeAdvancedReport $analyze, AdvancedReportCatalog $catalog, TenantContext $tenants): void
    {
        $this->analysisError = '';
        $question = trim($this->question);

        if ($question === '' || mb_strlen($question) > 1000) {
            $this->analysisError = __('manager_advanced.ai.failure.invalid');

            return;
        }

        if (($limited = $this->throttled($tenants)) !== null) {
            $this->analysisError = $limited;

            return;
        }

        try {
            $period = $this->advancedPeriod();
            [$current, $compared] = $this->reportRequests($period);
            $answer = $analyze($this->code($catalog), $this->viewer(), $current, $question, app()->getLocale(), $compared);

            if (! $answer->ok) {
                $this->analysisError = $this->failure($answer);

                return;
            }

            $this->analysisMessages[] = ['role' => 'user', 'text' => $question];
            $this->analysisMessages[] = ['role' => 'assistant', 'text' => (string) $answer->text];
            $this->question = '';
        } catch (Throwable $failure) {
            $this->analysisError = $this->readFailure($failure);
        }
    }

    public function generate(AnalyzeAdvancedReport $analyze, AdvancedReportCatalog $catalog, TenantContext $tenants): void
    {
        $this->insightsError = '';

        if (($limited = $this->throttled($tenants)) !== null) {
            $this->insightsError = $limited;

            return;
        }

        try {
            $period = $this->advancedPeriod();
            [$current, $compared] = $this->reportRequests($period);
            $answer = $analyze->insights($this->code($catalog), $this->viewer(), $current, app()->getLocale(), $compared);

            if (! $answer->ok) {
                $this->insightsError = $this->failure($answer);

                return;
            }

            $this->insights = $answer->sections;
            $this->insightsFor = $this->fingerprint($catalog);
        } catch (Throwable $failure) {
            $this->insightsError = $this->readFailure($failure);
        }
    }

    public function render(AnalyzeAdvancedReport $analyze, AdvancedReportCatalog $catalog, Usage $usage): View
    {
        if (! $this->mayRead()) {
            return view('livewire.center.advanced-reports.ai-insights', ['state' => 'hidden']);
        }

        $permitted = $this->permitted($catalog);

        if ($permitted === []) {
            return view('livewire.center.advanced-reports.ai-insights', ['state' => 'hidden']);
        }

        if (! $analyze->available()) {
            $this->rayanOpen = false;

            return view('livewire.center.advanced-reports.ai-insights', ['state' => 'unavailable']);
        }

        // Insights belong to the period, branch and report they were made for.
        if ($this->insightsFor !== '' && $this->insightsFor !== $this->fingerprint($catalog)) {
            $this->insights = [];
            $this->insightsFor = '';
        }

        $code = $this->code($catalog, $permitted);
        $period = $this->advancedPeriod();
        $locale = app()->getLocale();

        return view('livewire.center.advanced-reports.ai-insights', [
            'state' => 'ready',
            'code' => $code,
            'reportTitle' => __('manager_advanced.reports.'.$code),
            'choices' => $this->report === '' ? array_map(static fn (string $key): array => ['code' => $key, 'label' => __('manager_advanced.reports.'.$key)], array_keys($permitted)) : [],
            'context' => $period->label($locale).' · '.__('manager_advanced.ai.versus', ['period' => $period->comparisonLabel($locale)]).' · '.($this->branchUuid() === null ? __('manager_advanced.ai.scope_all') : __('manager_advanced.ai.scope_one')),
            'allowance' => $this->allowance($usage),
            'prompts' => array_values((array) __('manager_advanced.ai.prompts')),
            'sections' => array_map(static fn (string $key): array => ['key' => $key, 'title' => __('manager_advanced.ai.sections.'.$key)], array_keys($this->insights)),
            // Generated for exactly this view, and the report supported no heading.
            'nothing' => $this->insightsFor !== '' && $this->insights === [],
        ]);
    }

    /**
     * What is left of the separate Advanced Report AI allowance this period.
     *
     * @return array{text: string, used: int, allowance: int|null, remaining: int|null, percent: int|null}|null
     */
    private function allowance(Usage $usage): ?array
    {
        try {
            $summary = $usage->summary('advanced_report_ai_runs');
        } catch (Throwable $failure) {
            report($failure);

            return null;
        }

        return [
            'text' => $summary->isUnlimited()
                ? (string) __('manager_advanced.ai.unlimited')
                : trans_choice('manager_advanced.ai.remaining', (int) $summary->remaining, ['count' => number_format((int) $summary->remaining), 'total' => number_format((int) $summary->allowance)]),
            'used' => $summary->used,
            'allowance' => $summary->allowance,
            'remaining' => $summary->remaining,
            'percent' => $summary->allowance === null ? null : ($summary->allowance > 0 ? min(100, (int) round($summary->used / $summary->allowance * 100)) : 100),
        ];
    }

    private function throttled(TenantContext $tenants): ?string
    {
        $key = 'advanced-report-analysis:'.$tenants->id().'|'.$this->viewer()->getKey();

        if (RateLimiter::tooManyAttempts($key, self::ASKS_PER_MINUTE)) {
            return (string) __('manager_advanced.ai.failure.rate_limited', ['seconds' => RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);

        return null;
    }

    private function failure(AnalysisAnswer $answer): string
    {
        return match ($answer->failureCode) {
            'quota' => (string) __('manager_advanced.ai.failure.quota'),
            'assistant_unavailable' => (string) __('manager_advanced.ai.unavailable'),
            default => (string) __('manager_advanced.ai.failure.failed'),
        };
    }

    /**
     * The report analysed: the page's report (reports view), else the one
     * chosen here, else Period comparison, else the first permitted.
     *
     * @param  array<string, mixed>|null  $permitted
     */
    private function code(AdvancedReportCatalog $catalog, ?array $permitted = null): string
    {
        $permitted ??= $this->permitted($catalog);

        foreach ([$this->report, $this->target, 'period_comparison'] as $candidate) {
            if ($candidate !== '' && isset($permitted[$candidate])) {
                return $candidate;
            }
        }

        return (string) array_key_first($permitted);
    }

    private function fingerprint(AdvancedReportCatalog $catalog): string
    {
        return implode('|', [$this->code($catalog), $this->range, (string) $this->from, (string) $this->to, $this->comparison, (string) $this->branchUuid()]);
    }

    /** @return array<string, array{title: string, description: string, permissions: list<string>}> */
    private function permitted(AdvancedReportCatalog $catalog): array
    {
        return array_filter($catalog->all(), function (array $definition): bool {
            foreach ($definition['permissions'] as $code) {
                $permission = Permission::tryFrom($code);

                if (! $permission instanceof Permission || ! $this->viewer()->hasPermission($permission)) {
                    return false;
                }
            }

            return true;
        });
    }
}
