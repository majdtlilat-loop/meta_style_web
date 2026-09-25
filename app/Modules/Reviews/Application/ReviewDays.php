<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Time\BranchClock;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Reviews from the 3rd to the 5th", as each BRANCH counts days.
 *
 * A review belongs to a visit at one branch, and "the 5th" at a branch in
 * Baghdad is a different UTC window from "the 5th" at one in Istanbul. So the
 * dates are turned into one half-open UTC window PER TIMEZONE — from the local
 * midnight that starts the first day to the local midnight after the last —
 * and a review counts when it falls in its own branch's window:
 *
 *     (branch_id IN (…Baghdad…) AND submitted_at >= … AND submitted_at < …)
 *  OR (branch_id IN (…Istanbul…) AND submitted_at >= … AND submitted_at < …)
 *
 * One query, indexed columns, no timezone functions in SQL, and the LAST day is
 * included — a bare `<= 'Y-m-d'` compared a DATETIME with midnight and dropped
 * every review written on the day a manager asked about.
 */
final readonly class ReviewDays
{
    private function __construct(
        public ?string $from,
        public ?string $to,
    ) {}

    /**
     * From two branch-local dates (`Y-m-d`), either of which may be open.
     * Anything that is not a plain date is ignored rather than guessed at.
     */
    public static function between(?string $from, ?string $to): self
    {
        return new self(self::date($from), self::date($to));
    }

    public function isOpen(): bool
    {
        return $this->from === null && $this->to === null;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyTo(Builder $query, string $column = 'submitted_at', string $branchColumn = 'branch_id'): Builder
    {
        if ($this->isOpen()) {
            return $query;
        }

        /** @var array<string, list<int>> $byZone */
        $byZone = [];

        foreach (Branch::query()->get(['id', 'timezone']) as $branch) {
            $zone = $branch->timezone !== '' ? $branch->timezone : 'UTC';
            $byZone[$zone][] = (int) $branch->getKey();
        }

        if ($byZone === []) {
            // No branch, no review: keep the query honest and empty.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $outer) use ($byZone, $column, $branchColumn): void {
            foreach ($byZone as $zone => $branchIds) {
                $outer->orWhere(function (Builder $window) use ($zone, $branchIds, $column, $branchColumn): void {
                    $window->whereIn($branchColumn, $branchIds);

                    if ($this->from !== null) {
                        $window->where($column, '>=', BranchClock::toUtcOrShift($this->from, 0, $zone));
                    }

                    if ($this->to !== null) {
                        // The local midnight AFTER the last day, exclusive.
                        $window->where($column, '<', BranchClock::toUtcOrShift($this->to, 24 * 60, $zone));
                    }
                });
            }
        });
    }

    private static function date(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))
            ? $value
            : null;
    }
}
