<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A cashier's working session at one branch.
 *
 * ## An operational period, not a cash drawer
 *
 * It answers "who was at the till, from when to when, and which sales did that
 * session produce". Every finalized sale is stamped with the shift it was
 * finalized in, which is the seam Phase 10's cash reconciliation needs: without
 * it, a day's takings could not be attributed to a person.
 *
 * No opening float, no counted cash, no variance, no deposit. Those are
 * reconciliation, and reconciliation is Finance (docs/18-SALES.md §13).
 *
 * ## One open shift per user per branch
 *
 * `active_user_id` equals `user_id` while the shift is open and is NULL once it
 * closes. Both engines allow many NULLs in a unique index, so closed history
 * piles up freely while a second "open shift" for the same person at the same
 * branch collides — the portable shape of a partial unique index neither engine
 * has (ADR-033). The Action takes a lock in front of it; this is the backstop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            $table->unsignedBigInteger('active_user_id')->nullable();

            // open · closed
            $table->string('status', 16)->default('open');

            /*
             * DATETIME. `opened_at` is the first non-nullable instant in this
             * table, and as a TIMESTAMP MariaDB would silently rewrite it on
             * every later update — including the one that closes the shift
             * (ADR-046).
             */
            $table->dateTime('opened_at');
            $table->dateTime('closed_at')->nullable();

            $table->string('opening_note', 190)->nullable();
            $table->string('closing_note', 190)->nullable();

            // Usually the cashier; a supervisor when a forgotten shift is
            // closed on somebody's behalf.
            $table->string('closed_by_id', 64)->nullable();
            $table->string('closed_by_label', 190)->nullable();

            $table->timestamps();

            /*
             * The current-shift lookup AND the invariant:
             *
             *   SELECT ... FROM cashier_shifts
             *   WHERE active_user_id = ? AND branch_id = ?
             */
            $table->unique(['active_user_id', 'branch_id']);

            /*
             * A branch's shift list, bounded:
             *
             *   SELECT ... FROM cashier_shifts
             *   WHERE branch_id = ? ORDER BY opened_at DESC LIMIT n
             */
            $table->index(['branch_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
