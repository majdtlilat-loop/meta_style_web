<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a plan gives: a discount on a service — or on every service — with an
 * optional limit on how many times per membership term.
 *
 *   service_id      NULL: every service
 *   discount_type   percent (`basis_points` of the line) · fixed
 *                   (`amount_minor` per unit, never more than the line)
 *   uses_per_term   NULL: unlimited. "100% on blow-dries, 4 per term" is an
 *                   included service.
 *
 * Deliberately not a promotion engine: no weekdays, codes, carts or campaigns
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_plan_benefits', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('membership_plan_id')->constrained('membership_plans')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('services')->restrictOnDelete();

            $table->string('discount_type', 16);
            $table->unsignedSmallInteger('basis_points')->nullable();
            $table->unsignedBigInteger('amount_minor')->nullable();
            $table->unsignedSmallInteger('uses_per_term')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_plan_benefits');
    }
};
