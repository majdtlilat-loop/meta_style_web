<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Times an employee is not bookable — a break, training, a personal block.
 *
 * Phase 6 deliberately left this out; the Availability Engine knew about
 * appointments and nothing else, so a stylist's lunch hour was bookable
 * (docs/13-ROADMAP.md Phase 7 §13).
 *
 * ## This is NOT attendance
 *
 * No clock-in, no clock-out, no worked hours, no leave balance, no payroll and
 * no HR policy. Those are a workforce module with regulatory weight, and
 * building the schema for it now would mean designing against imagined
 * requirements (§45).
 *
 * A block answers one question: is this person bookable at this time. When
 * attendance eventually arrives it becomes a SECOND source answering the same
 * question, joining this one behind `BlockFinder` — the Booking Engine does not
 * change.
 *
 * ## Branch is REQUIRED
 *
 * A nullable branch meaning "everywhere" reads well and costs a great deal:
 * availability is a per-branch question, the affected-appointment query would
 * need two shapes, and a mutation would have to lock every branch to coordinate
 * with in-flight bookings (§2 of the Phase 7 corrections, ADR-044). Blocking
 * somebody across three branches is three rows, created together by one Action.
 *
 * ## DATETIME, not TIMESTAMP
 *
 * A scheduled instant, exactly like `appointments.starts_at`: MySQL converts a
 * TIMESTAMP through the session timezone, which is a second opinion about a
 * question `BranchClock` has already answered (ADR-046).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_availability_blocks', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // A controlled vocabulary, not free text: "why is this person
            // unavailable" is a question a future report will group by, and a
            // typed reason is the difference between an answer and a word cloud.
            $table->string('type', 24)->default('break');

            // Staff-only. Never rendered on a customer surface, and never
            // copied into an audit row (§41).
            $table->text('internal_note')->nullable();

            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            /*
             * The bulk load behind every availability query: every block for a
             * set of employees overlapping a date range, in one round trip.
             * Asking per candidate slot is the pattern §39 exists to prevent.
             */
            $table->index(['employee_id', 'starts_at', 'ends_at'], 'employee_blocks_window_index');

            // "Which blocks exist at this branch today?" — the management
            // screen, and the affected-appointment query.
            $table->index(['branch_id', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_availability_blocks');
    }
};
