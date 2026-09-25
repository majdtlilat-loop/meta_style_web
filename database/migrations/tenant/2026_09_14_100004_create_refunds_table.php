<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Refunds: money returned against one successful payment.
 *
 * Its own record, because the payment it returns money from is history and is
 * never rewritten. Partial refunds are normal; several of them may not add up
 * to more than the payment (docs/19-PAYMENTS.md §§25–26).
 *
 * ## A refund does not reopen the invoice
 *
 * It is money leaving the center, not a statement that the customer owes the
 * amount again. The invoice stays paid; the net collected goes down. Rebilling
 * or credit notes, if ever needed, are designed explicitly (§9).
 *
 *   pending → succeeded | failed        (a provider refund)
 *   succeeded                           (cash or manual electronic)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('payment_id')->constrained('payments')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            // cash · manual_electronic · gateway — how the money went back.
            $table->string('method', 24);
            $table->string('provider', 32)->nullable();

            // pending · succeeded · failed
            $table->string('status', 16);

            $table->string('reason', 190);

            $table->string('provider_refund_reference', 128)->nullable();

            // A cash refund leaves a drawer.
            $table->foreignId('cashier_shift_id')->nullable()->constrained('cashier_shifts')->restrictOnDelete();

            $table->string('idempotency_token', 64)->nullable()->unique();

            $table->string('requested_by_id', 64)->nullable();
            $table->string('requested_by_label', 190)->nullable();

            $table->dateTime('requested_at');
            $table->dateTime('succeeded_at')->nullable();
            $table->dateTime('failed_at')->nullable();

            $table->string('failure_code', 64)->nullable();

            $table->timestamps();

            /*
             * What is still refundable, under the payment lock, and the refunded
             * totals the settlement read model reports:
             *
             *   SELECT payment_id, status, SUM(amount_minor) FROM refunds
             *   WHERE payment_id IN (...) GROUP BY payment_id, status
             */
            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
