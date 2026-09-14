<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queue: numbers, tickets, and what happened to each of them.
 *
 * ## A ticket is a waiting and calling mechanism, nothing more
 *
 *     Appointment     what was RESERVED
 *     ServiceJourney  the operational VISIT
 *     JourneyStage    what was actually PERFORMED
 *     QueueTicket     the waiting, calling and routing around a stage
 *
 * Four concepts, permanently. A ticket never becomes the source of truth for
 * service execution: it does not know when a service started, only that its
 * stage did, and it learns that from Journey rather than deciding it
 * (docs/17-QUEUE.md §1, §5).
 *
 * So there is almost no customer or service data here. The number, where to go,
 * and the times of queue events. Everything else is one relation away through
 * the journey, and a copy on the ticket would be a copy on a PUBLIC SCREEN's
 * read model, which is the last place it belongs (§14).
 *
 * ## Every instant is DATETIME
 *
 * MariaDB gives the first NON-NULLABLE TIMESTAMP column in a table an implicit
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. `issued_at` is that
 * column here, and every later mutation of a ticket would silently rewrite when
 * it was issued — destroying the one value every waiting-time figure is
 * measured from. It cost Phase 7 an afternoon on `journey_stage_resources`;
 * it does not get to cost it twice (ADR-046, ADR-050).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_sequences', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            /*
             * The BRANCH-LOCAL date, from `BranchClock`. A center in Baghdad
             * rolls over at its own midnight; a server clock deciding when the
             * day resets would restart the numbering mid-evening
             * (CLAUDE.md, docs/17-QUEUE.md §6).
             */
            $table->date('business_date');

            $table->string('prefix', 4);

            $table->unsignedInteger('last_number')->default(0);

            $table->timestamps();

            /*
             * THE ROW THAT GETS LOCKED. Issuing a number is:
             *
             *   INSERT IGNORE the row  →  SELECT ... FOR UPDATE  →  +1  →  write
             *
             * `SELECT MAX(number) + 1` without a lock hands two simultaneous
             * walk-ins the same ticket; an in-process counter does the same
             * across two workers and cannot even be detected. This unique key is
             * both the lock target and the insert-or-ignore key (§6, §35).
             */
            $table->unique(['branch_id', 'business_date', 'prefix']);
        });

        Schema::create('queue_tickets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            /*
             * Denormalised deliberately. It is the numbering scope, it is in
             * every board and display query, and it is what makes "no ticket
             * from branch A appears on branch B's screen" a WHERE clause rather
             * than a three-table join through a journey that may have no
             * appointment at all (§20).
             */
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();

            $table->foreignId('service_journey_id')->constrained('service_journeys')->cascadeOnDelete();
            $table->foreignId('journey_stage_id')->constrained('journey_stages')->cascadeOnDelete();

            /*
             * ONE OPEN TICKET PER STAGE, as a database invariant.
             *
             * Equal to `journey_stage_id` while the ticket is open and NULL once
             * it closes. Both engines allow many NULLs in a unique index, so
             * history piles up freely while a double-clicked "issue ticket"
             * collides and is turned into "here is the ticket that already
             * exists" — the same three-layer pattern check-in uses (§11).
             *
             * A partial index would be the obvious tool and neither engine has
             * one; a generated column would be MySQL-8-only. This is the
             * portable shape, maintained by the Action (ADR-033).
             */
            $table->foreignId('active_journey_stage_id')->nullable()->unique()
                ->constrained('journey_stages')->nullOnDelete();

            // Snapshotted from the stage at issue time: the routing axis, and
            // the department-filtered board and display both read it.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            // Where the customer was sent. Null until somebody calls them.
            $table->foreignId('service_point_id')->nullable()
                ->constrained('queue_service_points')->nullOnDelete();

            $table->date('business_date');

            $table->string('prefix', 4);
            $table->unsignedInteger('number');

            /*
             * "A012", built once and stored.
             *
             * The alternative — formatting on read — means a later change to the
             * padding silently renumbers every historical ticket, including the
             * one printed on the paper in the customer's hand (§6).
             */
            $table->string('display_number', 12);

            /*
             * A NUMBER, not an enum of business meanings: 0 normal, 10 high,
             * 20 urgent. A center that later wants something between them sets
             * 15 rather than waiting for a release. Never inferred from anything
             * about the customer (§10).
             */
            $table->unsignedTinyInteger('priority')->default(0);

            // waiting · called · serving · held · completed · cancelled
            $table->string('state', 16)->default('waiting');

            $table->dateTime('issued_at');
            $table->dateTime('first_called_at')->nullable();
            $table->dateTime('last_called_at')->nullable();
            $table->dateTime('held_at')->nullable();
            $table->dateTime('serving_started_at')->nullable();
            $table->dateTime('closed_at')->nullable();

            $table->unsignedSmallInteger('call_count')->default(0);
            $table->unsignedSmallInteger('skip_count')->default(0);

            $table->string('hold_reason', 190)->nullable();
            $table->string('close_reason', 190)->nullable();

            /*
             * The uuid of the call event this ticket is currently announcing.
             *
             * A television polls every three seconds. Without a value that
             * CHANGES only when something new was said, it would either speak
             * the same number forever or need the browser to guess from
             * timestamps. A recall writes a new event and copies its uuid here,
             * so the screen speaks exactly once per call and exactly once more
             * per recall (§13, correction 2).
             *
             * A pointer at `queue_ticket_events`, written in the same locked
             * transaction as the event it names — never an independent fact.
             */
            $table->uuid('last_announcement_uuid')->nullable();

            // staff · walk_in · booking — which flow produced it.
            $table->string('source', 24)->default('staff');

            $table->string('issued_by_type', 24)->default('staff');
            $table->string('issued_by_id', 64)->nullable();
            $table->string('issued_by_label', 190)->nullable();

            $table->timestamps();

            /*
             * The numbering invariant, and the final backstop behind the
             * sequence lock: even a bug cannot produce two A012s on one day at
             * one branch — it produces an error instead (§6).
             *
             * On the COMPOSED number rather than on `(prefix, number)`, which
             * would say the same thing in four columns. This says it in three
             * and says slightly more: two prefixes that happen to compose to the
             * same string — "A" #1001 and "A1" #001 — are also refused, and
             * those are the two tickets that would be indistinguishable in the
             * customer's hand and on the screen.
             */
            $table->unique(['branch_id', 'business_date', 'display_number']);

            /*
             * THE call-next and board query, verbatim:
             *
             * SELECT ... FROM queue_tickets
             * WHERE branch_id = ? AND state = ?
             * ORDER BY priority DESC, issued_at ASC, id ASC
             *
             * Priority leads because it changes who is next; `issued_at` keeps
             * FIFO inside a priority; `id` makes a tie deterministic rather than
             * whatever order the engine happens to return (§10).
             *
             * The index stops at `priority` deliberately. The ORDER BY mixes
             * directions, and MariaDB 10.4 ignores DESC in an index definition
             * entirely — so the engine filesorts the matched rows whatever this
             * index says about `issued_at`, and a fourth column would cost
             * writes to buy nothing.
             *
             * `state` leads over `business_date` because of how this table
             * ages: after a year every day but one is history, and `waiting` is
             * a handful of rows out of all of it. The board query that filters
             * by DATE instead rides the `(branch_id, business_date, …)` prefix
             * of the display index below.
             */
            $table->index(['branch_id', 'state', 'priority']);

            /*
             * The public display feed, bounded:
             *
             * SELECT ... FROM queue_tickets
             * WHERE branch_id = ? AND business_date = ? AND last_called_at IS NOT NULL
             * ORDER BY last_called_at DESC LIMIT n
             *
             * One query, no join, at most `recent_calls_limit` rows — which is
             * what keeps a screen left on all day from reading a whole day of
             * tickets every three seconds (§19).
             */
            $table->index(['branch_id', 'business_date', 'last_called_at']);
        });

        Schema::create('queue_ticket_events', function (Blueprint $table): void {
            $table->id();

            /*
             * Public identifier, and the reason it exists is the television.
             *
             * The display feed exposes this as `announcement_id` for call and
             * recall events. The browser remembers the ones it has spoken, so a
             * poll that sees the same state says nothing and a recall — a new
             * event, a new uuid — speaks again. Exposing the numeric id would
             * publish a row count on a public screen (§13, correction 2).
             */
            $table->uuid('uuid')->unique();

            $table->foreignId('queue_ticket_id')->constrained('queue_tickets')->cascadeOnDelete();

            /*
             * 1, 2, 3 … per ticket. Allocated while the TICKET ROW is held
             * FOR UPDATE, so two desks acting at once cannot compute the same
             * next value — and the unique index below is what says so even if
             * somebody later writes a path that forgets the lock (§5).
             */
            $table->unsignedSmallInteger('sequence');

            /*
             * issued · called · recalled · skipped · held · resumed ·
             * transferred · serving_started · completed · cancelled ·
             * priority_changed
             *
             * APPEND ONLY. A recall never overwrites the previous call, which is
             * the whole reason this table exists rather than a handful of
             * columns: "called three times, transferred once, then served" is a
             * question the product has to answer, and a column cannot (§13).
             */
            $table->string('type', 24);

            $table->string('from_state', 16)->nullable();
            $table->string('to_state', 16)->nullable();

            // Where they were sent, recorded per call rather than only as the
            // ticket's current value — a transfer must not erase where somebody
            // was sent first.
            $table->foreignId('service_point_id')->nullable()
                ->constrained('queue_service_points')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('reason', 190)->nullable();

            $table->string('actor_type', 24)->default('staff');
            $table->string('actor_id', 64)->nullable();
            $table->string('actor_label', 190)->nullable();

            /*
             * DATETIME, like every other instant here, and for the same MariaDB
             * reason: this is the first non-nullable time column in the table.
             */
            $table->dateTime('occurred_at');

            $table->timestamps();

            /*
             * One ticket's history in order, and the concurrency backstop:
             *
             * SELECT ... FROM queue_ticket_events
             * WHERE queue_ticket_id = ? ORDER BY sequence
             */
            $table->unique(['queue_ticket_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_ticket_events');
        Schema::dropIfExists('queue_tickets');
        Schema::dropIfExists('queue_sequences');
    }
};
