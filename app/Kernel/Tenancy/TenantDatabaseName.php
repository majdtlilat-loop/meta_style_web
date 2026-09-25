<?php

declare(strict_types=1);

namespace App\Kernel\Tenancy;

use App\Kernel\Tenancy\Exceptions\InvalidTenantDatabaseName;

/**
 * The only place tenant database names are produced or trusted.
 *
 * SQL cannot bind a database identifier as a parameter — `CREATE DATABASE ?`
 * does not exist — so every name reaching a DDL statement is interpolated. The
 * defence is that names are *generated*, never accepted (ADR-024), and that a
 * name reads to an operator as the center it belongs to (ADR-106):
 *
 *     slug "drbany",           sequence 3    → tenant_drbany_000003
 *     slug "barbershop-alpha", sequence 4    → tenant_barbershop_alpha_000004
 *     slug "qa-test-center",   sequence 5    → tenant_qa_test_center_000005
 *
 * The LABEL is the center's slug reduced to a closed alphabet — lowercase
 * ASCII letters, digits and single underscores — and bounded, so whatever the
 * slug carried (a quote, a dot, a slash, a wildcard, Arabic) cannot reach SQL.
 * The SUFFIX is the internal auto-increment sequence, which alone makes the
 * name unique: two centers whose slugs reduce to the same label still get two
 * databases. The label is cut to fit, the suffix never is.
 *
 * A name is generated ONCE, at provisioning, and stored on the control-plane
 * tenant row; everything after reads the stored name. A center that is later
 * renamed, or moved to another address, keeps its database — nothing here is
 * ever re-derived from the center's current slug.
 *
 * Databases provisioned before ADR-106 are named `tenant_000003`, with no
 * label, and remain valid.
 */
final class TenantDatabaseName
{
    public const DEFAULT_PREFIX = 'tenant_';

    /** MySQL's and MariaDB's limit for a database identifier. */
    public const MAX_LENGTH = 64;

    /** The longest label a name may carry, whatever the slug. */
    public const MAX_SLUG_LENGTH = 24;

    /** The label of a center whose slug has nothing ASCII left in it. */
    public const FALLBACK_LABEL = 'center';

    /** Twelve digits: the widest suffix the identifier limit leaves room for. */
    private const MAX_SEQUENCE = 999_999_999_999;

    /**
     * The configured prefix, validated before it can reach SQL.
     *
     * Config is not user input, but it is the one remaining way a string could
     * travel into a DDL statement, so it is checked here rather than trusted.
     */
    public static function prefix(): string
    {
        $prefix = (string) config('metastyle.tenancy.database_prefix', self::DEFAULT_PREFIX);

        // `D`: without it `$` also matches before a final newline, and
        // "tenant_\n" would pass.
        if (preg_match('/^[a-z][a-z0-9_]{0,32}$/D', $prefix) !== 1) {
            throw InvalidTenantDatabaseName::for($prefix);
        }

        return $prefix;
    }

    /**
     * The database name for a NEW center: its slug as a label, its sequence as
     * the suffix that makes it unique.
     *
     * The slug is untrusted even after the platform normalised it: this is
     * the last step before an identifier.
     */
    public static function generate(int $sequence, ?string $slug): string
    {
        if ($sequence < 1 || $sequence > self::MAX_SEQUENCE) {
            throw new InvalidTenantDatabaseName("Tenant sequence out of range, got [{$sequence}].");
        }

        $prefix = self::prefix();
        $suffix = str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

        // The label gives way to the prefix and the suffix, never the reverse.
        // Under the production prefix it is MAX_SLUG_LENGTH; only a long
        // configured prefix (the test suite's) shortens it further.
        $budget = min(self::MAX_SLUG_LENGTH, self::MAX_LENGTH - strlen($prefix) - 1 - strlen($suffix));

        return self::assertValid($prefix.self::label($slug, $budget).'_'.$suffix);
    }

    public static function isValid(string $name): bool
    {
        if (strlen($name) > self::MAX_LENGTH) {
            return false;
        }

        // `tenant_{label}_{sequence}`, or the pre-ADR-106 `tenant_{sequence}`.
        // `D` ends the match at the true end: "tenant_000001\n" is not a name.
        $pattern = '/^'.preg_quote(self::prefix(), '/').'(?:(?<label>[a-z0-9]+(?:_[a-z0-9]+)*)_)?[0-9]{6,12}$/D';

        if (preg_match($pattern, $name, $match) !== 1) {
            return false;
        }

        return strlen($match['label'] ?? '') <= self::MAX_SLUG_LENGTH;
    }

    /**
     * Gate for every DDL path: anything that creates, drops, migrates or
     * reports on a tenant database calls this first. Runtime connection
     * switching binds the STORED name, which only provisioning writes
     * (ADR-106).
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

    /**
     * The slug reduced to `[a-z0-9]` runs joined by single underscores, cut to
     * the budget, and never empty. Byte-wise on purpose: nothing outside ASCII
     * survives, so no identifier ever depends on how a server treats Unicode.
     */
    private static function label(?string $slug, int $budget): string
    {
        $label = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower((string) $slug)), '_');
        $label = rtrim(substr($label, 0, $budget), '_');

        return $label === '' ? self::FALLBACK_LABEL : $label;
    }
}
