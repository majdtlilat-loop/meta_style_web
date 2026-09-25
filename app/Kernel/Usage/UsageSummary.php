<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

use App\Kernel\Usage\Models\UsageCounter;
use Carbon\CarbonImmutable;

/**
 * One resource, as a manager's dashboard needs it.
 *
 * Computed in ONE place so the dashboard, the threshold alert and the Super
 * Admin projection cannot disagree about what 85% means or about what
 * "unlimited" looks like (docs/26-USAGE-QUOTAS.md §10).
 *
 * `allowance`, `percent` and `remaining` are all null together, and that triple
 * is what UNLIMITED means. A percentage of unlimited is not 0 and not 100 — it
 * does not exist — and a surface that rendered either would be stating a limit
 * the center does not have.
 */
final readonly class UsageSummary
{
    public function __construct(
        public string $resource,
        public string $group,
        public int $used,
        public ?int $allowance,
        public ?int $percent,
        public ?int $remaining,
        public UsageStatus $status,
        /** Whether hitting the allowance actually refuses anything (§3). */
        public bool $enforced,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public ?CarbonImmutable $lastActivityAt,
    ) {}

    /**
     * @param  list<array{percent: int, status: UsageStatus}>  $thresholds
     */
    public static function of(
        string $resource,
        string $group,
        UsageCounter $counter,
        bool $enforced,
        array $thresholds,
    ): self {
        $percent = $counter->percent();

        return new self(
            resource: $resource,
            group: $group,
            used: $counter->used,
            allowance: $counter->allowance_snapshot,
            percent: $percent,
            remaining: $counter->remaining(),
            status: UsageStatus::forPercent($percent, $thresholds),
            enforced: $enforced,
            periodStart: $counter->period_start,
            periodEnd: $counter->period_end,
            lastActivityAt: $counter->last_activity_at,
        );
    }

    public function isUnlimited(): bool
    {
        return $this->allowance === null;
    }

    /**
     * The presentation shape. An allow-list, and free of anything commercial
     * the center is not party to: no unit cost, no platform margin, no provider
     * account identifier (§14).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'resource' => $this->resource,
            'group' => $this->group,
            'used' => $this->used,
            'allowance' => $this->allowance,
            'percent' => $this->percent,
            'remaining' => $this->remaining,
            'status' => $this->status->value,
            'enforced' => $this->enforced,
            'unlimited' => $this->isUnlimited(),
            'period_start' => $this->periodStart->toIso8601String(),
            'period_end' => $this->periodEnd->toIso8601String(),
            'last_activity_at' => $this->lastActivityAt?->toIso8601String(),
        ];
    }
}
