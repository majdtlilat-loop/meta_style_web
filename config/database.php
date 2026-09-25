<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Meta Style has two data planes (docs/01-ARCHITECTURE.md §2):
    |
    |   control  the platform database (meta_style_control)
    |   tenant   one operational database per tenant, bound at runtime
    |
    | The default is "control". A tenant database is NEVER the default and is
    | never statically assigned — see the "tenant" connection below.
    |
    */

    'default' => env('DB_CONNECTION', 'control'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Only MySQL-compatible connections are defined. SQLite is deliberately
    | absent: this system depends on MySQL JSON functions, generated columns,
    | collation and foreign key semantics (docs/11-TESTING-STRATEGY.md §2).
    |
    */

    'connections' => [

        /*
         * Control plane — platform metadata, plans, subscriptions, SaaS
         * billing, platform users, tenant registry.
         */
        'control' => [
            'driver' => env('DB_CONTROL_DRIVER', 'mysql'),
            'url' => env('DB_CONTROL_URL'),
            'host' => env('DB_CONTROL_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_CONTROL_PORT', env('DB_PORT', '3306')),
            'database' => env('DB_CONTROL_DATABASE', 'meta_style_control'),
            'username' => env('DB_CONTROL_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_CONTROL_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * Tenant plane — a TEMPLATE, never a usable connection.
         *
         * There is deliberately NO connection named "tenant" in this file.
         * The tenancy layer creates `database.connections.tenant` when a
         * tenant is initialised and DELETES it when tenancy ends, so while no
         * tenant is bound the connection does not exist at all and any attempt
         * to use it fails immediately. That is the strongest form of the
         * fail-closed guarantee in docs/02-TENANCY.md §4:
         *
         *   1. No `tenant` connection exists unless a tenant is initialised.
         *   2. TenantConnectionGuard turns the failure into a named exception.
         *   3. Tenant models resolve through the guard rather than the default
         *      connection.
         *
         * This template supplies driver, host, credentials, charset, collation
         * and strict mode. It has no database name and must never be given
         * one: it is copied, not connected to. Naming it `tenant` would break
         * tenancy outright, since the package destroys that name on revert.
         */
        'tenant_template' => [
            'driver' => env('DB_TENANT_DRIVER', 'mysql'),
            'host' => env('DB_TENANT_HOST', env('DB_HOST', '127.0.0.1')),
            'port' => env('DB_TENANT_PORT', env('DB_PORT', '3306')),
            'database' => null,
            'username' => env('DB_TENANT_USERNAME', env('DB_USERNAME', 'root')),
            'password' => env('DB_TENANT_PASSWORD', env('DB_PASSWORD', '')),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        /*
         * Read-only reporting endpoint template. Its database name is bound
         * lazily from the current tenant by Kernel\Reporting\ReportConnection.
         * Empty credentials are valid while Advanced Reports are not enabled;
         * Primary/Standard reads never inspect this connection.
         */
        'reporting_template' => [
            'driver' => env('DB_REPORTING_DRIVER', 'mysql'),
            'host' => env('DB_REPORTING_HOST'),
            'port' => env('DB_REPORTING_PORT', '3306'),
            'database' => null,
            'username' => env('DB_REPORTING_USERNAME'),
            'password' => env('DB_REPORTING_PASSWORD'),
            'unix_socket' => env('DB_REPORTING_SOCKET', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => 'InnoDB',
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                (PHP_VERSION_ID >= 80500 ? Mysql::ATTR_SSL_CA : PDO::MYSQL_ATTR_SSL_CA) => env('DB_REPORTING_SSL_CA'),
            ]) : [],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | Each database — control and every tenant — keeps its own migration
    | history in this table. See docs/03-DATABASE-MIGRATIONS.md.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Configured but not required. Meta Style runs on file/database drivers
    | until Phase 2, where tenant cache isolation and the provisioning queue
    | make Redis worthwhile (docs/01-ARCHITECTURE.md §8).
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'meta-style')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],

    ],

];
