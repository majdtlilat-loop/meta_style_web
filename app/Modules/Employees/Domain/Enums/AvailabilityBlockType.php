<?php

declare(strict_types=1);

namespace App\Modules\Employees\Domain\Enums;

/**
 * Why an employee is not bookable for a stretch of time.
 *
 * A controlled vocabulary rather than free text, because "why was nobody
 * available on Thursday afternoon" is a question a future report groups by, and
 * a free-text reason turns that into a word cloud.
 *
 * Five cases, all of which a center can name today. Not an attendance or leave
 * taxonomy — there is no sick leave, annual leave, accrual or approval here,
 * because none of those is a scheduling fact and all of them belong to a
 * workforce module that does not exist (docs/13-ROADMAP.md Phase 7 §§13, 45).
 */
enum AvailabilityBlockType: string
{
    /** Lunch, prayer, a cigarette. The everyday case. */
    case Break = 'break';

    /** Away, reason unstated. The honest default when nothing else fits. */
    case Unavailable = 'unavailable';

    case Training = 'training';

    /** Somebody asked for the afternoon off. */
    case Personal = 'personal';

    /** Stock count, a team meeting, deep-cleaning the room. */
    case Admin = 'admin';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $t): string => $t->value, self::cases());
    }

    public function label(): string
    {
        return match ($this) {
            self::Break => __('Break'),
            self::Unavailable => __('Unavailable'),
            self::Training => __('Training'),
            self::Personal => __('Personal'),
            self::Admin => __('Admin'),
        };
    }
}
