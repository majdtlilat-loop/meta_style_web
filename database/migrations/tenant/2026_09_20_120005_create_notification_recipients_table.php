<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's copy of one notification, and their read state.
 *
 * ## Two id spaces, and no foreign key
 *
 * A recipient is a STAFF user in `users` or a CUSTOMER's login in
 * `customer_accounts` — different tables, different guards, unrelated id
 * sequences. A column cannot reference both, so there is no FK and
 * `recipient_kind` carries the other half of the identity. EVERY query filters
 * on both columns; one that filtered on the id alone would hand staff user 7
 * the inbox of customer account 7 (docs/23-NOTIFICATIONS.md §4).
 *
 * `unique(notification_id, recipient_kind, recipient_id)` makes a second
 * attempt at the same person a no-op rather than a duplicate line in their
 * inbox.
 *
 * Index and unique names are given explicitly: the generated ones are 74 and 65
 * characters, over the 64-character identifier limit both engines enforce —
 * after `create table` has already succeeded, which is what makes that failure
 * so confusing to diagnose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();

            $table->string('recipient_kind', 16);
            $table->unsignedBigInteger('recipient_id');

            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['notification_id', 'recipient_kind', 'recipient_id'], 'notification_recipients_unique');

            /*
             * The inbox, and the unread count:
             *
             *   SELECT ... FROM notification_recipients
             *   WHERE recipient_kind = ? AND recipient_id = ? ORDER BY id DESC
             *
             *   SELECT COUNT(*) FROM notification_recipients
             *   WHERE recipient_kind = ? AND recipient_id = ? AND read_at IS NULL
             */
            $table->index(['recipient_kind', 'recipient_id', 'read_at'], 'notification_recipients_inbox_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};
