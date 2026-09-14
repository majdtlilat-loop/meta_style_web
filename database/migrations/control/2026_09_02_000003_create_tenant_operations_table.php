<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A log of what the platform did TO a tenant.
 *
 * Provisioning and migrations are long-running and fail in ways worth keeping:
 * this is what makes a failure observable and a retry informed. It is a log,
 * not a workflow engine — there is no state machine, no steps table, and no
 * orchestration here (docs/03-DATABASE-MIGRATIONS.md §5).
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('tenant_operations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('tenant_id');

            $table->string('type', 32);
            $table->string('status', 32);
            $table->unsignedSmallInteger('attempt')->default(1);

            // Sanitised message only. Raw exception output can contain
            // credentials and connection strings (docs/08-AUDIT-SECURITY.md §17).
            $table->text('error')->nullable();

            $table->uuid('correlation_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type', 'status']);

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('tenant_operations');
    }
};
