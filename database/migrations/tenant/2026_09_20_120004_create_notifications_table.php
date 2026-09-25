<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The FACT a notification is about — once, not once per person.
 *
 * `unique(type, source_type, source_uuid)` IS the idempotency story. The same
 * appointment heard twice, a reminder sweep that overlaps itself and a
 * reconciliation pass all attempt the same row, and exactly one survives. No
 * "have I already sent this?" query, which would race with the very thing it is
 * trying to prevent (docs/23-NOTIFICATIONS.md §12).
 *
 * `params` holds the small allow-listed values its sentence needs. NOT rendered
 * text: the message is built at read time, in the reader's own language, so a
 * customer who switches to Kurdish sees their whole inbox in it. Never HTML,
 * never a serialised model, never an internal id (§7).
 *
 * No delivery status. In-app is the only channel, and there "delivered" means
 * the recipient row exists; a `sent`/`failed` column for something that cannot
 * fail would be a status that lies (§10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('type', 48);
            $table->string('severity', 16);

            // The domain fact: 'appointment' + its uuid, 'review' + its uuid.
            // Opaque here — Notifications names no other module's tables.
            $table->string('source_type', 24);
            $table->uuid('source_uuid');

            // Where it happened, for staff targeting and branch scoping. Null
            // for anything that is not about one branch.
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->json('params');

            $table->dateTime('created_at');

            // 48 + 24 + 36 characters of utf8mb4 = 432 bytes, inside the 3072
            // InnoDB key limit on both engines.
            $table->unique(['type', 'source_type', 'source_uuid']);

            /*
             * Retention, and the newest-first inbox join:
             *
             *   SELECT ... FROM notifications WHERE created_at < ?
             */
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
