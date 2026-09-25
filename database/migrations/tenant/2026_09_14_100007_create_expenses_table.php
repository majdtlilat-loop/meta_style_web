<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real money the center paid out.
 *
 *   posted → voided        (with a reason; never edited, never deleted)
 *
 * Posting writes an `expense` ledger entry; voiding writes an
 * `expense_reversal`. The expense row keeps what was posted so the ledger and
 * the record behind it can always be read together (docs/20-FINANCE.md §§39–40).
 *
 * Not suppliers, purchasing, payroll or inventory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('expense_category_id')->constrained('expense_categories')->restrictOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            $table->dateTime('occurred_at');

            // cash · manual_electronic
            $table->string('method', 24);

            $table->string('reference', 120)->nullable();
            $table->string('payee_label', 120)->nullable();
            $table->string('description', 500);

            /*
             * Set only when the person recording it says the cash came out of
             * their open drawer. Never inferred from whoever happens to have a
             * shift open.
             */
            $table->foreignId('cashier_shift_id')->nullable()->constrained('cashier_shifts')->restrictOnDelete();

            // posted · voided
            $table->string('status', 16);

            $table->string('idempotency_token', 64)->nullable()->unique();

            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_label', 190)->nullable();

            $table->dateTime('voided_at')->nullable();
            $table->string('voided_by_id', 64)->nullable();
            $table->string('voided_by_label', 190)->nullable();
            $table->string('void_reason', 190)->nullable();

            $table->timestamps();

            /*
             * A branch's expenses over a range:
             *
             *   SELECT ... FROM expenses
             *   WHERE branch_id = ? AND occurred_at >= ? AND occurred_at < ?
             *   ORDER BY occurred_at DESC LIMIT n
             */
            $table->index(['branch_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
