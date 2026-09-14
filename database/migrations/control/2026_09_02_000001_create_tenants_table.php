<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant registry — the heart of the control plane.
 *
 * Holds identity, lifecycle state, and where the tenant's operational database
 * lives. It holds NO tenant business data: that is the entire point of
 * database-per-tenant (docs/02-TENANCY.md §1).
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('tenants', function (Blueprint $table): void {
            // Public, stable identity. Used for storage prefixes, cache tags,
            // log context and (later) API exposure. Never the sequence, which
            // would leak signup volume and order.
            $table->uuid('id')->primary();

            // Internal, monotonic. Its only job is producing a safe, unique
            // database name (ADR-024). Made AUTO_INCREMENT below.
            $table->unsignedBigInteger('sequence')->unique();

            $table->string('name');

            $table->string('status', 32)->index();
            $table->string('provisioning_status', 32)->index();

            // `tenancy_db_name` is the tenancy package's contract for the
            // tenant database name (it reads the `tenancy_` prefixed
            // attribute). Keeping the package's name on the column avoids a
            // second source of truth; application code reads it through the
            // Tenant value object as `databaseName`.
            $table->string('tenancy_db_name', 64)->nullable()->unique();

            // Present from day one so tenants can be sharded across database
            // instances later without a schema change (ADR-004). Null means
            // "the default host".
            $table->string('db_host')->nullable();

            // Holds a full tenant migration filename, which is long. 191 is
            // the utf8mb4 index-safe length and leaves plenty of room.
            $table->string('schema_version', 191)->nullable();
            $table->string('migration_status', 32)->default('pending')->index();
            $table->timestamp('last_migration_at')->nullable();
            $table->text('last_migration_error')->nullable();

            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->timestamp('archived_at')->nullable();

            // Required by the tenancy package's virtual-column support:
            // any attribute that is not a real column above lands here.
            $table->json('data')->nullable();

            $table->timestamps();
        });

        // The sequence must be assigned by the database, not by the
        // application: two concurrent provisions computing MAX(sequence)+1
        // would collide on the database name. AUTO_INCREMENT on a UNIQUE
        // column is valid in both MySQL 8 and MariaDB 10.4.
        DB::connection($this->connection)->statement(
            'ALTER TABLE `tenants` MODIFY `sequence` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT'
        );
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('tenants');
    }
};
