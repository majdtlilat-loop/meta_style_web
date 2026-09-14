<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service Journey — what actually happened during a visit.
 *
 * ## The distinction this whole phase rests on
 *
 *   `appointments` / `appointment_items`  →  what was RESERVED
 *   `service_journeys` / `journey_stages` →  what was EXECUTED
 *
 * Booked with Ahmed at 10:00 for 30 minutes; arrived at 10:12, seen by Sara,
 * finished at 10:51. Both facts are true and neither may overwrite the other.
 * A single set of columns would force a choice between answering "what did we
 * promise?" and "what did we do?", and every delay report, duration variance
 * and employee attribution later needs both (§27, §57).
 *
 * That is why there is no `arrived`, `waiting`, `in_service` or `stage` column
 * anywhere in the Phase 6 booking schema, and an architecture test keeps it
 * that way.
 *
 * ## Not a queue
 *
 * A stage's `waiting` means "this service has not started yet". It is not a
 * ticket, not a position, not a priority and not a display state. Phase 8 will
 * build Queue ON TOP of these tables; putting queue fields here now would be
 * guessing at its design (§44).
 *
 * ## Times are TIMESTAMP, with one deliberate exception
 *
 * The lifecycle stamps are TIMESTAMP, matching `appointments.completed_at`:
 * they are stamped at `now()` as things happen, and every one of them is
 * nullable. A scheduled instant is DATETIME instead, because it is a future
 * wall-clock fact that must not be re-converted (ADR-046).
 *
 * `journey_stage_resources.assigned_at` is DATETIME for a third reason, and it
 * cost an afternoon to find: MariaDB gives the first NON-NULLABLE TIMESTAMP
 * column in a table an implicit `ON UPDATE CURRENT_TIMESTAMP`, so closing a
 * usage row rewrote when it had opened. See the note on the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_journeys', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * UNIQUE, and that is the idempotency mechanism. A double-clicked
             * check-in is a duplicate-key violation the Action catches and
             * turns into "here is the journey that already exists" — a database
             * invariant rather than an HTTP idempotency key on an internal
             * button (§48).
             *
             * restrictOnDelete: an appointment with operational history behind
             * it must not vanish underneath it.
             */
            $table->foreignId('appointment_id')->unique()->constrained('appointments')->restrictOnDelete();

            // active · completed · aborted. No `not_started`: the ABSENCE of a
            // journey means the customer has not arrived, which is one fewer
            // state to keep honest (§17).
            $table->string('status', 16)->default('active');

            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            /*
             * Aborted: the customer arrived, processing began, and then they
             * left — or the visit was called off at the desk. Without a
             * terminal operational state such a journey stays `active` forever
             * while its appointment is cancelled, which is dangling state the
             * board can never clear.
             *
             * Aborting does NOT cancel the appointment. Journey never writes
             * appointment status; the supported flow calls the Booking
             * lifecycle Action, and an architecture test enforces it.
             */
            $table->timestamp('aborted_at')->nullable();
            $table->string('abort_reason', 190)->nullable();

            $table->string('created_by_type', 24)->default('staff');
            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_label', 190)->nullable();

            $table->timestamps();

            // The board: today's journeys by operational state.
            $table->index(['status', 'arrived_at']);
        });

        Schema::create('journey_stages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('service_journey_id')->constrained('service_journeys')->cascadeOnDelete();

            /*
             * One stage per booked item, so a stage always knows what was
             * promised. UNIQUE because Phase 7 derives stages from items
             * exactly once; splitting or adding a stage is a Phase 8+ concern
             * and would relax this constraint deliberately rather than by
             * accident.
             */
            $table->foreignId('appointment_item_id')->unique()->constrained('appointment_items')->restrictOnDelete();

            $table->unsignedTinyInteger('position')->default(0);

            /*
             * The operational routing axis, snapshotted from the booked
             * SERVICE's department at check-in. Never `service_category_id`:
             * Category is customer-facing menu grouping, Department is how the
             * center is actually organised (ADR-037, §22).
             */
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            /*
             * The employee who ACTUALLY performed it, which may differ from
             * `appointment_items.employee_id`. Reassigning here never rewrites
             * the booking (§18, §24).
             */
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();

            // waiting · in_service · completed · skipped
            $table->string('status', 16)->default('waiting');

            $table->timestamp('waiting_started_at')->nullable();
            $table->timestamp('service_started_at')->nullable();
            $table->timestamp('service_completed_at')->nullable();

            // Required when skipping. "Why did this not happen" is the only
            // interesting thing about a skipped stage.
            $table->string('skip_reason', 190)->nullable();

            $table->timestamps();

            // Rendering one visit, in order.
            $table->index(['service_journey_id', 'position']);

            // The board's "in service right now" and "waiting" groupings.
            $table->index(['status', 'service_started_at']);
        });

        Schema::create('journey_stage_resources', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('journey_stage_id')->constrained('journey_stages')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('resources')->restrictOnDelete();

            $table->unsignedTinyInteger('quantity')->default(1);

            /*
             * ACTUAL USAGE INTERVALS, not current assignment.
             *
             * A swap mid-service closes the open row and opens a new one, so
             * the history reads "Laser 1 from 10:00 to 10:15, Laser 2 from
             * 10:15 to 10:40" rather than "both machines were involved somehow"
             * (§26 as corrected). Overwriting `resource_id` in place would
             * destroy exactly the interval a device-utilisation report needs.
             *
             * `released_at` null means still in use.
             *
             * DATETIME, not TIMESTAMP, and this one is not stylistic.
             *
             * MariaDB gives the FIRST non-nullable TIMESTAMP column in a table
             * an implicit `DEFAULT CURRENT_TIMESTAMP ON UPDATE
             * CURRENT_TIMESTAMP`. `assigned_at` is that column here — so
             * closing a usage row silently rewrote when it had opened, to the
             * server's local clock, destroying the exact interval this table
             * exists to preserve. Nothing errored; the numbers just moved.
             *
             * The same trap as ADR-046, one column along. These are interval
             * boundaries the system does arithmetic on, so they belong in
             * DATETIME with the other instants the engine reasons about.
             */
            $table->dateTime('assigned_at');
            $table->dateTime('released_at')->nullable();
            $table->string('release_reason', 190)->nullable();

            $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // "What is this stage using now, and what did it use?"
            $table->index(['journey_stage_id', 'assigned_at']);

            // "Is this resource actually occupied?" — the capacity check for a
            // swap, which asks about live usage rather than reservations.
            $table->index(['resource_id', 'released_at']);
        });

        Schema::create('journey_handoffs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('service_journey_id')->constrained('service_journeys')->cascadeOnDelete();

            /*
             * Append-only history. A small explicit record rather than a
             * generic event store: "who passed this customer to whom, when, and
             * why" is one indexed read, and an event-sourcing framework would
             * be infrastructure nothing else in the product uses (§23).
             */
            $table->foreignId('from_stage_id')->nullable()->constrained('journey_stages')->nullOnDelete();
            $table->foreignId('to_stage_id')->nullable()->constrained('journey_stages')->nullOnDelete();

            $table->foreignId('from_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('to_employee_id')->nullable()->constrained('employees')->nullOnDelete();

            $table->foreignId('from_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->foreignId('to_department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('note', 190)->nullable();

            $table->string('actor_type', 24)->default('staff');
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 190)->nullable();

            $table->timestamps();

            $table->index(['service_journey_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journey_handoffs');
        Schema::dropIfExists('journey_stage_resources');
        Schema::dropIfExists('journey_stages');
        Schema::dropIfExists('service_journeys');
    }
};
