<?php

declare(strict_types=1);

namespace App\Modules\Sales\Domain;

use Random\RandomException;

/**
 * The secret in a customer's invoice link.
 *
 * A bearer capability: whoever holds the URL reads the invoice. So it is treated
 * the way Meta Style already treats registration and staff activation tokens
 * (ADR-035):
 *
 *   generated   256 bits of CSPRNG output, 64 hex characters
 *   stored      as SHA-256 only — `invoice_share_links.token_hash`
 *   plaintext   exists in exactly one place: the URL handed back by the call
 *               that minted it. Never a column, never a log line, never audit.
 *
 * A copy of the database therefore opens no invoice, and a support engineer
 * reading `invoice_share_links` learns nothing they could paste into a browser.
 *
 * SHA-256 rather than a slow hash, deliberately: there is no dictionary to slow
 * down against 256 random bits, and the public lookup has to be an indexed
 * equality match on the digest (docs/18-SALES.md §20).
 */
final class InvoiceShareToken
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
    public static function hash(#[\SensitiveParameter] string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Whether a presented value could be a secret at all. Anything else is
     * answered without touching the database.
     */
    public static function isWellFormed(#[\SensitiveParameter] string $presented): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $presented) === 1;
    }
}
