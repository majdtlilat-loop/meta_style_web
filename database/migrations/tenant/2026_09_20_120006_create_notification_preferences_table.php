<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's answer for one optional switch.
 *
 * ABSENT MEANS ON. A row exists only where somebody turned something off — or
 * turned it back on again — so a new customer needs no rows at all, and a new
 * preference key defaults to enabled everywhere without a backfill
 * (docs/23-NOTIFICATIONS.md §8).
 *
 * Only the notification types that name a key can be suppressed. A preference
 * can never hide an appointment somebody else cancelled, an invoice that was
 * issued, or a one-star review a manager is responsible for: those are facts,
 * and a switch that could hide them would make the inbox an unreliable record
 * of what the center did.
 *
 * The unique name is given explicitly — the generated one is 66 characters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('owner_kind', 16);
            $table->unsignedBigInteger('owner_id');

            $table->string('preference_key', 48);
            $table->boolean('enabled');

            $table->timestamps();

            $table->unique(['owner_kind', 'owner_id', 'preference_key'], 'notification_preferences_owner_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
