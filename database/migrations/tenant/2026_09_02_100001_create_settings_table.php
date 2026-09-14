<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-local key/value settings.
 *
 * The minimum tenant-side table Phase 2 genuinely needs: provisioning seeds
 * the tenant's own identity marker here, which proves the tenant database was
 * created, migrated, seeded and is readable through tenant context.
 *
 * This is infrastructure, not a business table. Branches, employees, services,
 * customers and everything else belong to later phases and are NOT stubbed
 * here (docs/13-ROADMAP.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
