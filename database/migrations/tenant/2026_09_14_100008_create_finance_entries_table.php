<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The center's operational money ledger: every movement of real money, once.
 *
 * ## What an entry is — and is not
 *
 *   collection        a payment succeeded — money IN
 *   refund            a refund succeeded — money OUT
 *   expense           an expense was posted — money OUT
 *   expense_reversal  a posted expense was voided — money back IN
 *
 * Issuing an invoice is NOT an entry. An invoice is an amount billed; the ledger
 * is money that actually moved (docs/20-FINANCE.md §35, ADR-059).
 *
 * ## Append-only
 *
 * No update, no delete: the model refuses both and a scan refuses query-builder
 * writes. A mistake is corrected by a new entry.
 *
 * ## Once per source
 *
 * `unique(source_type, source_uuid, kind)`. A provider that repeats its "paid"
 * callback five times still produces one collection.
 *
 * Not double-entry accounting, not a general ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // in · out
            $table->string('direction', 8);

            // collection · refund · expense · expense_reversal
            $table->string('kind', 24);

            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            // cash · manual_electronic · gateway
            $table->string('method', 24);
            $table->string('provider', 32)->nullable();

            // payment · refund · expense
            $table->string('source_type', 16);
            $table->uuid('source_uuid');

            // The drawer the cash went into or out of. Its FK index serves the
            // expected-cash sum at close:
            //
            //   SELECT kind, SUM(amount_minor) FROM finance_entries
            //   WHERE cashier_shift_id = ? AND method = 'cash' GROUP BY kind
            $table->foreignId('cashier_shift_id')->nullable()->constrained('cashier_shifts')->restrictOnDelete();

            // A snapshot for the ledger screen, never parsed.
            $table->string('label', 190);

            $table->dateTime('occurred_at');

            $table->dateTime('created_at');

            /*
             * Once per source, whatever retries.
             */
            $table->unique(['source_type', 'source_uuid', 'kind']);

            /*
             * The ledger screen and every dashboard sum over a range:
             *
             *   SELECT kind, method, SUM(amount_minor) FROM finance_entries
             *   WHERE branch_id IN (...) AND occurred_at >= ? AND occurred_at < ?
             *   GROUP BY kind, method
             */
            $table->index(['branch_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_entries');
    }
};
