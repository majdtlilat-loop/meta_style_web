<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue backing store, in the CONTROL database.
 *
 * Job payloads carry a tenant identifier and record ids — never tenant data
 * (docs/02-TENANCY.md §6) — so one shared queue table creates no isolation
 * problem, and gives operators one place to look.
 *
 * This exists so the queue works, and the tenant-context-in-jobs guarantee can
 * be genuinely tested, without requiring Redis locally (ADR-025). Redis remains
 * the production driver.
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('jobs');
    }
};
