<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain;

/**
 * What a customer and a receptionist call a booking out loud.
 *
 * `B-000412`. Short, unambiguous over a phone line, and deliberately NOT a
 * uuid: `9f1c4e2a-...` is unusable in the one situation a reference exists for,
 * which is a person reading it to another person
 * (docs/24-BOOKING-VERIFICATION.md §1).
 *
 * ## It authenticates NOTHING
 *
 * This is the whole design, and it is why the reference can be derived from the
 * appointment's primary key without apology. It is ENUMERABLE BY DESIGN —
 * anyone can count upwards from `B-000001` — and that is harmless precisely
 * because knowing a reference grants nothing at all. Opening a booking needs
 * the reference AND the {@see VerificationCode}, which is the part with the
 * entropy (§2).
 *
 * Splitting the two apart is what lets the reference be printed on a receipt,
 * read over a counter, quoted in a WhatsApp message and backfilled onto every
 * historical appointment, while the thing that actually grants access stays a
 * secret that exists in one place for one moment.
 *
 * ## What it leaks, stated plainly
 *
 * A center's booking volume and roughly when a booking was made. The same is
 * true of the invoice numbers already printed on every receipt
 * (`docs/18-SALES.md`), and the alternative — a random reference — costs a
 * uniqueness check, a collision retry and the ability to backfill, to hide a
 * number a competitor could estimate by walking past the shop.
 */
final class BookingReference
{
    /**
     * Distinguishes a booking reference from an invoice number at a glance, in
     * a market where both get written on the same scrap of paper.
     */
    private const PREFIX = 'B-';

    /**
     * Six digits reaches a million bookings before the string grows, and a
     * center doing two hundred a day takes fourteen years to get there. It
     * grows rather than wraps: correctness never depends on the width.
     */
    private const PAD = 6;

    public static function forId(int $appointmentId): string
    {
        return self::PREFIX.str_pad((string) $appointmentId, self::PAD, '0', STR_PAD_LEFT);
    }

    /**
     * Accepts what a person typed: spaces, a missing prefix, lower case.
     *
     * `b 412`, `412` and `B-000412` are one booking. Nobody dictating a
     * reference says "capital B, hyphen, zero zero zero".
     */
    public static function normalise(string $presented): string
    {
        $compact = str_replace([' ', '-', '_', '#'], '', mb_strtoupper(trim($presented)));

        if (str_starts_with($compact, 'B')) {
            $compact = mb_substr($compact, 1);
        }

        if (preg_match('/^\d{1,12}$/', $compact) !== 1) {
            // Left as-is so it fails the well-formed check rather than being
            // coerced into a DIFFERENT valid reference.
            return $compact;
        }

        return self::forId((int) $compact);
    }

    public static function isWellFormed(string $normalised): bool
    {
        return preg_match('/^B-\d{'.self::PAD.',12}$/', $normalised) === 1;
    }
}
