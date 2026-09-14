<?php

declare(strict_types=1);

namespace App\Kernel\Time;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A half-open interval in absolute time: `[start, end)`.
 *
 * HALF-OPEN IS THE WHOLE POINT. A 10:00–10:30 appointment occupies 10:00 and
 * every instant up to but NOT including 10:30, so the next customer at 10:30 is
 * not a conflict. Getting that boundary wrong in either direction is the
 * classic booking bug: closed at both ends refuses every back-to-back booking a
 * busy salon depends on, and open at both ends lets two customers overlap by an
 * instant (docs/13-ROADMAP.md Phase 6 §10).
 *
 * Always UTC. Wall-clock reasoning happens in {@see BranchClock}, which turns a
 * branch-local date and time into one of these and back again; nothing else in
 * the Booking Engine is allowed to think in local time.
 */
final readonly class TimeWindow
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {
        if ($end <= $start) {
            throw new InvalidArgumentException(
                'A time window must end after it starts; got '
                .$start->toIso8601String().' → '.$end->toIso8601String().'.'
            );
        }
    }

    public static function of(CarbonImmutable $start, int $minutes): self
    {
        return new self($start->utc(), $start->utc()->addMinutes($minutes));
    }

    /**
     * The overlap rule, in one place.
     *
     *     this.start < other.end  AND  this.end > other.start
     *
     * Strict on both sides. 10:00–10:30 overlaps 10:15–10:45 and does NOT
     * overlap 10:30–11:00.
     *
     * The same expression exists in SQL, in the Booking module's
     * `ConflictFinder`; if one ever changes, both must. It is named here in
     * prose rather than with a `{@see}` tag on purpose — the tag would be
     * turned into a real import, and the Kernel may not depend on a business
     * module (docs/04-MODULE-BOUNDARIES.md §2).
     */
    public function overlaps(self $other): bool
    {
        return $this->start < $other->end && $this->end > $other->start;
    }

    /**
     * Does this window fit entirely inside $outer?
     *
     * Used to test a booking against ONE continuous opening interval. A service
     * that starts at 12:45 and runs 30 minutes is not contained by 09:00–13:00,
     * which is exactly why it must be refused even though its start is inside
     * opening hours.
     */
    public function isContainedBy(self $outer): bool
    {
        return $this->start >= $outer->start && $this->end <= $outer->end;
    }

    public function durationMinutes(): int
    {
        return (int) $this->start->diffInMinutes($this->end);
    }

    /**
     * @return array{start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toIso8601String(),
            'end' => $this->end->toIso8601String(),
        ];
    }
}
