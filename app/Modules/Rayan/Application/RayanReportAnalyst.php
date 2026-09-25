<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application;

use App\Kernel\Usage\Exceptions\QuotaExceeded;
use App\Kernel\Usage\Usage;
use App\Modules\AdvancedReports\Contracts\ReportAnalyst;
use App\Modules\AdvancedReports\Data\AnalysisAnswer;
use App\Modules\AdvancedReports\Data\ReportAnalysisContext;
use App\Modules\Rayan\Domain\Data\AiResponse;
use App\Modules\Rayan\Domain\Data\AiTurnItem;
use App\Modules\Rayan\Domain\Enums\AiRunStatus;
use App\Modules\Rayan\Domain\Enums\AiUsage;
use App\Modules\Rayan\Domain\Models\AiRun;
use Carbon\CarbonImmutable;
use Throwable;

final readonly class RayanReportAnalyst implements ReportAnalyst
{
    public const SOURCE = 'advanced_report_analysis';

    public function __construct(
        private RayanSettings $settings,
        private AiProviderRegistry $providers,
        private Usage $usage,
    ) {}

    /**
     * A configured provider exists. Never throws and never says why not: the
     * page shows one neutral unavailable state, not a configuration detail.
     */
    public function available(): bool
    {
        try {
            return $this->providers->has($this->settings->provider())
                && $this->providers->get($this->settings->provider())->capabilities()->available;
        } catch (Throwable $failure) {
            report($failure);

            return false;
        }
    }

    public function analyze(ReportAnalysisContext $context, string $question, int $userId): AnalysisAnswer
    {
        if (! $this->available()) {
            return AnalysisAnswer::failed('assistant_unavailable');
        }

        $provider = $this->providers->get($this->settings->provider());

        $model = $this->settings->reportModel($provider->capabilities());
        $run = AiRun::query()->create([
            'conversation_id' => null,
            'source' => self::SOURCE,
            'report_code' => $context->report->code,
            'requested_by_user_id' => $userId,
            'provider' => $provider->code(),
            'model' => $model,
            'status' => AiRunStatus::Failed,
            'started_at' => CarbonImmutable::now()->utc(),
        ]);

        try {
            $this->usage->consume('advanced_report_ai_runs', self::SOURCE, $run->uuid, 1, $provider->code(), $model);

            $payload = json_encode([
                'report_context' => $context->toArray(),
                'manager_question' => mb_substr(trim($question), 0, 1000),
            ], JSON_THROW_ON_ERROR);

            $response = $provider->respond(
                $model,
                $this->instructions($context->locale, $context->mode),
                [AiTurnItem::user($payload)],
                [],
                min(1200, $this->settings->limits()['max_output_tokens']),
            );

            $this->meterTokens($run, $response, $provider->code(), $model);

            if (! $response->ok || $response->text === null || $response->wantsTools()) {
                $this->finish($run, AiRunStatus::Failed, $response->failureCode ?? 'invalid_report_answer');
                $this->usage->meter(AiUsage::FailedRuns->code(), self::SOURCE, $run->uuid, 1, $provider->code(), $model);

                return AnalysisAnswer::failed($response->failureCode ?? 'failed');
            }

            $this->finish($run, AiRunStatus::Completed);

            return $context->mode === ReportAnalysisContext::INSIGHTS
                ? self::insights($response->text)
                : AnalysisAnswer::answered($response->text);
        } catch (QuotaExceeded) {
            $this->finish($run, AiRunStatus::Exhausted, 'quota');

            return AnalysisAnswer::failed('quota');
        } catch (Throwable $failure) {
            report($failure);
            $this->finish($run, AiRunStatus::Failed, 'internal');
            $this->usage->meter(AiUsage::FailedRuns->code(), self::SOURCE, $run->uuid, 1, $provider->code(), $model);

            return AnalysisAnswer::failed('failed');
        }
    }

    private function instructions(?string $locale, string $mode = ReportAnalysisContext::QUESTION): string
    {
        $base = 'You are RAYAN in read-only report-analysis mode. Analyze only the structured report context supplied in this request. '
            .'Do not claim access to databases, SQL, customer records, hidden branches, or tools. Do not infer missing facts. '
            .'When a requested metric is absent or listed as unavailable, say it is unavailable and explain why. '
            .'Keep denominators, sample sizes, coverage, currency, periods, and freshness explicit. Never recommend a business-domain write. '
            .'Write the whole answer in '.self::answerLanguage($locale).', whatever language the question or the report labels use.';

        if ($mode !== ReportAnalysisContext::INSIGHTS) {
            return $base;
        }

        return $base.' Reply with ONE JSON object and nothing else, with exactly these keys, each an array of at most 4 short plain-text points: '
            .'"summary" (executive summary), "changes" (key changes against the comparison period), "issues" (potential issues), '
            .'"trends" (strongest trends), "review" (suggested areas to review). Use an empty array when the report does not support a heading. '
            .'No markdown, no HTML.';
    }

    /**
     * The structured answer, parsed defensively: a JSON object (optionally in
     * a code fence) with the known headings. Anything else is kept as the
     * executive summary text rather than discarded — never an invented point.
     */
    private static function insights(string $text): AnalysisAnswer
    {
        $json = trim($text);

        if (preg_match('/\{.*\}/s', $json, $match) === 1) {
            $json = $match[0];
        }

        $decoded = json_decode($json, true);

        // The asked-for object, even when every heading is empty (the report
        // supports none): nothing to highlight — never the raw JSON as prose.
        if (is_array($decoded) && array_intersect(array_keys($decoded), AnalysisAnswer::SECTIONS) !== []) {
            return AnalysisAnswer::insights($text, $decoded);
        }

        return AnalysisAnswer::insights($text, ['summary' => [mb_substr(trim($text), 0, 1800)]]);
    }

    /**
     * The language the manager reads the page in. Kurdish is always named as
     * Sorani Kurdish, never by its code.
     */
    public static function answerLanguage(?string $locale): string
    {
        return match ($locale) {
            'ar' => 'Arabic',
            'ckb' => 'Kurdish (Sorani), written in the Arabic script',
            default => 'English',
        };
    }

    private function meterTokens(AiRun $run, AiResponse $response, string $provider, string $model): void
    {
        $run->forceFill([
            'input_tokens' => $response->usage->input,
            'output_tokens' => $response->usage->output,
            'total_tokens' => $response->usage->total,
        ])->save();

        if ($response->usage->input !== null) {
            $this->usage->meter(AiUsage::InputTokens->code(), self::SOURCE, $run->uuid, $response->usage->input, $provider, $model);
        }

        if ($response->usage->output !== null) {
            $this->usage->meter(AiUsage::OutputTokens->code(), self::SOURCE, $run->uuid, $response->usage->output, $provider, $model);
        }
    }

    private function finish(AiRun $run, AiRunStatus $status, ?string $failureCode = null): void
    {
        $run->forceFill([
            'status' => $status,
            'failure_code' => $failureCode,
            'turns' => 1,
            'tool_calls' => 0,
            'finished_at' => CarbonImmutable::now()->utc(),
        ])->save();
    }
}
