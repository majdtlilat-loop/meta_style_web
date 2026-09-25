<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Loyalty saw at an instant: whether the center owned `loyalty`, and which
 * rule version was effective.
 *
 * Eligibility must depend on the state WHEN THE EVENT HAPPENED, never on the
 * state when a reconciliation happens to run: money collected while loyalty was
 * owned stays recoverable after it is taken away, and money collected during a
 * gap never earns when it is granted again
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 *
 * Two kinds of row, both append-only:
 *
 *   tied to a source   written when Loyalty heard a payment, refund or
 *                      completed visit — the exact answer for that event,
 *                      captured while its transaction was still open
 *   free-standing      written by a configuration change or a reconciliation
 *                      run — the timeline used for an event whose own
 *                      observation never made it (a process that died between
 *                      the commit and the callback)
 *
 * Entitlements live in the control plane and keep no history of their own; this
 * is Loyalty's own record of what it observed, and nothing else reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_earning_observations', function (Blueprint $table): void {
            $table->id();

            /*
             * The state at an instant, for an event with no observation of its
             * own:
             *
             *   SELECT * FROM loyalty_earning_observations
             *   WHERE observed_at <= ? ORDER BY observed_at DESC, id DESC LIMIT 1
             */
            $table->dateTime('observed_at')->index();

            $table->boolean('owns_loyalty');
            $table->foreignId('loyalty_rule_version_id')->nullable()->constrained('loyalty_rule_versions')->nullOnDelete();

            // The event this answers for, when there is one. One row per event.
            $table->string('source_type', 16)->nullable();
            $table->uuid('source_uuid')->nullable();

            $table->dateTime('created_at');

            $table->unique(['source_type', 'source_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_earning_observations');
    }
};
