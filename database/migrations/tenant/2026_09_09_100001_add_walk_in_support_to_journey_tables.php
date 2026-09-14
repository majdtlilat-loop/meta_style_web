<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Walk-in visits: an operational visit that never had a booking.
 *
 * Phase 7 tied a journey to an appointment with a NOT NULL foreign key, which
 * was right while every visit was booked. A barbershop's Saturday is not, and
 * the queue Phase 8 builds is mostly about people who walked through the door
 * (docs/16-JOURNEY-RESOURCES.md §22, docs/17-QUEUE.md §2).
 *
 * ## No fake appointments
 *
 * The cheap alternative — inventing an appointment so the old column is
 * satisfied — would put rows into the reservation system for reservations that
 * never existed, and every availability query, calendar screen and later report
 * would have to learn to filter them out again. The column becomes nullable
 * instead, and the journey carries the context it can no longer derive.
 *
 * ## The invariant
 *
 *     source = appointment   appointment_id NOT NULL, customer_id NULL, branch_id NULL
 *     source = walk_in       appointment_id NULL,     customer_id NOT NULL, branch_id NOT NULL
 *
 * Enforced in the Actions and by tests, NOT by a CHECK constraint: MariaDB 10.4
 * and MySQL 8 disagree about enforcing them, and ADR-033 keeps the schema
 * portable.
 *
 * A booked journey deliberately does NOT copy the customer or the branch. Both
 * are reachable through the appointment, and a second copy is a second place
 * they can disagree. `ServiceJourney::branchId()` and `customerId()` read the
 * column when it is set and fall back through the appointment, so callers see
 * one answer and the storage keeps one rule.
 *
 * ## Widening, not contracting
 *
 * NOT NULL → NULL keeps every existing row valid, which is what makes this an
 * expand-phase migration rather than the kind that needs a release in between
 * (docs/03-DATABASE-MIGRATIONS.md §3). The foreign key is dropped and re-added
 * around the change because MariaDB will not modify a column while a constraint
 * references it; the unique index survives and is what keeps check-in
 * idempotent for booked visits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_journeys', function (Blueprint $table): void {
            $table->dropForeign(['appointment_id']);
        });

        Schema::table('service_journeys', function (Blueprint $table): void {
            // The unique index stays: it is the check-in idempotency mechanism,
            // and both engines allow many NULLs in a unique index, so walk-ins
            // do not collide with each other.
            $table->unsignedBigInteger('appointment_id')->nullable()->change();
        });

        Schema::table('service_journeys', function (Blueprint $table): void {
            $table->foreign('appointment_id')->references('id')->on('appointments')->restrictOnDelete();

            /*
             * Present for walk-ins only. A booked journey leaves both null and
             * reads them through the appointment.
             */
            $table->foreignId('customer_id')->nullable()->after('appointment_id')
                ->constrained('customers')->restrictOnDelete();

            $table->foreignId('branch_id')->nullable()->after('customer_id')
                ->constrained('branches')->restrictOnDelete();

            // appointment · walk_in. Explicit, rather than inferred from which
            // column happens to be null — a reader should not have to know the
            // invariant to know what kind of visit this is.
            $table->string('source', 24)->default('appointment')->after('branch_id');

            /*
             * The walk-in equivalent of the unique appointment_id.
             *
             * Reception presses "create visit" twice. There is no appointment to
             * collide on, so the request carries a token and the database
             * refuses the second one — the same three-layer pattern check-in
             * uses: read, unique index, catch and return the winner
             * (docs/17-QUEUE.md §11).
             */
            $table->string('idempotency_token', 64)->nullable()->unique()->after('source');

            /*
             * Today's board, including walk-ins.
             *
             * SELECT ... FROM service_journeys
             * WHERE branch_id = ? AND status = ? ORDER BY arrived_at
             *
             * A walk-in has no appointment to join through, so the Phase 7
             * board query — which reached the branch via `appointments` — cannot
             * find one at all without this.
             */
            $table->index(['branch_id', 'status', 'arrived_at']);
        });

        Schema::table('journey_stages', function (Blueprint $table): void {
            $table->dropForeign(['appointment_item_id']);
        });

        Schema::table('journey_stages', function (Blueprint $table): void {
            $table->unsignedBigInteger('appointment_item_id')->nullable()->change();
        });

        Schema::table('journey_stages', function (Blueprint $table): void {
            $table->foreign('appointment_item_id')->references('id')->on('appointment_items')->restrictOnDelete();

            /*
             * A walk-in stage's service, and the snapshot that outlives it.
             *
             * The booked path reads all of this from `appointment_items`, which
             * snapshots for exactly the same reason: renaming a service or
             * changing its price must never rewrite what happened. A walk-in has
             * no item, so the snapshot lives here.
             *
             * `duration_minutes` is not decoration — it is the expected-use
             * window the Phase 7 capacity check admits a stage on (ADR-050).
             */
            $table->foreignId('service_id')->nullable()->after('appointment_item_id')
                ->constrained('services')->nullOnDelete();

            $table->json('service_name')->nullable()->after('service_id');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('service_name');
            $table->unsignedBigInteger('price_minor')->nullable()->after('duration_minutes');
            $table->string('currency', 3)->nullable()->after('price_minor');
        });
    }

    public function down(): void
    {
        Schema::table('journey_stages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
            $table->dropColumn(['service_name', 'duration_minutes', 'price_minor', 'currency']);
        });

        Schema::table('service_journeys', function (Blueprint $table): void {
            $table->dropIndex(['branch_id', 'status', 'arrived_at']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['source', 'idempotency_token']);
        });
    }
};
