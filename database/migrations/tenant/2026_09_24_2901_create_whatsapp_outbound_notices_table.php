<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Business-initiated WhatsApp notices — one row per FACT a customer is told
 * about, whatever happened to it (docs/25-WHATSAPP.md §22).
 *
 * The first purpose is `booking_confirmation`: a guest (a customer with no
 * `customer_accounts` row) whose booking was CONFIRMED is sent the center's
 * approved confirmation template.
 *
 * `unique(purpose, source_type, source_uuid)` IS the idempotency. The booking
 * event heard twice, the reconciler racing the listener and a retry of a
 * refused send all meet this row, and exactly one ever exists — so a booking
 * produces one confirmation decision, never two messages. Nothing asks "have I
 * already sent this" (the Notifications rule, docs/23 §12).
 *
 * The row records the DECISION and the attempt, never the content: no phone
 * number (the thread holds it), no rendered text (the message row holds it),
 * no provider body. `reason` is a short code — a skip reason or the provider's
 * safe failure code — never a raw provider message.
 *
 *   pending   claimed; the send is in progress or its process died mid-way.
 *             NEVER retried automatically: it may have reached the customer.
 *   sent      the provider accepted it and named it.
 *   failed    the provider refused it (or it was refused before sending).
 *             Safe to retry — bounded by `attempts`.
 *   unknown   the outcome was never observed. NEVER retried (§9).
 *   skipped   policy said no: channel not in the plan, setting off, no usable
 *             phone, opted out, channel not connected, no approved template…
 *             `reason` says which. A decision, never replayed later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_outbound_notices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // `booking_confirmation`. A code, never a class name.
            $table->string('purpose', 32);

            // The domain fact: 'appointment' + its uuid. Opaque here.
            $table->string('source_type', 24);
            $table->uuid('source_uuid');

            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // The thread and the message the attempt produced, once it did.
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();

            // pending · sent · failed · unknown · skipped
            $table->string('status', 12);
            $table->string('reason', 64)->nullable();

            // The language the notice was (or would have been) written in.
            $table->string('locale', 12)->nullable();

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('last_attempt_at')->nullable();

            $table->timestamps();

            // 32 + 24 + 36 characters of utf8mb4 = 368 bytes.
            $table->unique(['purpose', 'source_type', 'source_uuid'], 'wa_notices_source_unique');

            /*
             * The reconciler's retry scan and the Manager's recent summary:
             *
             *   WHERE status = 'failed' AND created_at >= ?
             *   WHERE purpose = ? AND created_at >= ? GROUP BY status
             */
            $table->index(['status', 'created_at'], 'wa_notices_status_idx');
            $table->index(['purpose', 'created_at'], 'wa_notices_purpose_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbound_notices');
    }
};
