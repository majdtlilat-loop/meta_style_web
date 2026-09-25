<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Contracts;

use App\Modules\Rayan\Domain\Data\AiCapabilities;
use App\Modules\Rayan\Domain\Data\AiResponse;
use App\Modules\Rayan\Domain\Data\AiTurnItem;
use App\Modules\Rayan\Domain\Data\ToolDefinition;

/**
 * One AI provider, translated. No business rules, no conversation state.
 *
 * The adapter's whole job is: take a transcript plus a list of tool
 * definitions, ask the provider for the next turn, and report what came back.
 * It does not decide whether to call a tool again, when to stop, whether to
 * hand off, or what a refusal means — all of which belong to the run loop, and
 * none of which should have to be rewritten to add a second provider
 * (docs/27-RAYAN.md §6).
 *
 * ## What an adapter must never do
 *
 * - send anything beyond the transcript and tools it was given. It never adds
 *   the verified phone number, a customer id, a tenant identifier or an
 *   internal id: the curation happened above it and must not be undone here
 *   (§10);
 * - enable provider-side conversation storage or history. Every request is
 *   STATELESS and carries its own transcript, so nothing accumulates in an
 *   account Meta Style does not control (§10);
 * - log a request body, a response body or an `Authorization` header;
 * - throw for an ordinary failure. A timeout, a rate limit, a refusal and a
 *   malformed answer are all {@see AiResponse::failed()} — the run has to
 *   record and meter them, and the customer has to reach a human (§16);
 * - accept a base URL, a host or a model id from tenant data. Both come from
 *   platform configuration, and a tenant may only choose from an approved
 *   list (§5).
 */
interface AiProvider
{
    /** The registry code stored on runs: `openai`. */
    public function code(): string;

    public function displayName(): string;

    public function capabilities(): AiCapabilities;

    /**
     * Produces the next turn.
     *
     * @param  string  $model  an id the caller has already checked against
     *                         {@see AiCapabilities::allowsModel()}
     * @param  string  $instructions  the system prompt. Business tone and
     *                                information ONLY — never a security or
     *                                authorization rule, because a prompt is
     *                                not an enforcement mechanism and anything
     *                                stated in one can be argued with (§17)
     * @param  list<AiTurnItem>  $transcript  oldest first
     * @param  list<ToolDefinition>  $tools  the allow-list, in full
     * @param  int  $maxOutputTokens  a hard ceiling for this turn (§15)
     */
    public function respond(
        string $model,
        string $instructions,
        array $transcript,
        array $tools,
        int $maxOutputTokens,
    ): AiResponse;
}
