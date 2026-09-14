<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy;

use App\Kernel\Tenancy\Exceptions\InvalidTenantDatabaseName;

/**
 * The only place tenant database names are produced or trusted.
 *
 * SQL cannot bind a database identifier as a parameter — `CREATE DATABASE ?`
 * does not exist — so every name reaching a DDL statement is interpolated. The
 * defence is that names are *generated*, never accepted: derived from the
 * internal auto-increment sequence, never from a center's name, a request
 * field, or anything a user can influence (ADR-024).
 *
 *     sequence 1     → tenant_000001
 *     sequence 1234  → tenant_001234
 *     sequence 10^7  → tenant_10000000   (widens past six digits, still safe)
 */
final class TenantDatabaseName
{
    public const DEFAULT_PREFIX = 'tenant_';

    /**
     * The configured prefix, validated before it can reach SQL.
     *
     * Config is not user input, but it is the one remaining way a string could
     * travel into a DDL statement, so it is checked here rather than trusted.
     */
    public static function prefix(): string
    {
        $prefix = (string) config('metastyle.tenancy.database_prefix', self::DEFAULT_PREFIX);

        if (preg_match('/^[a-z][a-z0-9_]{0,32}$/', $prefix) !== 1) {
            throw InvalidTenantDatabaseName::for($prefix);
        }

        return $prefix;
    }

    public static function forSequence(int $sequence): string
    {
        if ($sequence < 1) {
            throw new InvalidTenantDatabaseName("Tenant sequence must be positive, got [{$sequence}].");
        }

        return self::prefix().str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    public static function isValid(string $name): bool
    {
        return preg_match('/^'.preg_quote(self::prefix(), '/').'[0-9]{6,12}$/', $name) === 1;
    }

    /**
     * Gate for every DDL path. Anything that creates, drops, or connects to a
     * tenant database calls this first.
     *
     * @throws InvalidTenantDatabaseName
     */
    public static function assertValid(string $name): string
    {
        if (! self::isValid($name)) {
            throw InvalidTenantDatabaseName::for($name);
        }

        return $name;
    }
}
