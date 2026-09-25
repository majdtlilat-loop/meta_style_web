<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A package a customer bought — a snapshot of the definition at the moment of
 * sale.
 *
 * Created only when the invoice that sold it is SETTLED — fully paid, or free —
 * never from an unpaid invoice (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §15).
 * `unique(sale_item_id)`: one sale line activates one package, whatever
 * retries — the payment event, the finalization event and a replay all find
 * the same row.
 *
 *   status        active · cancelled. "Expired" is DERIVED from `expires_at`,
 *                 so nothing needs a scheduler to become true.
 *   expires_at    the start of the branch-local day the package stops working
 *
 * What is left of it is never stored here: it is proven from
 * `package_transactions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_packages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('package_definition_id')->constrained('package_definitions')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // What was bought, as it was bought.
            $table->json('name');
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('validity_days');

            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('sale_item_id')->unique()->constrained('sale_items')->restrictOnDelete();

            $table->dateTime('activated_at');
            $table->dateTime('expires_at');

            $table->string('status', 16);
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by_id', 64)->nullable();
            $table->string('cancelled_by_label', 190)->nullable();
            $table->string('cancel_reason', 190)->nullable();

            $table->timestamps();

            /*
             * A customer's packages, active first — the till and the customer's
             * own page:
             *
             *   SELECT ... FROM customer_packages
             *   WHERE customer_id = ? AND status = 'active'
             */
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_packages');
    }
};
