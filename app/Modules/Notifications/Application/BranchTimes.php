<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use Carbon\CarbonInterface;

/**
 * An instant, as the customer of a particular branch would read it.
 *
 * A center with a branch in Baghdad and one in Istanbul is a supported case, so
 * "your appointment at 14:30" is a per-branch question and never the server's
 * clock (docs/10-API-FOUNDATION.md §8). Storage stays UTC; only the reading
 * changes.
 *
 * The zone is looked up once per branch per request. A reminder sweep walks
 * many appointments across a handful of branches, and a query per appointment
 * would be a query per row for an answer that never changes (§18).
 */
final class BranchTimes
{
    /** @var array<int, string> */
    private array $zones = [];

    public function timezone(int $branchId): string
    {
        if (isset($this->zones[$branchId])) {
            return $this->zones[$branchId];
        }

        $zone = Branch::query()->whereKey($branchId)->value('timezone');

        return $this->zones[$branchId] = is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    /**
     * ISO-8601 with the branch's own offset, so nothing downstream has to
     * guess which clock it is looking at.
     */
    public function iso(CarbonInterface $instant, int $branchId): string
    {
        return BranchClock::toLocal($instant->toImmutable(), $this->timezone($branchId))->toIso8601String();
    }
}
