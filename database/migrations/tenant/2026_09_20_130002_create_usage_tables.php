<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What this center has actually used, in this center's own database.
 *
 * Three tables with three different jobs (docs/26-USAGE-QUOTAS.md §§5–7):
 *
 *   `usage_events`    append-only evidence. One row per thing that happened,
 *                     keyed so the same thing can never be counted twice.
 *   `usage_counters`  the authoritative running total for a period, and the
 *                     allowance snapshot it is judged against.
 *   `usage_alerts`    which thresholds have already been announced.
 *
 * ## Why the counter is not just SUM(usage_events)
 *
 * Because a quota check happens on the hot path of every AI run, and summing a
 * growing table under a lock to decide whether one more is allowed gets slower
 * exactly as a center gets busier. The counter is the decision; the events are
 * the audit trail that proves the decision was right and lets it be rebuilt.
 *
 * ## Why the counter is here and not in the control plane
 *
 * A quota decision must be one atomic statement against one database. Reaching
 * across to the control plane on every run would mean a cross-database
 * check-then-increment — two round trips with a race between them, on the path
 * that most needs not to have one (§5).
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Append-only. Nothing updates or deletes a row here; retention trims
         * old periods, and that is all.
         */
        Schema::create('usage_events', function (Blueprint $table): void {
            $table->id();

            // A code from config/usage.php: `ai_runs`, `wa_outbound`...
            $table->string('resource', 64);

            $table->unsignedBigInteger('quantity');

            /*
             * WHAT this was usage of: `ai_run` + the run's uuid, `wa_message` +
             * the message's uuid. Together with the resource these are the
             * idempotency key, so a retried listener, a replayed webhook and a
             * reconciler pass all produce ONE row (§5).
             */
            $table->string('source_type', 48);
            $table->string('source_uuid', 64);

            // Metered facts that only the provider knows. Null when the
            // provider did not return them — never guessed, never derived from
            // a price list this code invented (§§6, 13).
            $table->string('provider', 32)->nullable();
            $table->string('model', 64)->nullable();

            // When it happened, and which period it belongs to. The period is
            // stored rather than derived, because a center's billing boundaries
            // can move and a row must stay in the period it was counted into.
            $table->dateTime('occurred_at');
            $table->dateTime('period_start');

            $table->timestamps();

            /*
             * The idempotency key. `insertOrIgnore` against this is the whole
             * mechanism — nothing anywhere asks "have I already counted this".
             *
             * Named explicitly: the generated name is over 64 characters, which
             * breaks provisioning mid-migration (docs/03 §7.2).
             */
            $table->unique(['resource', 'source_type', 'source_uuid'], 'usage_events_source_unique');

            // The reconciler's scan, and the projection's aggregate.
            $table->index(['resource', 'period_start'], 'usage_events_period_idx');
        });

        Schema::create('usage_counters', function (Blueprint $table): void {
            $table->id();

            $table->string('resource', 64);

            $table->dateTime('period_start');
            $table->dateTime('period_end');

            /*
             * The allowance AS IT WAS when this period opened, resolved once
             * from tenant override -> plan -> system default.
             *
             * Snapshotted so the hot path never crosses databases, and so a
             * mid-period plan change cannot retroactively make already-allowed
             * usage disallowed. NULL means UNLIMITED (§7).
             */
            $table->unsignedBigInteger('allowance_snapshot')->nullable();

            /*
             * Which control-plane override version produced the snapshot, or
             * NULL when it came from the plan or the default.
             *
             * This is what lets the reconciler tell "nothing has changed" from
             * "changed, and not applied yet" without comparing allowances —
             * a comparison cannot distinguish a stale snapshot from a
             * deliberate decrease that is correctly waiting for next period (§8).
             */
            $table->unsignedBigInteger('allowance_version')->nullable();

            $table->unsignedBigInteger('used')->default(0);

            $table->dateTime('last_activity_at')->nullable();

            $table->timestamps();

            /*
             * The row identity, and the concurrency backstop.
             *
             * Two simultaneous first requests in a new period both try to
             * create this row; this index makes exactly one succeed and the
             * other fall through to reading it. No lock, no advisory key, no
             * duplicate-key error ever reaching a customer (§5).
             */
            $table->unique(['resource', 'period_start'], 'usage_counters_period_unique');
        });

        /*
         * One row per threshold announced, so nobody is told twice.
         *
         * Without this the 85% warning would fire on every message after the
         * 85th, which is the fastest way to teach a manager to ignore the
         * notification that matters — the one at 100% (§52).
         */
        Schema::create('usage_alerts', function (Blueprint $table): void {
            $table->id();

            $table->string('resource', 64);
            $table->dateTime('period_start');
            $table->unsignedSmallInteger('threshold');

            $table->dateTime('raised_at');

            $table->timestamps();

            $table->unique(['resource', 'period_start', 'threshold'], 'usage_alerts_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_alerts');
        Schema::dropIfExists('usage_counters');
        Schema::dropIfExists('usage_events');
    }
};
