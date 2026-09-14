<?php

declare(strict_types=1);

namespace App\Kernel\Privacy;

/**
 * A correlatable, non-reversible stand-in for a personal identifier.
 *
 * Audit entries need to answer "is this the same number as last week" without
 * ever storing the number. A fingerprint does that: equal inputs give equal
 * outputs, and the output reveals nothing on its own.
 *
 * KEYED, not a bare hash. A plain `sha256` of a phone number is not private —
 * Iraqi mobile numbers occupy a space of roughly 10^9, which a laptop enumerates
 * in seconds, so an audit log full of bare digests is an audit log full of phone
 * numbers. `hash_hmac` with `APP_KEY` means an attacker who reads the database
 * still cannot build that table (docs/08-AUDIT-SECURITY.md §17).
 *
 * The consequence to be aware of: rotating `APP_KEY` breaks correlation with
 * fingerprints written before the rotation. That is the right trade — they are
 * a diagnostic aid, not a record anyone is entitled to reverse.
 */
final class Fingerprint
{
    /** Enough to correlate; short enough that nobody mistakes it for the value. */
    private const LENGTH = 16;

    public static function of(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalised = mb_strtolower(trim($value));

        if ($normalised === '') {
            return null;
        }

        return substr(hash_hmac('sha256', $normalised, self::key()), 0, self::LENGTH);
    }

    private static function key(): string
    {
        $key = config('app.key');

        // An empty APP_KEY would silently degrade this to an unkeyed hash, so
        // it fails instead. `metastyle:doctor` checks the same thing before a
        // deployment takes traffic.
        if (! is_string($key) || $key === '') {
            throw new \RuntimeException(
                'APP_KEY is not set; PII fingerprints cannot be generated safely.'
            );
        }

        return $key;
    }
}
