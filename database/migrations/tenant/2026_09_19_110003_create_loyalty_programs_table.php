<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The center's loyalty rules — one row, deliberately small.
 *
 *   spend      `spend_points` points per `spend_unit_minor` of money actually
 *              collected on an invoice, once its net collected reaches
 *              `min_spend_minor`
 *   visit      `visit_points` per completed visit
 *   redeem     one point is worth `point_value_minor`; at least
 *              `min_redeem_points` at a time
 *   expiry     points older than `expiry_days` that nothing has used expire
 *
 * These are the CURRENT rules, for what happens next: redemption reads them,
 * and a manager edits them. Earning reads `loyalty_rule_versions` instead, so a
 * repair long afterwards still uses the rule that was in force when the money
 * was collected (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 *
 * Zero switches a rule off. Not a promotion engine: no weekdays, codes, carts
 * or campaigns (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 *
 * `singleton` is unique and always 1, so a second row cannot exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_programs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('singleton')->default(1)->unique();

            $table->unsignedInteger('spend_points')->default(0);
            $table->unsignedBigInteger('spend_unit_minor')->default(0);
            $table->unsignedBigInteger('min_spend_minor')->default(0);

            $table->unsignedInteger('visit_points')->default(0);

            $table->unsignedBigInteger('point_value_minor')->default(0);
            $table->unsignedInteger('min_redeem_points')->default(0);

            $table->unsignedSmallInteger('expiry_days')->nullable();

            $table->string('updated_by_id', 64)->nullable();
            $table->string('updated_by_label', 190)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
