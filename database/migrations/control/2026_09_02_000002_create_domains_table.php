<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Host-based tenant resolution.
 *
 * The minimum needed for secure resolution and nothing more: no verification
 * workflow, no SSL provisioning, no DNS automation. Those are real features
 * with real cost and none of them are needed to resolve a tenant
 * (docs/02-TENANCY.md §2.2).
 */
return new class extends Migration
{
    protected $connection = 'control';

    public function up(): void
    {
        Schema::connection($this->connection)->create('domains', function (Blueprint $table): void {
            $table->id();

            // The lookup key for every host-resolved request. Unique across
            // the platform: one host can only ever mean one tenant.
            $table->string('domain')->unique();

            $table->uuid('tenant_id');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->foreign('tenant_id')
                ->references('id')->on('tenants')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('domains');
    }
};
