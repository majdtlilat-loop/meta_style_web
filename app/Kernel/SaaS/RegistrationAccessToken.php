<?php

declare(strict_types=1);

namespace App\Kernel\SaaS;

use Random\RandomException;

/**
 * The secret half of a registration's identity.
 *
 * A registration has two identifiers and they do different jobs (ADR-035):
 *
 *   uuid   public LOCATOR   — appears in a redirect URL, browser history, a
 *                             support ticket, a screenshot. Not a secret.
 *   token  secret CAPABILITY — never stored in plaintext, never in a URL,
 *                             returned to the registering client exactly once.
 *
 * Phase 3 conflated the two, which made the uuid alone sufficient to read a
 * registration's state and to trigger provisioning work.
 *
 * SHA-256 rather than bcrypt, deliberately — and in the same codebase where
 * `owner_password_hash` uses bcrypt. The difference is what is being hashed. A
 * password is low-entropy and human-chosen, so it needs a slow hash to survive
 * an offline attack on the digest. This is 256 bits of CSPRNG output: there is
 * nothing to guess, and a fast digest is what lets the check be a constant-time
 * comparison on a public endpoint that is polled every two seconds. The same
 * reasoning Sanctum applies to its own tokens.
 */
final class RegistrationAccessToken
{
    /** The header a client presents it in. Never a query parameter. */
    public const HEADER = 'X-Registration-Token';

    /** Bytes of randomness. 32 bytes = 256 bits = 64 hex characters. */
    private const BYTES = 32;

    /**
     * A new plaintext token.
     *
     * @throws RandomException when the platform has no usable CSPRNG, which is
     *                         a condition to fail on rather than fall back from
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(self::BYTES));
    }

    /**
     * The value that is safe to store.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * Constant-time comparison of a presented token against a stored digest.
     *
     * `hash_equals` rather than `===` so the comparison cannot be timed. It
     * matters less here than for a password — the attacker would need to time
     * their way through 64 hex characters over the network — but the correct
     * function costs nothing and removes the question.
     */
    public static function matches(?string $presented, ?string $storedHash): bool
    {
        if (! is_string($presented) || $presented === '' || ! is_string($storedHash) || $storedHash === '') {
            return false;
        }

        return hash_equals($storedHash, self::hash($presented));
    }
}
