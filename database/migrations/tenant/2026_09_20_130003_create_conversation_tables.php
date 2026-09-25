<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Talking to a customer, and the provider account it happens through.
 *
 * Four tables, and deliberately NOT a support-ticket system
 * (docs/25-WHATSAPP.md §§2–5). There is no priority, no queue, no SLA, no
 * assignment rules engine, no tags, no macros, no satisfaction survey. A
 * conversation here is: this phone number, this center, what was said, and
 * whether a human or the AI is answering.
 *
 * ## Why the messages are stored at all
 *
 * Because the center has to be able to see what was said to their customer in
 * their name. A staff member taking over mid-conversation needs the thread, and
 * a manager asked "what did your bot tell my mother" needs to be able to
 * answer. That is the whole justification, and it is what bounds the retention
 * policy (§18).
 *
 * ## What is NOT stored
 *
 * The provider's raw webhook body. It arrives carrying a display name, a
 * profile, a wa_id and whatever Meta adds next, none of which is needed once
 * the message has been read out of it — the same decision Payments made for
 * gateway callbacks, for the same reason: retaining bodies "for debugging"
 * needs a written retention policy first, and there is not one (§18).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The center's own WhatsApp Business account, at the provider.
         *
         * ONE CENTER, ONE ACCOUNT IDENTITY. A provider account resolves to
         * exactly one tenant, enforced by the unique `phone_number_id` here and
         * by the fact that this table is in the center's own database. There is
         * no shared Meta Style number through which several centers speak —
         * that would put one center's customers in another center's inbox the
         * first time a number was reused (§4).
         */
        Schema::create('whatsapp_accounts', function (Blueprint $table): void {
            $table->id();

            // Public and opaque: it appears in the webhook callback URL.
            $table->uuid('uuid')->unique();

            // `meta_cloud` — a registry code, never a class name.
            $table->string('provider', 32);

            $table->string('display_name', 120);

            /*
             * Meta's identifier for the sending number, and the routing key for
             * everything inbound: a notification carries it at
             * `entry[].changes[].value.metadata.phone_number_id`.
             *
             * NOT A SECRET — it is an account identifier, it appears in every
             * outbound URL, and it is useless without the access token. It is a
             * COLUMN rather than a credential precisely because inbound routing
             * has to query it before any credential is decrypted.
             */
            $table->string('phone_number_id', 64)->nullable();

            /*
             * The WhatsApp Business Account id. Also not a secret; it is what
             * `entry[].id` carries, and checking it is a second, cheap
             * confirmation that a notification belongs to this account.
             */
            $table->string('business_account_id', 64)->nullable();

            // The human-readable number, for the settings screen. Shown to
            // staff so they can tell which of their numbers this is.
            $table->string('display_phone_number', 32)->nullable();

            $table->boolean('enabled')->default(false);

            /*
             * `encrypted:array`: the access token, the app secret and the
             * webhook verify token.
             *
             * TEXT, and so no default (ADR-033). Never presented, never logged,
             * never audited, and read through exactly one method that fails
             * closed when the application key has moved on (§4).
             */
            $table->text('credentials')->nullable();

            $table->dateTime('configured_at')->nullable();
            $table->string('configured_by_id', 64)->nullable();
            $table->string('configured_by_label', 190)->nullable();

            // The last time the provider accepted or rejected something,
            // for the settings screen's connection state. A FACT, not a claim:
            // it is only ever written from a real provider response (§16).
            $table->dateTime('last_inbound_at')->nullable();
            $table->dateTime('last_error_at')->nullable();
            $table->string('last_error_code', 64)->nullable();

            $table->timestamps();

            /*
             * The inbound routing lookup. Unique because two accounts in one
             * center claiming the same provider number would make routing
             * ambiguous, and the resolution would depend on row order.
             */
            $table->unique('phone_number_id');
            $table->index(['provider', 'enabled']);
        });

        Schema::create('conversations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // `whatsapp`. One value today; a column rather than an assumption,
            // because the staff inbox and every query already have to say which.
            $table->string('channel', 24);

            $table->foreignId('whatsapp_account_id')->nullable()
                ->constrained('whatsapp_accounts')->nullOnDelete();

            /*
             * The customer this thread was resolved to, once it was.
             *
             * NULLABLE and it stays that way for a first-time sender: a
             * conversation can exist before anybody knows who it is with, and
             * inventing a customer record for every wrong number would fill a
             * center's CRM with people who never visited (§7).
             *
             * Resolving it does NOT make future messages trusted. Every inbound
             * message still passes signature verification on its own (§6).
             */
            $table->foreignId('customer_id')->nullable()
                ->constrained('customers')->nullOnDelete();

            /*
             * The sender's number in E.164, from the VERIFIED provider envelope
             * and never from message text or a tool argument (§6).
             */
            $table->string('contact_phone', 32);

            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // ai_active · human_requested · human_active · closed
            $table->string('status', 24)->default('ai_active');

            // Which language this thread is being held in. Stored, because the
            // AI must answer in it and a staff reply is rendered in it (§17).
            $table->string('locale', 8)->default('en');

            // Who took it over. Null while the AI has it.
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('last_message_at')->nullable();

            /*
             * How many times in a row the AI could not complete a run.
             *
             * The trigger for an automatic hand-off: a thread where the model
             * or the provider keeps failing must reach a human rather than
             * retrying at a customer forever (§12).
             */
            $table->unsignedSmallInteger('consecutive_failures')->default(0);

            $table->timestamps();

            /*
             * The staff inbox: open threads, most recent first.
             */
            $table->index(['status', 'last_message_at']);

            /*
             * Inbound routing: this account, this number, still open.
             *
             * Not unique — a closed conversation and a new one from the same
             * number must be able to coexist, because that is two separate
             * visits' worth of talking, and merging them would show a customer
             * last month's thread when they say hello today.
             */
            $table->index(['whatsapp_account_id', 'contact_phone'], 'conversations_account_phone_idx');

            $table->index(['customer_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // inbound · outbound
            $table->string('direction', 12);

            // customer · ai · staff · system
            $table->string('author_type', 12);

            // Which staff member, when a human wrote it.
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');

            /*
             * The provider's own id for this message — `wamid.…`.
             *
             * For INBOUND it is the replay key (§10). For OUTBOUND it is how a
             * later status callback finds the row it is about (§11).
             */
            $table->string('provider_message_id', 128)->nullable();

            // pending · sent · failed · unknown. Null for inbound: a message
            // that arrived has no delivery state of ours (§9).
            $table->string('delivery_state', 12)->nullable();

            $table->string('failure_code', 64)->nullable();

            /*
             * Set only when a template was used instead of free-form text.
             * Meta requires one outside the customer service window, and which
             * template was sent is a fact staff need when reading the thread.
             */
            $table->string('template_name', 128)->nullable();

            $table->dateTime('sent_at')->nullable();
            $table->dateTime('delivered_at')->nullable();

            $table->timestamps();

            // The thread, in order. `id` rather than a timestamp: two messages
            // in the same second must still have a defined order.
            $table->index(['conversation_id', 'id']);

            /*
             * Status callbacks arrive by provider id. Not unique: an inbound
             * and an outbound message can never share one, but leaving room
             * avoids a constraint whose only job would be to reject a provider
             * doing something unexpected at 3am.
             */
            $table->index('provider_message_id');
        });

        /*
         * One row per distinct provider notification accepted.
         *
         * Meta delivers at least once and retries on any non-2xx, so the same
         * message arrives repeatedly. The fingerprint is the idempotency key,
         * and the unique index is the mechanism — no Redis lock, no "have I
         * seen this" query (§10).
         */
        Schema::create('whatsapp_webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('whatsapp_account_id')->constrained('whatsapp_accounts')->restrictOnDelete();

            // SHA-256 hex over the provider's canonical identity for this
            // notification: account, kind, and the provider's own message id.
            $table->char('fingerprint', 64);

            // message · status
            $table->string('kind', 16);

            $table->string('provider_message_id', 128)->nullable();

            // Whether the signature verified. An unverified notification is
            // never processed, and the row exists so a burst of them is
            // visible rather than silent (§8).
            $table->boolean('signature_verified')->default(false);

            // accepted · duplicate · rejected · ignored · rate_limited
            $table->string('result', 24);
            $table->string('error_code', 64)->nullable();

            $table->dateTime('received_at');

            $table->timestamps();

            $table->unique(['whatsapp_account_id', 'fingerprint'], 'wa_webhook_events_fingerprint_unique');
            $table->index(['whatsapp_account_id', 'received_at'], 'wa_webhook_events_received_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_webhook_events');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('whatsapp_accounts');
    }
};
