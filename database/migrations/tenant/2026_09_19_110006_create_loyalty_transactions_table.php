<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every points movement, append-only — the truth a balance is proven from.
 *
 *   kind        earn · redeem · adjustment · reversal · recovery · expiry
 *   direction   in · out; `points` is always positive
 *   unrecovered a refund reversal that asked for more than the customer still
 *               had: the balance stops at zero and the shortfall is recorded
 *               here. Later earnings settle it with `recovery` rows before any
 *               of them become available — never a debt shown to the customer,
 *               never a free loss (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §8)
 *   expires_at  SNAPSHOT on a credit, taken when it was written: when those
 *               points stop being usable. Changing the program later affects
 *               new credits only (§21). On a `redeem` row: the expiry the
 *               points get back if the redemption is returned.
 *   source      what caused it: payment · refund · journey · benefit · manual ·
 *               recovery · expiry — with that thing's uuid
 *   context     the invoice (spend) or sale (redemption) it belongs to
 *
 * Never updated, never deleted: a mistake is corrected by a reversal or an
 * adjustment. `unique(source_type, source_uuid, kind)` makes every automatic
 * movement once-only, whatever retries — one earn per payment, one reversal per
 * refund, one reward per visit, one reversal per redemption, one recovery per
 * earning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('loyalty_account_id')->constrained('loyalty_accounts')->restrictOnDelete();

            $table->string('kind', 16);
            $table->string('direction', 8);
            $table->unsignedInteger('points');
            $table->unsignedInteger('unrecovered_points')->default(0);

            $table->dateTime('expires_at')->nullable();

            $table->string('source_type', 16);
            $table->uuid('source_uuid');
            $table->uuid('context_uuid')->nullable();

            $table->string('reason', 190)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 190)->nullable();

            $table->dateTime('occurred_at');
            $table->dateTime('created_at');

            $table->unique(['source_type', 'source_uuid', 'kind']);

            /*
             * A customer's history, oldest first for the FIFO walk that decides
             * which credits a debit used and which have aged out; newest first
             * for the screen:
             *
             *   SELECT ... FROM loyalty_transactions
             *   WHERE loyalty_account_id = ? ORDER BY occurred_at, id
             */
            $table->index(['loyalty_account_id', 'occurred_at']);

            /*
             * What one invoice has already earned, so a payment or a refund
             * writes only the difference:
             *
             *   SELECT kind, SUM(points + unrecovered_points) ...
             *   WHERE loyalty_account_id = ? AND context_uuid = ? GROUP BY kind
             */
            $table->index(['loyalty_account_id', 'context_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
