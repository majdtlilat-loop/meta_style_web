<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The appointment: one customer's reservation at one branch, at one time.
 *
 * TWO TABLES, NOT ONE ROW WITH A SERVICE COLUMN. A customer books a haircut,
 * a beard trim and a facial as ONE visit, and modelling that as three unrelated
 * appointments loses the fact that they are one arrival by one person — which
 * is the fact reception, the queue, the invoice and the reminder all need.
 * Services live in `appointment_items` (docs/13-ROADMAP.md Phase 6 §2).
 *
 * NO `tenant_id`. The appointment is in this center's database; a column
 * repeating that would be a second source of truth and an invitation to filter
 * by it instead of by connection (docs/02-TENANCY.md §1).
 *
 * NO `local_date` COLUMN. The branch-local calendar day is derivable from
 * `starts_at` and the branch timezone, and deriving it in PHP is exact. A
 * stored copy could disagree with the timestamp beside it — the same reasoning
 * that made `BranchWorkingHour::crossesMidnight()` a method rather than a
 * boolean. Day and week queries convert the local range to a UTC range once and
 * scan `(branch_id, starts_at)`.
 *
 * WHAT IS NOT HERE, on purpose: journey stage, queue ticket, room, chair,
 * device, deposit, payment, invoice, commission, reminder state. Phase 7
 * describes how a visit is executed; this table describes what was reserved,
 * and keeping the two apart is what lets Service Journey be built without
 * replacing the Booking Engine (§38).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            // restrictOnDelete, not cascade: an appointment is history, and a
            // customer is archived rather than deleted. If something ever does
            // try to hard-delete a customer with bookings, the database should
            // refuse rather than quietly erase the record of a visit.
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->string('status', 24)->default('booked');

            // Which channel booked it. Derived server-side from the endpoint,
            // never accepted from a request body.
            $table->string('source', 32)->default('staff');

            /*
              * DATETIME, not TIMESTAMP, and the distinction matters here.
              *
              * MySQL and MariaDB convert a TIMESTAMP from the SESSION timezone
              * to UTC on write and back on read. Meta Style already stores UTC
              * deliberately, so that conversion is a second opinion about a
              * question already answered — and it silently changes its mind if
              * the database server's timezone is ever changed. DATETIME stores
              * exactly the instant that was written.
              *
              * It also sidesteps a MariaDB rule that would otherwise refuse this
              * table outright: only the FIRST non-nullable TIMESTAMP column in a
              * table gets an implicit default, and every one after it is given
              * `0000-00-00 00:00:00`, which strict mode rejects.
              *
              * The nullable lifecycle stamps below stay TIMESTAMP, matching the
              * rest of the schema — a nullable TIMESTAMP defaults to NULL and
              * has neither problem.
              */
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            /*
             * The branch's IANA timezone AS IT WAS WHEN BOOKED.
             *
             * A snapshot in the same family as the price and duration snapshots
             * on the items. If a center later corrects a branch's timezone, the
             * stored instant does not move — so rendering it against the NEW
             * zone would show a wall-clock time nobody agreed to. Keeping the
             * zone that was in force lets the appointment still say "10:00, as
             * booked" and makes the correction visible instead of silent.
             */
            $table->string('booked_timezone', 64);

            // What the customer typed when booking. Distinct from an internal
            // note: this one was written BY the customer, and staff notes must
            // never be shown to them (§17).
            $table->text('customer_note')->nullable();

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('no_show_at')->nullable();

            // Cancellation metadata (§13). `cancelled_from_status` is kept
            // because "cancelled from confirmed" and "cancelled from booked"
            // are different events to a center, and the status column can only
            // hold where it ended up.
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancelled_from_status', 24)->nullable();
            $table->string('cancellation_reason', 190)->nullable();
            $table->string('cancelled_by_type', 24)->nullable();
            $table->string('cancelled_by_id', 64)->nullable();
            $table->string('cancelled_by_label', 190)->nullable();

            /*
             * Who created it, denormalised.
             *
             * The label is captured now rather than resolved later, for the
             * same reason audit entries capture theirs: a booking made by a
             * receptionist who has since left must still say who made it
             * (docs/08-AUDIT-SECURITY.md §3). The id is a uuid — a staff user's
             * or a customer account's — never an internal database id, and
             * never a phone number.
             */
            $table->string('created_by_type', 24)->default('staff');
            $table->string('created_by_id', 64)->nullable();
            $table->string('created_by_label', 190)->nullable();

            $table->timestamps();

            // The calendar: every day and week view is a bounded scan of this.
            $table->index(['branch_id', 'starts_at']);

            // "Upcoming", "today's confirmed", "no-shows this month".
            $table->index(['status', 'starts_at']);

            // A customer's own history, and the customer profile timeline when
            // it arrives.
            $table->index(['customer_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
