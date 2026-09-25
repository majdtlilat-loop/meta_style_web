<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payments: one collection attempt against one invoice.
 *
 * ## A payment is not an invoice
 *
 * The invoice is the immutable amount billed. A payment is money actually
 * received, or an attempt to receive it. A split bill is one invoice with
 * several payments; nothing here ever changes the invoice row
 * (docs/19-PAYMENTS.md §§5, 8, ADR-058).
 *
 * ## Status is small on purpose
 *
 *   pending → succeeded | failed | cancelled        (gateway)
 *   succeeded                                        (cash, manual electronic)
 *
 * All three end states are final. A refund is its own record and never turns a
 * payment back into anything.
 *
 * ## What is never stored
 *
 * No card number, CVV, PIN, OTP or wallet credential — an architecture scan
 * refuses such a column anywhere (docs/08-AUDIT-SECURITY.md §9). Provider
 * references are opaque identifiers the provider issued, kept only to reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('invoice_id')->constrained('invoices')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3);

            // cash · manual_electronic · gateway
            $table->string('method', 24);

            // pending · succeeded · failed · cancelled
            $table->string('status', 16);

            // desk · public_link
            $table->string('source', 16);

            // Manual electronic: what the desk saw, in its own words — "FIB
            // transfer", "bank transfer". Staff-confirmed, never gateway-verified.
            $table->string('manual_method_label', 60)->nullable();
            $table->string('manual_reference', 120)->nullable();

            // Gateway only.
            $table->foreignId('gateway_account_id')->nullable()->constrained('payment_gateway_accounts')->restrictOnDelete();
            $table->string('provider', 32)->nullable();
            $table->string('provider_payment_reference', 128)->nullable();

            // Non-secret things a customer needs to pay: a short code to type
            // into the provider's app, and the provider's own payment link.
            $table->string('provider_display_code', 64)->nullable();
            $table->string('checkout_url', 1000)->nullable();
            $table->dateTime('expires_at')->nullable();

            // The drawer this money went into, when it did.
            $table->foreignId('cashier_shift_id')->nullable()->constrained('cashier_shifts')->restrictOnDelete();

            $table->string('idempotency_token', 64)->nullable()->unique();

            $table->string('collected_by_id', 64)->nullable();
            $table->string('collected_by_label', 190)->nullable();

            // DATETIME, every one: ADR-046.
            $table->dateTime('initiated_at');
            $table->dateTime('succeeded_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();

            // A code from a fixed list, never a provider's raw message.
            $table->string('failure_code', 64)->nullable();

            $table->timestamps();

            /*
             * THE HOT ONE. The settlement read model, the reservation check under
             * the invoice lock, and the void guard all ask:
             *
             *   SELECT invoice_id, status, SUM(amount_minor) FROM payments
             *   WHERE invoice_id IN (...) GROUP BY invoice_id, status
             */
            $table->index(['invoice_id', 'status']);

            /*
             * A branch's payments, bounded:
             *
             *   SELECT ... FROM payments
             *   WHERE branch_id = ? AND initiated_at >= ? AND initiated_at < ?
             *   ORDER BY initiated_at DESC LIMIT n
             */
            $table->index(['branch_id', 'initiated_at']);

            /*
             * A provider callback finds its payment by the account it arrived for
             * and the reference that account's provider issued — never by the
             * reference alone, whose format and uniqueness belong to one provider:
             *
             *   SELECT ... FROM payments
             *   WHERE gateway_account_id = ? AND provider_payment_reference = ?
             *
             * Unique, and both columns are NULL for desk payments — NULLs never
             * collide (ADR-033).
             */
            $table->unique(['gateway_account_id', 'provider_payment_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
