<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per distinct provider callback a gateway account received.
 *
 * Providers deliver callbacks at least once; the same "paid" can arrive five
 * times. The fingerprint — provider, reference and the VERIFIED status — is the
 * idempotency key, so a repeat finds its row and changes nothing
 * (docs/19-PAYMENTS.md §22).
 *
 * ## No raw body
 *
 * A provider payload can carry a payer's name, an account number, a phone. None
 * of it is needed to settle a payment, so none of it is stored: the event keeps
 * what was decided and why, in safe codes. Retaining bodies for debugging would
 * need a written retention policy first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('gateway_account_id')->constrained('payment_gateway_accounts')->restrictOnDelete();
            $table->string('provider', 32);

            // When the provider supplies a trustworthy event id.
            $table->string('provider_event_id', 128)->nullable();

            // SHA-256 hex of the provider's canonical identity for this event.
            $table->char('fingerprint', 64);

            $table->string('event_type', 32);
            $table->boolean('signature_verified')->default(false);

            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            // processed · duplicate · ignored · amount_mismatch · unknown_payment
            $table->string('result', 32);
            $table->string('error_code', 64)->nullable();

            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();

            $table->timestamps();

            /*
             * The idempotency key and its lookup:
             *
             *   INSERT ... ; on a duplicate key the event was already handled
             */
            $table->unique(['gateway_account_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
    }
};
