<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain\Enums;

/**
 * What a submitted review is currently doing.
 *
 *     submitted ──hide──▶ hidden ──unhide──▶ submitted
 *         │
 *         └────flag─────▶ flagged ──unhide/hide──▶ …
 *
 * Three states, and deliberately not a moderation workflow: there is no queue,
 * no approval step and no "pending". A customer's review is published to the
 * center the moment it is submitted — hiding is the exception, taken by a
 * person who has to give a reason (docs/22-REVIEWS.md §8).
 *
 * `hidden` removes a review from the visible rating summary. `flagged` marks
 * one for internal attention and STILL counts: flagging is a note to staff, not
 * a verdict on the customer, and a rating that disappeared the moment somebody
 * clicked "look at this" would make the average a function of staff attention.
 *
 * Nothing deletes a submitted review. A privacy erasure is a separate decision
 * with its own rules, not a button on a moderation screen.
 */
enum ReviewStatus: string
{
    case Submitted = 'submitted';
    case Hidden = 'hidden';
    case Flagged = 'flagged';

    /**
     * Whether this review counts towards the center's published averages
     * (docs/22-REVIEWS.md §24).
     */
    public function countsTowardsRatings(): bool
    {
        return $this !== self::Hidden;
    }

    /**
     * The statuses a rating summary is built from.
     *
     * @return list<string>
     */
    public static function visibleValues(): array
    {
        return [self::Submitted->value, self::Flagged->value];
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => __('Published'),
            self::Hidden => __('Hidden'),
            self::Flagged => __('Flagged'),
        };
    }
}
