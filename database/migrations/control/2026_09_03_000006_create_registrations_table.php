<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A self-registration request, and the record that makes provisioning
 * observable while it runs.
 *
 * Provisioning creates a database and runs migrations, which is far too slow
 * and too failure-prone for a web request. The request creates this row and
 * queues the work; the client polls this row for `preparing → ready | failed`.
 *
 * SECRET HANDLING (docs/DECISIONS.md ADR-028)
 *
 * `owner_password_hash` holds a bcrypt hash, encrypted at rest with APP_KEY,
 * and is NULLED the moment provisioning finishes — success or failure. The
 * queue payload carries only this row's uuid, so no credential material ever
 * reaches `jobs`, `failed_jobs`, `tenant_operations`, audit entries or logs.
 * Plaintext is never persisted anywhere at any point.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('registrations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Makes a duplicate submit — or a client retry — return the first
            // registration instead of creating a second tenant and database.
            $table->string('idempotency_key', 128)->unique();

            $table->string('status', 32)->index();

            $table->string('center_name');
            $table->string('owner_name');
            $table->string('owner_email')->nullable();
            $table->string('owner_phone', 20)->nullable();
            $table->string('locale', 12)->default('en');
            $table->char('country', 2)->nullable();

            // Encrypted bcrypt hash, cleared on completion. See the note above.
            $table->text('owner_password_hash')->nullable();

            $table->uuid('tenant_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('registrations');
    }
};
