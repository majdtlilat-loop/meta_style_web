<?php

use App\Kernel\Tenancy\Infrastructure\DomainModel;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use App\Kernel\Tenancy\Infrastructure\TenantStorageBootstrapper;
use Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\TenantDatabaseManagers\MySQLDatabaseManager;
use Stancl\Tenancy\UUIDGenerator;

/*
|--------------------------------------------------------------------------
| Tenancy (stancl/tenancy v3)
|--------------------------------------------------------------------------
|
| Infrastructure configuration only. Meta Style's tenancy *policy* — how a
| tenant is resolved, how databases are named, how provisioning and migrations
| behave — lives in App\Kernel\Tenancy, not here (ADR-018).
|
| This file is a trimmed version of the package's published config: options we
| do not use have been removed rather than left commented out, so what remains
| is what is actually in force.
|
*/

return [

    'tenant_model' => TenantModel::class,
    'domain_model' => DomainModel::class,

    // Public tenant identity is a UUID. The internal auto-increment `sequence`
    // column is what produces the database name, and is never exposed
    // (ADR-024).
    'id_generator' => UUIDGenerator::class,

    /*
     * Hosts that belong to Meta Style itself rather than to a tenant.
     */
    // Filled from APP_URL through PlatformHosts in TenancyServiceProvider so
    // tenancy and HTTP routing share one host authority.
    'central_domains' => [],

    /*
     * Executed when tenancy is initialised, and reverted when it ends.
     * Redis bootstrapping is not enabled: Redis is optional in Phase 2
     * (ADR-025).
     */
    'bootstrappers' => [
        DatabaseTenancyBootstrapper::class,
        CacheTenancyBootstrapper::class,
        FilesystemTenancyBootstrapper::class,
        // After the filesystem one: gives the suffixed storage path the
        // directory Laravel's real-time facades are written to.
        TenantStorageBootstrapper::class,
        QueueTenancyBootstrapper::class,
    ],

    'database' => [
        'central_connection' => 'control',

        /*
         * The connection whose driver, host, charset, collation and strict
         * mode are copied when building a tenant's connection.
         *
         * It must NOT be called "tenant": the package creates and destroys a
         * connection by that name on every tenancy cycle, so a template named
         * `tenant` would be deleted the first time tenancy ended. Hence
         * `tenant_template`, which is a template and is never connected to.
         */
        'template_tenant_connection' => 'tenant_template',

        /*
         * Fallback name generator. In practice it is never used: Meta Style
         * always assigns `tenancy_db_name` explicitly (TenantDatabaseName, from
         * the slug and the tenant sequence) before the database is created.
         */
        'prefix' => 'tenant_',
        'suffix' => '',

        'managers' => [
            'mysql' => MySQLDatabaseManager::class,
            'mariadb' => MySQLDatabaseManager::class,
        ],
    ],

    /*
     * Cache isolation works by tagging every entry with the tenant.
     *
     * This requires a taggable cache store — array, redis or memcached. The
     * `file` and `database` stores do NOT support tags and will throw, which
     * is the correct behaviour: a silently un-tagged cache would serve one
     * tenant's data to another. See ADR-025.
     */
    'cache' => [
        'tag_base' => 'tenant',
    ],

    /*
     * Filesystem isolation. Disk roots and storage_path() are suffixed with
     * the tenant key, producing `tenants/{uuid}/...` to match the layout in
     * docs/09-STORAGE.md §2.
     */
    'filesystem' => [
        'suffix_base' => 'tenants/',
        'disks' => [
            'local',
            'public',
        ],
        'root_override' => [
            'local' => '%storage_path%/app/',
            'public' => '%storage_path%/app/public/',
        ],
        'suffix_storage_path' => true,
        'asset_helper_tenancy' => false,
    ],

    /*
     * Tenant migrations are a separate set from control-plane migrations and
     * are applied identically to every tenant database
     * (docs/03-DATABASE-MIGRATIONS.md §1).
     */
    'migration_parameters' => [
        '--force' => true,
        '--path' => [database_path('migrations/tenant')],
        '--realpath' => true,
    ],

    'seeder_parameters' => [],

];
