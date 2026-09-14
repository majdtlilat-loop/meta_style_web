<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-side audit trail (docs/08-AUDIT-SECURITY.md §2-§3).
 *
 * Records what the PLATFORM does — tenant lifecycle, provisioning, migrations,
 * security rejections — including actions taken with no tenant bound. The
 * tenant-side `audit_logs` table records what happens inside a center.
 *
 * Append-only: the model refuses updates and deletes, and in production the
 * application database user is granted INSERT and SELECT only.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('platform_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // The action's time, not the row's.
            $table->timestamp('occurred_at', 3);

            // Ties every entry from one request, command or job together.
            $table->uuid('correlation_id')->nullable()->index();

            $table->string('actor_type', 32);
            $table->string('actor_id')->nullable();
            // Denormalised on purpose: an audit entry must stay readable after
            // the actor is renamed or deleted. Joining live data at read time
            // rewrites history.
            $table->string('actor_label')->nullable();
            $table->string('source', 32);

            $table->uuid('tenant_id')->nullable()->index();

            $table->string('action')->index();
            $table->string('category', 32)->index();
            $table->string('severity', 16);

            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('target_label')->nullable();

            // Changed attributes only, redacted. Never whole rows.
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('meta')->nullable();

            $table->text('reason')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('session_id')->nullable();

            $table->index(['occurred_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('platform_audit_logs');
    }
};
