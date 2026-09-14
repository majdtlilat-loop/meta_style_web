<?php

declare(strict_types=1);

namespace App\Modules\Queue\Domain;

use App\Modules\Queue\Domain\Exceptions\QueueFailed;

/**
 * The letter (or two) a queue number carries: `A012`, `L003`, `R1-007`.
 *
 * ## Why this is validated at all
 *
 * A prefix is not decoration. It is printed on 80mm thermal paper, shown across
 * a room on a television, and read aloud by a speech synthesiser. A prefix
 * containing a space, an emoji or a right-to-left mark would produce a ticket
 * number a customer cannot read back to a receptionist, and an announcement
 * that says something unintended (docs/17-QUEUE.md §6).
 *
 * So: ASCII letters and digits, one to four, upper-cased. Nothing else is
 * refused for tidiness — each excluded character is one the display, the
 * printer or the voice would handle badly.
 *
 * Upper-casing is normalisation, not validation: `l` and `L` must not become
 * two sequences at the same branch on the same day, because that is two
 * customers holding `L001`.
 */
final class TicketPrefix
{
    /** The fallback when neither the service point nor the department names one. */
    public const DEFAULT = 'A';

    private const PATTERN = '/^[A-Z0-9]{1,4}$/';

    /**
     * @throws QueueFailed
     */
    public static function normalise(?string $value): string
    {
        $candidate = mb_strtoupper(trim((string) $value));

        if ($candidate === '') {
            return self::DEFAULT;
        }

        if (preg_match(self::PATTERN, $candidate) !== 1) {
            throw QueueFailed::policy(
                'A ticket prefix must be one to four letters or digits, with no spaces.',
                ['prefix' => $value],
            );
        }

        return $candidate;
    }

    /**
     * The same rule, without throwing, for reading values already stored.
     *
     * A prefix written before this rule existed — or by a migration — must not
     * make a board unrenderable. It falls back to the default, which is wrong
     * in a visible, fixable way rather than wrong in a way that 500s.
     */
    public static function sanitise(?string $value): string
    {
        $candidate = mb_strtoupper(trim((string) $value));

        return preg_match(self::PATTERN, $candidate) === 1 ? $candidate : self::DEFAULT;
    }

    /**
     * `A` + 12 → `A012`.
     *
     * Padded to three digits and then allowed to grow: a center issuing more
     * than 999 tickets in one day at one branch gets `A1000`, which is ugly and
     * readable, rather than a number that wraps or collides.
     */
    public static function format(string $prefix, int $number): string
    {
        return $prefix.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }
}
