<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Domain;

use Random\RandomException;
use SensitiveParameter;

/**
 * The secret in a customer's review link.
 *
 * The same bearer capability as an invoice share link, and treated the same way
 * (ADR-035, ADR-058): whoever holds the URL may leave exactly one review for
 * exactly one visit.
 *
 *   generated   256 bits of CSPRNG output, 64 hex characters
 *   stored      as SHA-256 only — `review_invitations.token_hash`
 *   plaintext   exists in exactly one place: the URL handed back by the call
 *               that minted it. Never a column, never a log line, never an
 *               audit row, never a notification payload.
 *
 * So a copy of the database opens no review form, and a later read cannot show
 * the URL again — the desk issues a new link instead, exactly as it does for an
 * invoice (docs/22-REVIEWS.md §5).
 *
 * SHA-256 rather than a slow hash, deliberately: there is no dictionary to slow
 * down against 256 random bits, and the public lookup has to be an indexed
 * equality match on the digest.
 */
final class ReviewToken
{
    /** 32 bytes = 256 bits = 64 hex characters. */
    private const BYTES = 32;

    /**
     * A new plaintext secret. Hand it to the caller; persist only {@see hash()}.
     *
     * @throws RandomException when the platform has no usable CSPRNG, which is
     *                         a condition to fail on rather than fall back from
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    /**
     * The value that is safe to store and to query by.
     */
    public static function hash(#[SensitiveParameter] string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Whether a presented value could be a secret at all. Anything else is
     * answered without touching the database.
     */
    public static function isWellFormed(#[SensitiveParameter] string $presented): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $presented) === 1;
    }
}
