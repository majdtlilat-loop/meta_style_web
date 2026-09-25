<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every package movement, append-only — the truth "sessions left" is proven
 * from. There is no `remaining_sessions` column anywhere
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §14).
 *
 *   allocation    what activation gave, per item
 *   redemption    a performed service covered at checkout
 *   reversal      a redemption given back: withdrawn, discarded, voided
 *   cancellation  what was left, forfeited when the package was cancelled
 *
 *   left(item) = Σ allocation − Σ redemption + Σ reversal − Σ cancellation
 *
 * `unique(source_type, source_uuid, kind)`: every movement once — one
 * allocation per item, one redemption per benefit, one reversal of it.
 * `journey_stage_id` records the performed stage a redemption covered, when
 * there was one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_package_id')->constrained('customer_packages')->restrictOnDelete();
            $table->foreignId('customer_package_item_id')->constrained('customer_package_items')->restrictOnDelete();

            $table->string('kind', 16);
            $table->unsignedSmallInteger('quantity');

            $table->string('source_type', 16);
            $table->uuid('source_uuid');
            $table->uuid('sale_uuid')->nullable();
            $table->foreignId('journey_stage_id')->nullable()->constrained('journey_stages')->nullOnDelete();

            $table->string('reason', 190)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 190)->nullable();

            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            $table->unique(['source_type', 'source_uuid', 'kind']);

            /*
             * Sessions left, per item of one package:
             *
             *   SELECT customer_package_item_id, kind, SUM(quantity)
             *   FROM package_transactions WHERE customer_package_id = ?
             *   GROUP BY customer_package_item_id, kind
             */
            $table->index(['customer_package_id', 'kind']);

            /*
             * What a sale redeemed, when it is voided or its draft discarded:
             *
             *   SELECT source_uuid FROM package_transactions
             *   WHERE sale_uuid = ? AND source_type = 'benefit' AND kind = 'redemption'
             */
            $table->index('sale_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_transactions');
    }
};
