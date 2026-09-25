<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The spend rule an invoice earns under — snapshotted at its first qualifying
 * collection.
 *
 * An invoice's earning is always `rule(net collected)`, and every payment or
 * refund moves it to that target, writing only the difference. Freezing the
 * rule per invoice makes that exact and order-independent: a program change
 * between two payments of one bill, or a refund processed out of order after a
 * crash, cannot change what the bill earns or over-reverse it
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§4, 8).
 *
 * `unique(invoice_uuid)`: one rule per invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_invoice_rules', function (Blueprint $table): void {
            $table->id();

            $table->uuid('invoice_uuid')->unique();
            $table->foreignId('loyalty_account_id')->constrained('loyalty_accounts')->restrictOnDelete();

            $table->unsignedInteger('spend_points');
            $table->unsignedBigInteger('spend_unit_minor');
            $table->unsignedBigInteger('min_spend_minor');

            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_invoice_rules');
    }
};
