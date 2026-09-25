<?php

declare(strict_types=1);

namespace App\Modules\Reports\Data;

use Carbon\CarbonImmutable;

final readonly class ReportResult
{
    /**
     * @param  list<ReportKpi>  $kpis
     * @param  list<array<string, int|float|string|null>>  $series
     * @param  list<array<string, int|float|string|null>>  $rows
     * @param  array<string, string>  $glossary
     * @param  array<string, int|float|string|null>  $coverage
     * @param  array<array-key, string>  $unavailable  stable key => the English
     *                                                 statement (API, CSV, RAYAN);
     *                                                 a surface translates by key
     */
    public function __construct(
        public string $code,
        public string $title,
        public string $description,
        public array $kpis,
        public array $series,
        public array $rows,
        public array $glossary,
        public array $coverage,
        public ?string $currency,
        public CarbonImmutable $asOf,
        public array $unavailable = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'title' => $this->title,
            'description' => $this->description,
            'kpis' => array_map(static fn (ReportKpi $kpi): array => $kpi->toArray(), $this->kpis),
            'series' => $this->series,
            'rows' => $this->rows,
            'glossary' => $this->glossary,
            'coverage' => $this->coverage,
            'currency' => $this->currency,
            'as_of' => $this->asOf->toIso8601String(),
            'unavailable' => array_values($this->unavailable),
            'unavailable_keys' => array_map('strval', array_keys($this->unavailable)),
        ];
    }
}
