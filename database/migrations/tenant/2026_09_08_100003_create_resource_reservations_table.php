<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The concrete resources a booking holds.
 *
 * One row per (appointment item, resource). A laser session that needs a room
 * AND a machine produces two rows against the same item — which is why this is
 * a table and not a `resource_id` column on `appointment_items`
 * (docs/13-ROADMAP.md Phase 7 §6).
 *
 * ## No times here, on purpose
 *
 * A reservation is held for exactly as long as its appointment item, and the
 * item already stores `starts_at`/`ends_at`. Copying them would create a second
 * place that has to be kept in step through every reschedule — the same
 * two-writes-one-can-fail problem that keeps appointment STATUS off the item
 * rather than denormalised onto it (§7).
 *
 * The conflict query therefore joins item → appointment for the window and the
 * status, and drives from `resource_id`, which is the selective end.
 *
 * ## PLANNED, not actual
 *
 * These rows say what the booking reserved. What was actually used during the
 * visit — including a device swapped mid-service — lives in
 * `journey_stage_resources`, with its own time boundaries. The two are allowed
 * to diverge and neither rewrites the other (§25, §27).
 *
 * ## The snapshots
 *
 * `resource_name` and `resource_type_name` are frozen for the same reason every
 * price and duration on the item is: renaming "Room 1" to "VIP Room" must not
 * rewrite what a customer was told last month (§36). The FK stays, so the live
 * record is still reachable; the snapshot is what the history means.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resource_reservations', function (Blueprint $table): void {
            $table->id();

            // Cancelling a booking removes its items and with them these rows.
            $table->foreignId('appointment_item_id')->constrained('appointment_items')->cascadeOnDelete();

            /*
             * restrictOnDelete, not nullOnDelete. A reservation with no
             * resource is a row that still consumes capacity in no identifiable
             * place. Resources archive instead of deleting, which is what makes
             * this constraint something operators never meet (§12, §36).
             */
            $table->foreignId('resource_id')->constrained('resources')->restrictOnDelete();

            // How much of the resource's capacity this booking holds. 1 for an
            // exclusive room; 2 when a service needs two places in a shared
            // hammam.
            $table->unsignedTinyInteger('quantity')->default(1);

            $table->json('resource_name');
            $table->json('resource_type_name');

            $table->timestamps();

            /*
             * The capacity sweep: given a handful of candidate resources, find
             * every reservation against them, then join to the item for the
             * window. `resource_id` leads because it is the selective column —
             * one resource's whole history is small, a day's items are not.
             */
            $table->index(['resource_id', 'appointment_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_reservations');
    }
};
