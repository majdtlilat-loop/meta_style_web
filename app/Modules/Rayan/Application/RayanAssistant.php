<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Application;

use App\Kernel\Usage\Exceptions\QuotaExceeded;
use App\Kernel\Usage\Usage;
use App\Modules\Rayan\Contracts\AiProvider;
use App\Modules\Rayan\Contracts\Assistant;
use App\Modules\Rayan\Domain\Data\AiResponse;
use App\Modules\Rayan\Domain\Data\AiToolCallRequest;
use App\Modules\Rayan\Domain\Data\AiTurnItem;
use App\Modules\Rayan\Domain\Data\AssistantReply;
use App\Modules\Rayan\Domain\Data\AssistantRequest;
use App\Modules\Rayan\Domain\Data\ConversationTurn;
use App\Modules\Rayan\Domain\Data\ToolContext;
use App\Modules\Rayan\Domain\Data\ToolResult;
use App\Modules\Rayan\Domain\Enums\AiRunStatus;
use App\Modules\Rayan\Domain\Enums\AiUsage;
use App\Modules\Rayan\Domain\Models\AiRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * The bounded agent loop. Ask the model, run what it asks for, ask again — and
 * stop.
 *
 * ## Termination is guaranteed, four ways over (docs/27-RAYAN.md §15)
 *
 *     max_turns          how many times the model may be asked
 *     max_tool_calls     across the WHOLE run, not per turn — which is what
 *                        catches a model asking the same thing six times
 *     max_output_tokens  per request, enforced by the provider
 *     max_seconds        wall clock for the whole run, checked between turns
 *
 * There is no configuration of those four that produces an unbounded loop, and
 * there is no path out of the `for` below that is not one of them. That is the
 * property, and it is deliberately not left to the model's good sense.
 *
 * ## The quota is spent BEFORE the provider is called
 *
 * Atomically, through `Kernel\Usage`. Two simultaneous messages competing for a
 * center's last allowed run cannot both get it — one consumes, the other is
 * refused and hands off. Spending afterwards would let both through
 * (docs/26-USAGE-QUOTAS.md §5).
 *
 * One RUN is one unit, however many turns it takes. A customer's message costs
 * the center one run whether the model answered immediately or called three
 * tools first, which is the unit a center can actually reason about.
 *
 * ## Every failure ends in a human, never in silence
 *
 * A provider outage, a timeout, a refusal, a truncated answer, an exhausted
 * allowance, a loop that hit its ceiling — all of them return
 * {@see AssistantReply::handOff()}. The customer's message is already stored by
 * the channel, and a person picks it up. Nothing here ever invents an answer to
 * cover a failure (§16).
 */
final class RayanAssistant implements Assistant
{
    public function __construct(
        private readonly RayanSettings $settings,
        private readonly AiProviderRegistry $providers,
        private readonly ToolRegistry $tools,
        private readonly Usage $usage,
        private readonly RayanPrompt $prompt,
    ) {}

    public function answer(AssistantRequest $request): AssistantReply
    {
        /*
         * The center switched its assistant OFF (docs/27-RAYAN.md §5). Owning
         * `rayan_ai` and using it are separate questions; the answer here is the
         * same quiet hand-off an unowned assistant gets. The customer's message
         * is already stored, a person picks it up, and nothing is run or
         * metered.
         */
        if (! $this->settings->enabled()) {
            return AssistantReply::handOff('assistant_disabled');
        }

        $provider = $this->providers->get($this->settings->provider());

        if (! $provider->capabilities()->available) {
            /*
             * No API key deployed. An honest, quiet hand-off — not an error at
             * a customer, and not a fabricated reply. This is also exactly how
             * the test suite runs, which is why it is a first-class path rather
             * than an afterthought.
             */
            return AssistantReply::handOff('assistant_unavailable');
        }

        $model = $this->settings->model($provider->capabilities());
        $limits = $this->settings->limits();

        $run = $this->startRun($request, $provider->code(), $model);

        try {
            return $this->loop($request, $provider, $model, $limits, $run);
        } catch (QuotaExceeded) {
            /*
             * Lost the race for the last allowed run. Recorded as `exhausted`,
             * which is the one status that did NOT reach the provider and so is
             * not metered as a provider cost (§7).
             */
            $this->finish($run, AiRunStatus::Exhausted, 'quota');

            return AssistantReply::handOff('quota');
        } catch (Throwable $e) {
            // A genuine fault. Reported for an engineer; the conversation still
            // reaches a person.
            report($e);

            $this->finish($run, AiRunStatus::Failed, 'internal');
            $this->meterFailure();

            return AssistantReply::handOff('failed');
        }
    }

    /**
     * @param  array{max_turns: int, max_tool_calls: int, max_output_tokens: int, max_seconds: int}  $limits
     *
     * @throws QuotaExceeded
     */
    private function loop(
        AssistantRequest $request,
        AiProvider $provider,
        string $model,
        array $limits,
        AiRun $run,
    ): AssistantReply {
        /*
         * Spent up front, keyed on the RUN's uuid so a replayed inbound message
         * — which would not reach here anyway, the webhook fingerprint stops it
         * — could never double-charge (§5).
         */
        $this->usage->consume(AiUsage::Runs->code(), 'ai_run', $run->uuid, 1, $provider->code(), $model);

        $transcript = $this->transcript($request);
        $context = new ToolContext(
            conversationUuid: $request->conversationUuid,
            locale: $request->locale,
            customerId: $request->customerId,
            branchId: $request->branchId,
            verifiedPhone: $request->verifiedPhone,
        );

        $instructions = $this->prompt->build($request->locale);
        $definitions = $this->tools->definitions();

        $deadline = CarbonImmutable::now()->addSeconds($limits['max_seconds']);

        $toolCallsUsed = 0;
        $turns = 0;
        $resolvedCustomerId = null;
        $resolvedBranchId = null;

        /*
         * Results already produced in THIS run, keyed by tool + arguments.
         *
         * The idempotency §31 asks for, tied to the run. A model that asks for
         * the same booking twice — because it did not notice the first answer —
         * gets the first result back rather than a second appointment. Cheap,
         * in-memory, and exactly as long-lived as the run it protects.
         *
         * @var array<string, ToolResult> $alreadyRun
         */
        $alreadyRun = [];

        for ($turns = 1; $turns <= $limits['max_turns']; $turns++) {
            if (CarbonImmutable::now()->greaterThan($deadline)) {
                // Four requests that each took 29 seconds are four successes
                // and one customer who waited two minutes.
                $this->finish($run, AiRunStatus::Failed, 'wall_clock', $turns, $toolCallsUsed);
                $this->meterFailure();

                return AssistantReply::handOff('failed');
            }

            $response = $provider->respond(
                $model,
                $instructions,
                $transcript,
                $definitions,
                $limits['max_output_tokens'],
            );

            $this->meterTokens($run, $response, $provider->code(), $model);

            if (! $response->ok) {
                $this->finish($run, AiRunStatus::Failed, $response->failureCode, $turns, $toolCallsUsed);
                $this->meterFailure();

                return AssistantReply::handOff('failed');
            }

            if (! $response->wantsTools()) {
                $this->finish($run, AiRunStatus::Completed, null, $turns, $toolCallsUsed);

                return $response->text === null
                    // A turn with nothing to say and nothing to do. Handed off
                    // rather than answered with silence.
                    ? AssistantReply::handOff('empty')
                    : AssistantReply::say($response->text, $resolvedBranchId, $resolvedCustomerId);
            }

            if ($toolCallsUsed + count($response->toolCalls) > $limits['max_tool_calls']) {
                $this->finish($run, AiRunStatus::Refused, 'max_tool_calls', $turns, $toolCallsUsed);

                return AssistantReply::handOff('too_complex');
            }

            /*
             * The model's own request goes back on the transcript BEFORE its
             * result, or the next turn has no idea what it asked for and will
             * cheerfully ask again.
             */
            foreach ($response->toolCalls as $call) {
                $transcript[] = AiTurnItem::toolCall($call->callId, $call->name, $call->arguments);

                $result = $this->run($call, $context, $alreadyRun);
                $toolCallsUsed++;

                $this->recordToolCall($run, $call, $result);

                if ($result->resolvedCustomerId !== null) {
                    $resolvedCustomerId = $result->resolvedCustomerId;
                    // Later tools in this same turn now know who they are
                    // talking to — from what the engine resolved, never from
                    // the model (§13).
                    $context = $context->withCustomer($result->resolvedCustomerId);
                }

                if ($result->resolvedBranchId !== null) {
                    $resolvedBranchId = $result->resolvedBranchId;
                    $context = $context->withBranch($result->resolvedBranchId);
                }

                $transcript[] = AiTurnItem::toolOutput(
                    $call->callId,
                    (string) json_encode($result->forModel()),
                );
            }
        }

        /*
         * Out of turns with the model still wanting tools. Not an error — a
         * conversation this involved genuinely belongs to a person.
         */
        $this->finish($run, AiRunStatus::Refused, 'max_turns', $limits['max_turns'], $toolCallsUsed);

        return AssistantReply::handOff('too_complex');
    }

    /**
     * Runs one tool call, or returns the result an identical one already gave.
     *
     * @param  array<string, ToolResult>  $alreadyRun
     */
    private function run(AiToolCallRequest $call, ToolContext $context, array &$alreadyRun): ToolResult
    {
        // Sorted, so argument ORDER cannot make two identical calls look
        // different.
        $arguments = $call->arguments;
        ksort($arguments);

        $signature = $call->name.'|'.json_encode($arguments);

        if (isset($alreadyRun[$signature])) {
            return $alreadyRun[$signature];
        }

        $result = $this->tools->execute($call, $context);

        return $alreadyRun[$signature] = $result;
    }

    /**
     * The conversation so far, as provider-neutral items.
     *
     * @return list<AiTurnItem>
     */
    private function transcript(AssistantRequest $request): array
    {
        return array_map(
            static fn (ConversationTurn $turn): AiTurnItem => $turn->isFromCustomer()
                ? AiTurnItem::user($turn->text)
                : AiTurnItem::assistant($turn->text),
            $request->history,
        );
    }

    private function startRun(AssistantRequest $request, string $provider, string $model): AiRun
    {
        /** @var AiRun $run */
        $run = AiRun::query()->create([
            'conversation_id' => $request->conversationId,
            'provider' => $provider,
            'model' => $model,
            'status' => AiRunStatus::Failed,
            'started_at' => CarbonImmutable::now()->utc(),
        ]);

        /*
         * Created as `failed`, and corrected on the way out.
         *
         * So a process that dies mid-run leaves a row that says it failed,
         * which is true, rather than one that says it is still running forever
         * or — worse — one that says it completed (§7).
         */
        return $run;
    }

    private function finish(
        AiRun $run,
        AiRunStatus $status,
        ?string $failureCode = null,
        int $turns = 0,
        int $toolCalls = 0,
    ): void {
        $run->forceFill([
            'status' => $status,
            'failure_code' => $failureCode,
            'turns' => $turns,
            'tool_calls' => $toolCalls,
            'finished_at' => CarbonImmutable::now()->utc(),
        ])->save();
    }

    private function recordToolCall(AiRun $run, AiToolCallRequest $call, ToolResult $result): void
    {
        $run->toolCalls()->create([
            'tool' => $call->name,
            'provider_call_id' => $call->callId,
            // Already allow-listed to the tool's declared properties by the
            // registry, so an invented field never reaches this table.
            'arguments' => $call->arguments,
            'result' => $result->ok ? 'ok' : 'refused',
            'refusal_code' => $result->refusalCode,
        ]);
    }

    /**
     * Tokens, as the provider reported them. Never estimated (§7).
     */
    private function meterTokens(AiRun $run, AiResponse $response, string $provider, string $model): void
    {
        $usage = $response->usage;

        if (! $usage->hasAny()) {
            return;
        }

        $run->forceFill([
            'input_tokens' => ($run->input_tokens ?? 0) + ($usage->input ?? 0),
            'output_tokens' => ($run->output_tokens ?? 0) + ($usage->output ?? 0),
            'total_tokens' => ($run->total_tokens ?? 0) + ($usage->total ?? 0),
        ])->save();

        /*
         * METERED, not consumed: tokens refuse nothing. The source key includes
         * the turn number, because one run legitimately reports usage several
         * times and each is a distinct fact (docs/26-USAGE-QUOTAS.md §3).
         */
        $this->safely(fn () => $this->usage->meter(
            AiUsage::InputTokens->code(),
            'ai_run_turn',
            $run->uuid.':'.$run->turns.':in',
            $usage->input ?? 0,
            $provider,
            $model,
        ));

        $this->safely(fn () => $this->usage->meter(
            AiUsage::OutputTokens->code(),
            'ai_run_turn',
            $run->uuid.':'.$run->turns.':out',
            $usage->output ?? 0,
            $provider,
            $model,
        ));
    }

    private function meterFailure(): void
    {
        $this->safely(fn () => $this->usage->meter(AiUsage::FailedRuns->code(), 'ai_run', (string) Str::uuid()));
    }

    /**
     * Metering must never break a conversation.
     *
     * A usage table that is briefly unavailable is an analytics problem; the
     * customer is still owed an answer. Reported and swallowed (ADR-067).
     *
     * @param  callable(): void  $work
     */
    private function safely(callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
