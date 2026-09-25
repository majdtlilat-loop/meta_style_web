<?php

declare(strict_types=1);

namespace App\Modules\AdvancedReports\Data;

use App\Kernel\Reporting\ReportReadRequest;
use App\Modules\Reports\Data\ReportResult;

final readonly class ReportAnalysisContext
{
    /** A manager's own question about the report. */
    public const QUESTION = 'question';

    /** The fixed, structured "AI insights" request (summary, changes, issues, trends, areas to review). */
    public const INSIGHTS = 'insights';

    /**
     * @param  array<string, int|string|null>  $filters
     * @param  array{from: string, to: string}|null  $comparisonPeriod  the dates the report compared with
     * @param  string|null  $locale  the VIEWER's interface language (en, ar,
     *                               ckb): the language the answer is written in
     */
    public function __construct(
        public ReportResult $report,
        public ReportReadRequest $request,
        public array $filters,
        public ?array $comparisonPeriod = null,
        public ?string $locale = null,
        public string $mode = self::QUESTION,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $report = $this->report->toArray();

        return [
            'report' => [
                'code' => $report['code'],
                'title' => $report['title'],
                'metric_glossary' => $report['glossary'],
            ],
            'period' => ['from' => $this->request->fromDate, 'to' => $this->request->toDate],
            'comparison_period' => $this->comparisonPeriod,
            'filters' => $this->filters,
            'branch_scope' => array_map(static fn ($window): array => [
                'uuid' => $window->branchUuid,
                'name' => $window->branchName,
                'timezone' => $window->timezone,
            ], $this->request->windows),
            'currency' => $report['currency'],
            'kpis' => $report['kpis'],
            'series' => $report['series'],
            'dimensions' => $report['rows'],
            'coverage' => $report['coverage'],
            'unavailable_metrics' => $report['unavailable'],
            'as_of' => $report['as_of'],
        ];
    }
}
