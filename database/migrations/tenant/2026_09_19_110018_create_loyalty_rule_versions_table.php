<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every earning rule the center has ever had, append-only, with the instant it
 * took effect.
 *
 * A qualifying event earns under the rule that was effective WHEN IT HAPPENED,
 * not the rule that happens to be current when the earning is written. Those
 * differ whenever an after-commit earning failed and reconciliation repairs it
 * after a manager changed the rules — the repair must reproduce the result the
 * customer should have had (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 *
 * Only the EARNING rules are versioned. What a point is worth and the
 * redemption minimum apply when points are spent, so the current program row
 * answers those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_rule_versions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * The rule effective at an instant:
             *
             *   SELECT * FROM loyalty_rule_versions
             *   WHERE effective_from <= ? ORDER BY effective_from DESC, id DESC LIMIT 1
             */
            $table->dateTime('effective_from')->index();

            $table->unsignedInteger('spend_points');
            $table->unsignedBigInteger('spend_unit_minor');
            $table->unsignedBigInteger('min_spend_minor');
            $table->unsignedInteger('visit_points');
            $table->unsignedSmallInteger('expiry_days')->nullable();

            $table->string('changed_by_id', 64)->nullable();
            $table->string('changed_by_label', 190)->nullable();

            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_rule_versions');
    }
};
