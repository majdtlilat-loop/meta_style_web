<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PDO;

/**
 * Creates and drops the real databases the test suite needs.
 *
 * Meta Style is a database-per-tenant system, so proving isolation requires
 * more than one real database. SQLite cannot stand in for this
 * (docs/DECISIONS.md ADR-016).
 *
 * Everything this class touches must be prefixed, and the prefix guard is not
 * optional: a `DROP DATABASE` with a mistyped name in a developer's local
 * MySQL is an unrecoverable accident.
 */
final class TestDatabaseManager
{
    public const PREFIX = 'meta_style_test_';

    /**
     * Databases owned by a NAMESPACED run start with this. A run started with
     * METASTYLE_TEST_DB_NAMESPACE=x owns only `meta_style_test_ns_x_*` (its
     * control database and tenant prefix must be named inside it), and a
     * default run never touches any namespaced database. Two runs therefore
     * never drop each other's databases — isolation, not a shared server.
     */
    public const NAMESPACED = self::PREFIX.'ns_';

    /** The prefix every database of THIS run must carry. */
    public static function prefix(): string
    {
        $namespace = getenv('METASTYLE_TEST_DB_NAMESPACE');

        if (is_string($namespace) && $namespace !== '') {
            if (preg_match('/^[a-z][a-z0-9]{0,11}$/D', $namespace) !== 1) {
                throw new InvalidArgumentException('METASTYLE_TEST_DB_NAMESPACE must be 1-12 lowercase letters or digits.');
            }

            return self::NAMESPACED.$namespace.'_';
        }

        return self::PREFIX;
    }

    private static ?PDO $server = null;

    /**
     * Connects to the database server itself, with no database selected.
     */
    public static function server(): PDO
    {
        if (self::$server instanceof PDO) {
            return self::$server;
        }

        /** @var array<string, mixed> $config */
        $config = config('database.connections.control');

        $dsn = sprintf(
            '%s:host=%s;port=%s',
            $config['driver'] === 'mariadb' ? 'mysql' : $config['driver'],
            $config['host'],
            $config['port'],
        );

        return self::$server = new PDO(
            $dsn,
            (string) $config['username'],
            (string) $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
    }

    public static function create(string $database): string
    {
        self::guard($database);

        self::server()->exec(
            "CREATE DATABASE IF NOT EXISTS `{$database}` "
            .'CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
        );

        return $database;
    }

    public static function drop(string $database): void
    {
        self::guard($database);

        self::server()->exec("DROP DATABASE IF EXISTS `{$database}`");
    }

    /**
     * Removes every database this suite owns.
     *
     * Provisioned tenants get their names from the tenant sequence, which
     * keeps climbing across runs, so a per-test list can miss one if a test
     * dies mid-provision. This sweeps whatever the prefix matches.
     */
    public static function dropAll(): void
    {
        foreach (self::existing() as $database) {
            if ($database !== (string) config('database.connections.control.database')) {
                self::drop($database);
            }
        }
    }

    /**
     * Ensures the control database used by the suite exists.
     */
    public static function ensureControlDatabase(): void
    {
        $database = (string) config('database.connections.control.database');

        self::create($database);
    }

    /**
     * Drops and recreates the suite's control database, so each run starts
     * from a known-empty schema regardless of how the previous run ended.
     */
    public static function recreateControlDatabase(): void
    {
        $database = (string) config('database.connections.control.database');

        self::drop($database);
        self::create($database);

        // Laravel may already hold a connection to the database that was just
        // dropped.
        DB::purge('control');
    }

    /**
     * @return list<string>
     */
    public static function existing(): array
    {
        $prefix = self::prefix();
        $statement = self::server()->query(
            "SHOW DATABASES LIKE '".str_replace('_', '\_', $prefix)."%'"
        );

        /** @var list<string> $names */
        $names = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);

        // A default run owns everything under the base prefix EXCEPT the
        // namespaced runs' databases.
        return array_values(array_filter(
            $names,
            static fn (string $name): bool => $prefix !== self::PREFIX || ! str_starts_with($name, self::NAMESPACED),
        ));
    }

    /**
     * Only ever touches databases this suite owns.
     */
    private static function guard(string $database): void
    {
        $prefix = self::prefix();
        if (! str_starts_with($database, $prefix)
            || ($prefix === self::PREFIX && str_starts_with($database, self::NAMESPACED))) {
            throw new InvalidArgumentException(
                "Refusing to manage database [{$database}]: this run's test databases must be "
                .'prefixed with "'.$prefix.'".'
            );
        }

        // `D`: `$` would otherwise also match before a final newline.
        if (preg_match('/^[A-Za-z0-9_]+$/D', $database) !== 1) {
            throw new InvalidArgumentException(
                "Refusing to manage database [{$database}]: unsafe name."
            );
        }
    }
}
