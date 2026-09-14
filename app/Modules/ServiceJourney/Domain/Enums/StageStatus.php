<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Enums;

/**
 * Where one service of a visit has got to.
 *
 * ## `waiting` is NOT a queue ticket
 *
 * It means "this service has not started yet". It carries no number, no
 * position, no priority, no display state and no call/recall. Phase 8 will
 * build Queue ON TOP of these tables — reading journey, department, resource
 * and destination — and putting queue fields here now would be guessing at a
 * design nobody has written (docs/13-ROADMAP.md Phase 7 §§19, 44).
 *
 * ## Four states
 *
 *     waiting ──▶ in_service ──▶ completed
 *        │
 *        └──────▶ skipped
 *
 * `skipped` is the customer who declined the beard trim after the haircut. It
 * needs a reason, because "why did this not happen" is the only interesting
 * thing about it and a skipped stage with no explanation is a hole in the
 * record of the visit.
 *
 * A stage cannot be skipped once it has started: something that was performed
 * for ten minutes and then abandoned is a completed stage with a short
 * duration, and calling it skipped would make service-time reporting lie.
 */
enum StageStatus: string
{
    case Waiting = 'waiting';
    case InService = 'in_service';
    case Completed = 'completed';
    case Skipped = 'skipped';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Waiting => [self::InService, self::Skipped],
            self::InService => [self::Completed],
            self::Completed, self::Skipped => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /**
     * Statuses that mean "there is nothing left to do here".
     *
     * A journey may complete when every stage is one of these — skipped counts,
     * because a service the customer declined is finished business (§28).
     *
     * @return list<string>
     */
    public static function settledValues(): array
    {
        return [self::Completed->value, self::Skipped->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Waiting => __('Waiting'),
            self::InService => __('In service'),
            self::Completed => __('Completed'),
            self::Skipped => __('Skipped'),
        };
    }
}
