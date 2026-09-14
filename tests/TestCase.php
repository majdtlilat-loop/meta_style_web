<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestDatabaseManager;

/**
 * Base test case.
 *
 * `RefreshDatabase` is not used: it assumes a single database, and Meta Style
 * has a control database plus one database per tenant, created during the test
 * itself. Instead the control schema is built once per run and its tables are
 * truncated between tests, while tenant databases are created and dropped by
 * the tests that need them (docs/11-TESTING-STRATEGY.md §3).
 */
abstract class TestCase extends BaseTestCase
{
    private static bool $suitePrepared = false;

    /**
     * Truncated between tests. Ordered so a truncate never fights a foreign
     * key, though constraints are disabled during the sweep anyway.
     *
     * @var list<string>
     */
    private const CONTROL_TABLES = [
        'platform_audit_logs',
        'tenant_operations',
        'registrations',
        'subscriptions',
        'tenant_entitlement_overrides',
        'domains',
        'tenants',
        'jobs',
        'failed_jobs',
    ];

    /**
     * Reference data, restored after each truncate rather than wiped.
     *
     * Plans and platform settings are the control plane's seeded catalog, not
     * per-test state: registration cannot run without a default plan, and
     * re-seeding them for every test would be slow and pointless.
     *
     * @var list<string>
     */
    private const CONTROL_REFERENCE_TABLES = [
        'plan_entitlements',
        'plans',
        'platform_settings',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$suitePrepared) {
            $this->prepareSuite();
            self::$suitePrepared = true;
        }

        $this->resetControlPlane();
    }

    /**
     * Builds the control schema from scratch and clears anything a previous,
     * possibly crashed, run left behind.
     *
     * The control database is recreated rather than migrated in place. A run
     * that dies part-way can leave tables present but the migration history
     * gone, after which every later run fails on "table already exists" — a
     * confusing failure that has nothing to do with the code under test.
     */
    private function prepareSuite(): void
    {
        TestDatabaseManager::recreateControlDatabase();

        // A run that died mid-provision also leaves orphan tenant databases.
        // They all carry the test prefix, so they can be swept safely.
        TestDatabaseManager::dropAll();

        $this->artisan('metastyle:control:migrate', ['--force' => true])->run();
    }

    /**
     * Seeds plans and platform settings if they are missing.
     */
    private function seedReferenceData(): void
    {
        if (DB::connection('control')->table('plans')->exists()) {
            return;
        }

        $this->artisan('db:seed', ['--force' => true])->run();
    }

    private function resetControlPlane(): void
    {
        $schema = Schema::connection('control');

        $schema->disableForeignKeyConstraints();

        foreach (self::CONTROL_TABLES as $table) {
            if ($schema->hasTable($table)) {
                DB::connection('control')->table($table)->truncate();
            }
        }

        $schema->enableForeignKeyConstraints();

        $this->seedReferenceData();
    }
}
