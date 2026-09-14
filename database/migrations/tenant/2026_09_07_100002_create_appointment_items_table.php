<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One booked service inside an appointment, and the add-ons chosen with it.
 *
 * ## Snapshots, and why they are not optional
 *
 * A manager raises the price of a haircut from 20,000 to 25,000, or its
 * duration from 30 minutes to 45. Every appointment already in the book must
 * keep the price and the duration the customer agreed to — otherwise tomorrow's
 * price change silently rewrites yesterday's bookings, next week's schedule
 * shifts by fifteen minutes per appointment, and the invoice charges an amount
 * nobody quoted (docs/13-ROADMAP.md Phase 6 §3).
 *
 * So price, duration, currency and the display name are copied here at booking
 * time. NOT the whole service row: a JSON dump of every column would carry
 * descriptions, flags and images that nothing reads and that go stale in a
 * different, more confusing way. Only what preserves the MEANING of the booking
 * is snapshotted.
 *
 * The canonical service stays linked "when possible" — hence nullable with
 * `nullOnDelete`. If a service is ever hard-deleted the item survives with its
 * name and its price, because an appointment that loses its own history is
 * worse than one that loses a foreign key.
 *
 * ## What this table is NOT
 *
 * It is not a journey stage. It says what was RESERVED. Phase 7 will add
 * stages saying what actually happened — started when, by whom, in which room,
 * handed off to whom — in their own table, referencing this one. No stage
 * status, no queue state and no "in progress" flag belongs here (§38).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointment_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();

            // Nullable and nulled on delete — see the note above.
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->foreignId('service_variation_id')->nullable()
                ->constrained('service_variations')->nullOnDelete();

            // Nulled, never cascaded. Deleting an employee must not delete the
            // appointments they were assigned to; a center that removes a
            // stylist still has to serve the customers already booked with
            // them (§21).
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // Whether the customer asked for that employee, or the engine
            // chose. Operationally different when a reassignment is needed.
            $table->string('employee_selection', 16)->default('any');

            // Order within the visit. Services run sequentially in Phase 6, so
            // this is also the order they happen in.
            $table->unsignedTinyInteger('position')->default(0);

            // DATETIME for the same two reasons as `appointments` — no
            // session-timezone conversion over values that are already UTC, and
            // no implicit `0000-00-00` default on the second one.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // ---- Snapshots -------------------------------------------------

            // Total for this item: base or variation duration, plus every
            // selected add-on. Stored resolved, so the schedule can be redrawn
            // without re-reading the catalog.
            $table->unsignedSmallInteger('duration_minutes');

            // Integer minor units, and the currency alongside it. The currency
            // is a center-level setting today, but an appointment is history —
            // if a center ever switches currency, an old booking must not
            // silently re-denominate (docs/10-API-FOUNDATION.md §9).
            $table->unsignedBigInteger('price_minor');
            $table->string('currency', 3);

            // Translatable, like the catalog it came from: a receipt or a
            // reminder has to reproduce what the customer actually saw, in the
            // language they saw it in.
            $table->json('service_name');
            $table->json('variation_name')->nullable();

            // "Shorter on the sides." Customer-authored, per service. Not an
            // internal note and never confused with one.
            $table->text('customer_note')->nullable();

            $table->timestamps();

            /*
             * THE OVERLAP INDEX.
             *
             * Every conflict check and every availability query runs
             *
             *     WHERE employee_id = ? AND starts_at < ? AND ends_at > ?
             *
             * joined to appointments for the status filter. Leading on
             * employee_id then starts_at makes that a range scan; ends_at is
             * included so the row does not have to be fetched to evaluate the
             * second half of the overlap rule.
             *
             * An ordinary composite index — no functional or generated columns
             * anywhere in this schema (ADR-033).
             */
            $table->index(['employee_id', 'starts_at', 'ends_at'], 'appointment_items_employee_window_index');

            $table->index(['appointment_id', 'position']);
        });

        /*
         * Add-ons chosen for an item, with their own snapshots.
         *
         * A separate table rather than a JSON column on the item: an add-on has
         * a price and a duration that later modules will sum, group and report
         * on, and none of that is reachable in a JSON blob without the kind of
         * MySQL-specific functional indexing this schema does not use.
         */
        Schema::create('appointment_item_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_item_id')->constrained('appointment_items')->cascadeOnDelete();
            $table->foreignId('service_addon_id')->nullable()
                ->constrained('service_addons')->nullOnDelete();

            $table->json('name');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('duration_minutes');
            $table->string('currency', 3);

            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointment_item_addons');
        Schema::dropIfExists('appointment_items');
    }
};
