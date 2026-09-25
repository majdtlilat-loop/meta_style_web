<?php

declare(strict_types=1);

namespace App\Kernel\Reporting;

use Illuminate\Database\Query\Builder;

final readonly class ReportReadRequest
{
    /**
     * @param  list<BranchWindow>  $windows
     * @param  array<string, int|string|null>  $filters
     */
    public function __construct(
        public ReadTarget $target,
        public string $fromDate,
        public string $toDate,
        public array $windows,
        public array $filters = [],
    ) {}

    public function applyWindow(Builder $query, string $branchColumn, string $timeColumn): Builder
    {
        if ($this->windows === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($branchColumn, $timeColumn): void {
            foreach ($this->windows as $window) {
                $outer->orWhere(function (Builder $branch) use ($window, $branchColumn, $timeColumn): void {
                    $branch->where($branchColumn, $window->branchId)
                        ->where($timeColumn, '>=', $window->fromUtc->format('Y-m-d H:i:s'))
                        ->where($timeColumn, '<', $window->untilUtc->format('Y-m-d H:i:s'));
                });
            }
        });
    }

    public function filter(string $key): int|string|null
    {
        return $this->filters[$key] ?? null;
    }

    /** @return list<int> */
    public function branchIds(): array
    {
        return array_map(static fn (BranchWindow $window): int => $window->branchId, $this->windows);
    }
}
