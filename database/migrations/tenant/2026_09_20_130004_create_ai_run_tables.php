<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the assistant did, as EXECUTION METADATA — not as the conversation.
 *
 * The distinction is the whole reason these are separate tables from
 * `messages` (docs/27-RAYAN.md §8):
 *
 *   `messages`      the DOMAIN RECORD. What was said to a customer, and by
 *                   whom. A center is accountable for it, staff read it, and it
 *                   is what a complaint is answered from.
 *   `ai_runs`       one attempt to produce an answer. Which model, how many
 *                   tokens, how long, did it succeed. Operational and
 *                   commercial, never customer-facing.
 *   `ai_tool_calls` which approved operations that attempt requested, with what
 *                   outcome. The audit trail for "the bot booked something".
 *
 * Collapsing them would mean either a conversation cluttered with token counts
 * or a usage report that disappears when messages are pruned.
 *
 * ## No prompts, no raw model output, no arguments by default
 *
 * A run does NOT store the assembled prompt or the provider's raw response.
 * Both are reconstructible from the messages and both would duplicate customer
 * PII into a second table with a different retention policy — and the prompt
 * additionally contains the curated customer context, which is precisely the
 * data §10 exists to keep bounded.
 *
 * Tool ARGUMENTS are stored, because "which service, at which branch, at what
 * time" is the answer to the only question anybody will ask about an automated
 * booking. They are allow-listed to the tool's own declared parameters before
 * they get here, so a model that invented an extra field cannot smuggle
 * anything in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_runs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();

            // `openai`. The registry code, never a class name.
            $table->string('provider', 32);

            /*
             * The exact model id used, recorded per run rather than read from
             * configuration later. Configuration changes; a run is history, and
             * "which model said that" is unanswerable afterwards if the answer
             * is "whatever the config says today" (§7).
             */
            $table->string('model', 64);

            // completed · failed · refused · exhausted
            $table->string('status', 24);

            /*
             * Provider-reported token counts. NULLABLE and never derived: if
             * the provider did not return them, they are unknown, and an
             * estimate on a usage screen is a number somebody will budget
             * against (§6).
             */
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();

            // How far the bounded loop actually went (§15).
            $table->unsignedSmallInteger('turns')->default(0);
            $table->unsignedSmallInteger('tool_calls')->default(0);

            // A safe code — `timeout`, `rate_limited`, `provider_error`,
            // `max_turns`. Never a provider message, which can echo input.
            $table->string('failure_code', 64)->nullable();

            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();

            $table->timestamps();

            // The conversation's own run history, newest last.
            $table->index(['conversation_id', 'id']);

            // "How many runs failed this week", for the usage screen and for
            // the reconciler that notices a center whose assistant is broken.
            $table->index(['status', 'started_at']);
        });

        Schema::create('ai_tool_calls', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('ai_run_id')->constrained('ai_runs')->cascadeOnDelete();

            /*
             * The tool NAME from the registry — `get_available_slots`,
             * `create_booking`. A registry key, never a class name and never
             * anything the model chose freely: a name that is not in the
             * allow-list is refused before it reaches here (§11).
             */
            $table->string('tool', 64);

            // The provider's id for this call, so a result can be returned to
            // the right one when the model requests several at once.
            $table->string('provider_call_id', 128)->nullable();

            /*
             * The arguments, after validation and allow-listing against the
             * tool's declared parameters. JSON, and never `text` with a default
             * (ADR-033).
             */
            $table->json('arguments')->nullable();

            // ok · refused · failed
            $table->string('result', 16);

            // Why it was refused, in safe codes: `not_entitled`, `not_owned`,
            // `out_of_scope`, `invalid_arguments`.
            $table->string('refusal_code', 64)->nullable();

            $table->unsignedInteger('duration_ms')->nullable();

            $table->timestamps();

            $table->index(['ai_run_id', 'id']);

            // "Show me every booking the assistant made", which is the first
            // thing anybody asks after an automated booking goes wrong.
            $table->index(['tool', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_tool_calls');
        Schema::dropIfExists('ai_runs');
    }
};
