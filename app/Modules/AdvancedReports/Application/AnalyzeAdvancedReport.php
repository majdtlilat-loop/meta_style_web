<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\AdvancedReports\Contracts\ReportAnalyst;
use App\Modules\AdvancedReports\Data\AnalysisAnswer;
use App\Modules\AdvancedReports\Data\ReportAnalysisContext;
use InvalidArgumentException;

final readonly class AnalyzeAdvancedReport
{
    /** The fixed request behind the structured insights (never user text). */
    private const INSIGHTS_REQUEST = 'Give the executive summary, key changes against the comparison period, potential issues, strongest trends and suggested areas to review for this report.';

    public function __construct(
        private AdvancedReports $reports,
        private ReportAnalyst $analyst,
    ) {}

    /** Whether analysis can be requested on this deployment at all. */
    public function available(): bool
    {
        return $this->analyst->available();
    }

    /**
     * @param  string|null  $locale  the viewer's interface language; the answer
     *                               is written in it (null: the analyst's default)
     * @param  ReportReadRequest|null  $comparison  the comparison period the page
     *                                              shows (dates only; null: the
     *                                              equal preceding period)
     */
    public function __invoke(string $code, User $user, ReportReadRequest $request, string $question, ?string $locale = null, ?ReportReadRequest $comparison = null): AnalysisAnswer
    {
        $question = trim($question);

        if ($question === '' || mb_strlen($question) > 1000) {
            throw new InvalidArgumentException('Ask a question of up to 1,000 characters.');
        }

        return $this->analyst->analyze($this->context($code, $user, $request, $locale, $comparison, ReportAnalysisContext::QUESTION), $question, (int) $user->getKey());
    }

    /**
     * The structured AI insights for one report: executive summary, key
     * changes, potential issues, strongest trends and areas to review. One
     * run of the same separate allowance as a question.
     */
    public function insights(string $code, User $user, ReportReadRequest $request, ?string $locale = null, ?ReportReadRequest $comparison = null): AnalysisAnswer
    {
        return $this->analyst->analyze($this->context($code, $user, $request, $locale, $comparison, ReportAnalysisContext::INSIGHTS), self::INSIGHTS_REQUEST, (int) $user->getKey());
    }

    private function context(string $code, User $user, ReportReadRequest $request, ?string $locale, ?ReportReadRequest $comparison, string $mode): ReportAnalysisContext
    {
        /*
         * Recalculate under the caller's current authorization. The AI context
         * is exactly this returned report; no browser-supplied KPI or hidden
         * field is accepted.
         */
        $result = $this->reports->run($code, $user, $request, $comparison);
        $compared = $comparison === null ? PeriodWindows::previous($request) : PeriodWindows::shift($request, $comparison->fromDate, $comparison->toDate);

        return new ReportAnalysisContext(
            report: $result,
            request: $request,
            filters: $request->filters,
            comparisonPeriod: ['from' => $compared->fromDate, 'to' => $compared->toDate],
            locale: $locale,
            mode: $mode,
        );
    }
}
