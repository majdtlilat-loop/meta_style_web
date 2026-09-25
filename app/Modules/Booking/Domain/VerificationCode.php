<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain;

use Random\RandomException;
use SensitiveParameter;

/**
 * The secret that proves someone holds one particular booking.
 *
 * Ten Crockford Base32 characters — about fifty bits — because unlike a review
 * link or an invoice share token this one is READ ALOUD. A customer says it to
 * a receptionist or types it into a phone keyboard, so sixty-four hex
 * characters is not an option, and the design problem is entirely about the
 * gap between "short enough to dictate" and "not guessable"
 * (docs/24-BOOKING-VERIFICATION.md §2).
 *
 * ## Why Crockford, and why the mapping matters
 *
 * Crockford's alphabet drops `I`, `L`, `O` and `U`: the first three because
 * they are indistinguishable from `1` and `0` in most typefaces and in most
 * handwriting, the last because of what it makes possible to spell. Then
 * {@see normalise()} maps the confusable characters BACK — a customer who
 * reads `0` as `O` is not wrong about their booking, and refusing them would
 * be a product deciding that a font choice is the customer's problem.
 *
 * Separators are dropped for the same reason. `X4K7-9TB2M0` is how a person
 * writes down ten characters, and it is the same code.
 *
 * ## Why fifty bits is enough, stated honestly
 *
 * Fifty bits is not cryptographic strength, and it is not claimed to be.
 * Guessing one is ~10^15 attempts against a REFERENCE THAT MUST ALSO BE RIGHT,
 * through a path that is rate limited per reference and per sender and that
 * answers identically for a wrong code and an unknown booking (§§8–9). The
 * entropy is the last line, not the only one.
 *
 * ## What it is not
 *
 * Not the booking reference — that one is public, quotable and authenticates
 * nothing. Not derived from the appointment's id, its uuid, the customer's
 * phone or an invoice number: every one of those is knowable by someone who
 * should not be able to open the booking. Sources of randomness are the CSPRNG
 * and nothing else (§3).
 */
final class VerificationCode
{
    /**
     * Crockford Base32: the digits, then the letters, minus `I`, `L`, `O`, `U`.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /** 10 symbols x 5 bits = 50 bits. */
    public const LENGTH = 10;

    /**
     * A new raw code. It exists in memory, is returned to exactly one caller,
     * and is never written anywhere (§3).
     *
     * @throws RandomException when the platform has no usable CSPRNG — a
     *                         condition to fail on rather than fall back from,
     *                         because the fallback would be a guessable code
     */
    public static function generate(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            // random_int, not a shuffled alphabet or an index into a hash:
            // uniform, and drawn from the CSPRNG for every symbol.
            $code .= self::ALPHABET[random_int(0, 31)];
        }

        return $code;
    }

    /**
     * The canonical form a digest is taken over.
     *
     * DETERMINISTIC AND TOTAL. Every variation a human produces has to collapse
     * to one string, or the same code verifies from one surface and not from
     * another — and because the raw code is never stored, that failure is
     * undebuggable from the data (§4).
     *
     *   lower case   → upper case
     *   `-` ` ` `_`  → dropped
     *   `O` `o`      → `0`
     *   `I` `i` `L` `l` → `1`
     *
     * Anything still outside the alphabet is left in place, so it fails
     * {@see isWellFormed()} rather than being silently deleted into a DIFFERENT
     * valid code.
     */
    public static function normalise(#[SensitiveParameter] string $presented): string
    {
        $upper = mb_strtoupper(trim($presented));

        $stripped = str_replace(['-', ' ', '_', '.'], '', $upper);

        return strtr($stripped, ['O' => '0', 'I' => '1', 'L' => '1']);
    }

    /**
     * Whether a NORMALISED value could be a code at all.
     *
     * Checked before any database work, so a malformed guess costs nothing and
     * cannot be used to time the difference between "no such booking" and
     * "wrong code".
     */
    public static function isWellFormed(#[SensitiveParameter] string $normalised): bool
    {
        return preg_match('/^['.self::ALPHABET.']{'.self::LENGTH.'}$/', $normalised) === 1;
    }

    /**
     * How a code is SHOWN to the person who has to keep it: two groups of five.
     *
     * Presentation only — {@see normalise()} removes this again, and no digest
     * is ever taken over the formatted form.
     */
    public static function format(#[SensitiveParameter] string $code): string
    {
        $normalised = self::normalise($code);

        if (! self::isWellFormed($normalised)) {
            return $code;
        }

        return mb_substr($normalised, 0, 5).'-'.mb_substr($normalised, 5);
    }
}
