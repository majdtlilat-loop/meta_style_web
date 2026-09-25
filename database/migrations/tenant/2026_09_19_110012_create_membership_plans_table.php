<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Membership plans a center sells: "Gold — 20% off every haircut for 30 days".
 *
 * A plan is what is OFFERED; a customer's membership is a snapshot of it at the
 * moment of sale, so editing a plan never changes what somebody paid for
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §10).
 *
 *   duration_days   whole branch-local calendar days, counting the day it
 *                   starts. Days, not months: see the document.
 *
 * Archived, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plans', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // Translatable JSON; no default on JSON (ADR-033).
            $table->json('name');

            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('duration_days');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->dateTime('archived_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plans');
    }
};
