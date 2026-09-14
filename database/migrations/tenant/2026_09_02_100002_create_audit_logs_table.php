<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-side audit trail (docs/08-AUDIT-SECURITY.md §2-§3).
 *
 * Everything done inside a center, by anyone — including Meta Style support
 * acting through impersonation, which is why this lives in the tenant's own
 * database where the center can see it.
 *
 * Runs on the connection the migrator binds, so no connection is named here:
 * this file is applied identically to every tenant database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->timestamp('occurred_at', 3);
            $table->uuid('correlation_id')->nullable()->index();

            $table->string('actor_type', 32);
            $table->string('actor_id')->nullable();
            $table->string('actor_label')->nullable();
            $table->string('source', 32);

            $table->string('action')->index();
            $table->string('category', 32)->index();
            $table->string('severity', 16);

            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->string('target_label')->nullable();

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
        Schema::dropIfExists('audit_logs');
    }
};
