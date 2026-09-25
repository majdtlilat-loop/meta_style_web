<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The drawer count at the end of a shift, and what it was measured against.
 *
 * Finance's record, written in the same transaction that closes the shift. It
 * keeps SNAPSHOTS of every component of the expected amount, so a later report
 * — or a later correction elsewhere — can never rewrite what the cashier was
 * held to at the time (docs/20-FINANCE.md §§31–33).
 *
 *   expected = opening + cash collected − cash refunded − cash expenses
 *              + cash expense reversals
 *   variance = counted − expected        (signed; recorded, never "fixed")
 *
 * Not a deposit, not a bank reconciliation, not accounting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shift_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // One count per shift.
            $table->foreignId('cashier_shift_id')->unique()->constrained('cashier_shifts')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->string('currency', 3);

            $table->unsignedBigInteger('opening_cash_minor');
            $table->unsignedBigInteger('cash_collected_minor');
            $table->unsignedBigInteger('cash_refunded_minor');
            $table->unsignedBigInteger('cash_expenses_minor');
            $table->unsignedBigInteger('cash_expense_reversals_minor');

            // Signed: a drawer can be expected to hold less than nothing when
            // more cash was refunded than collected.
            $table->bigInteger('expected_cash_minor');
            $table->unsignedBigInteger('counted_cash_minor');
            $table->bigInteger('variance_minor');

            $table->string('note', 190)->nullable();

            $table->string('reconciled_by_id', 64)->nullable();
            $table->string('reconciled_by_label', 190)->nullable();
            $table->dateTime('reconciled_at');

            $table->timestamps();

            /*
             * Variances over a range, for the finance dashboard:
             *
             *   SELECT ... FROM cashier_shift_reconciliations
             *   WHERE branch_id IN (...) AND reconciled_at >= ? AND reconciled_at < ?
             */
            $table->index(['branch_id', 'reconciled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shift_reconciliations');
    }
};
