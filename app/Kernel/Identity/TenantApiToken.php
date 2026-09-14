<?php

declare(strict_types=1);

namespace App\Kernel\Identity;

/**
 * The wire format for tenant-bound API tokens.
 *
 *     ctr_a1b2c3…|17|k9Xs…
 *     └─ tenant public key ─┘└─ Sanctum token ─┘
 *
 * The prefix exists so a token can act as a TRUSTED TENANT SIGNAL before any
 * database lookup. Without it, presenting Tenant B's token against Tenant A's
 * host would simply fail to find the token and return a bare 401 — correct, but
 * indistinguishable from a typo. With it, the mismatch is detectable, so it can
 * be refused as a security event and audited (docs/02-TENANCY.md §2.2).
 *
 * The prefix is not a credential and is not trusted on its own: it is resolved
 * server-side against the control plane, and the Sanctum half still has to
 * match a row in that tenant's database.
 */
final class TenantApiToken
{
    public const SEPARATOR = '|';

    public static function format(string $publicKey, string $sanctumToken): string
    {
        return $publicKey.self::SEPARATOR.$sanctumToken;
    }

    /**
     * Splits a bearer value into its tenant key and Sanctum halves.
     *
     * Returns null when the value is not in Meta Style's format — including a
     * bare Sanctum token, which must NOT be treated as a tenant signal.
     *
     * @return array{key: string, token: string}|null
     */
    public static function parse(?string $bearer): ?array
    {
        if ($bearer === null || $bearer === '') {
            return null;
        }

        $parts = explode(self::SEPARATOR, $bearer);

        // A Meta Style token has three segments: the public key, Sanctum's
        // token id, and Sanctum's plaintext. Anything else is not ours.
        if (count($parts) !== 3) {
            return null;
        }

        [$key, $id, $plain] = $parts;

        if (! self::looksLikePublicKey($key) || $id === '' || $plain === '') {
            return null;
        }

        return ['key' => $key, 'token' => $id.self::SEPARATOR.$plain];
    }

    public static function looksLikePublicKey(string $value): bool
    {
        return preg_match('/^ctr_[a-z0-9]{16,64}$/', $value) === 1;
    }

    /**
     * Reads the bearer value from a raw Authorization header.
     */
    public static function fromAuthorizationHeader(?string $header): ?string
    {
        if ($header === null || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $value = trim(substr($header, 7));

        return $value === '' ? null : $value;
    }
}
