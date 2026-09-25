<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A customer's loyalty account — the row every points movement locks.
 *
 * `balance` and `lifetime_points` are maintained ONLY by `LoyaltyLedger`, in
 * the same transaction as the history row that changes them, under this row's
 * `FOR UPDATE` lock. They are a cache of `loyalty_transactions`, which is the
 * truth; a test proves they agree (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §4).
 *
 *   balance             points held now. Never negative.
 *   lifetime_points     qualifying points: earned, minus the FULL earning a
 *                       refund reversed (recovered or not) — what tiers are
 *                       measured on.
 *   unrecovered_points  refund reversals the balance could not cover yet. The
 *                       next earnings settle it first, with explicit `recovery`
 *                       rows, before anything becomes available (§8).
 *
 * One per customer, per center. No `tenant_id`: the database is the center.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_id')->unique()->constrained('customers')->restrictOnDelete();

            $table->unsignedInteger('balance')->default(0);
            $table->unsignedInteger('lifetime_points')->default(0);
            $table->unsignedInteger('unrecovered_points')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_accounts');
    }
};
