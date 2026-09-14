<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a branch is open, and when it is not.
 *
 * ONE ROW PER INTERVAL, not one row per day. A salon that opens 09:00–13:00,
 * closes for the afternoon and reopens 16:00–22:00 is the normal case in this
 * market, not an edge case — so a schema with `opens_at`/`closes_at` columns on
 * a day row would be wrong for most centers on day one, and widening it later
 * would mean migrating every tenant.
 *
 * OVERNIGHT is a convention, not a column: `closes_at <= opens_at` means the
 * interval crosses midnight. A `spans_midnight` boolean would be a second
 * source of truth for something the two times already say, and the two could
 * disagree.
 *
 * Times are stored as plain TIME in the BRANCH's timezone, not as timestamps.
 * "We open at nine" is a wall-clock fact that does not shift with daylight
 * saving or with where the server is (docs/10-API-FOUNDATION.md §8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_working_hours', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            // 0 = Sunday .. 6 = Saturday. Numeric rather than a name so the
            // week can be rendered starting on any day — Saturday in Iraq,
            // Monday in Europe — without touching stored data.
            $table->unsignedTinyInteger('day_of_week');

            $table->time('opens_at');
            $table->time('closes_at');

            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'day_of_week']);
        });

        Schema::create('branch_hour_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();

            // A plain date, never a midnight timestamp — Eid is a date, and it
            // is the same date whatever timezone reads it.
            $table->date('date');

            // Closed entirely, or open at unusual times. The two cases are one
            // table because they answer the same question and a UI shows them
            // in one list.
            $table->boolean('is_closed')->default(true);
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();

            // Why, for the staff who look at it in six months.
            $table->string('note', 190)->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_hour_exceptions');
        Schema::dropIfExists('branch_working_hours');
    }
};
