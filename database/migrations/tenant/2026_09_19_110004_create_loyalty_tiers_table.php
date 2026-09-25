<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Loyalty tiers: a name, a threshold, a note of what it means.
 *
 * A customer's tier is DERIVED from their lifetime points against these
 * thresholds, never stored on the customer or set by hand
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §9). No multiplier and no campaign
 * rules. Archived, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_tiers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Translatable JSON; no default on JSON (ADR-033).
            $table->json('name');
            $table->json('benefit_note')->nullable();

            $table->unsignedInteger('threshold_points');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->dateTime('archived_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_tiers');
    }
};
