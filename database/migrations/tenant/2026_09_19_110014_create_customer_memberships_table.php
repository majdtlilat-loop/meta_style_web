<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A membership a customer bought — a snapshot of the plan at the moment of sale.
 *
 * Activated only when the invoice that sold it is SETTLED — fully paid, or free
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §11). `unique(sale_item_id)`: one
 * sale line activates one membership, whatever retries.
 *
 *   starts_at     when it begins — now, or the end of the customer's current
 *                 membership of the same plan, so a renewal never overlaps
 *   expires_at    the start of the branch-local day it stops working
 *   status        active · cancelled; "expired" and "upcoming" are derived
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_memberships', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('membership_plan_id')->constrained('membership_plans')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            // What was bought, as it was bought.
            $table->json('name');
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);
            $table->unsignedSmallInteger('duration_days');

            $table->foreignId('sale_id')->constrained('sales')->restrictOnDelete();
            $table->foreignId('sale_item_id')->unique()->constrained('sale_items')->restrictOnDelete();

            $table->dateTime('activated_at');
            $table->dateTime('starts_at');
            $table->dateTime('expires_at');

            $table->string('status', 16);
            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancelled_by_id', 64)->nullable();
            $table->string('cancelled_by_label', 190)->nullable();
            $table->string('cancel_reason', 190)->nullable();

            $table->timestamps();

            /*
             * A customer's memberships — the till, the customer page, and the
             * renewal that starts after the current one:
             *
             *   SELECT ... FROM customer_memberships
             *   WHERE customer_id = ? AND status = 'active'
             */
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_memberships');
    }
};
