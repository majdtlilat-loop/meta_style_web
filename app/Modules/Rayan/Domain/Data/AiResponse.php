<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * One provider turn: text, tool requests, tokens, and whether it worked.
 *
 * An adapter REPORTS. It does not decide whether to hand off, whether to retry,
 * or whether a refusal is acceptable — those are the run loop's decisions, and
 * keeping them out of the adapter is what lets a second provider be added
 * without re-litigating any of them (docs/27-RAYAN.md §6).
 *
 * A failure is a RETURNED VALUE, not an exception, for the same reason a send
 * result is one in the WhatsApp adapter: the run has to record what happened,
 * meter it, and hand the conversation to a person. An exception thrown from
 * deep inside an adapter makes all three harder, and carries a provider message
 * that can echo customer input.
 *
 * Deliberately NOT a `{@see}` on that class. RAYAN sits BELOW the channel, and
 * Pint turns a doc reference into a real import — which would invert the one
 * dependency this whole boundary rests on. The architecture test caught exactly
 * that here (docs/27-RAYAN.md §4).
 */
final readonly class AiResponse
{
    /**
     * @param  list<AiToolCallRequest>  $toolCalls
     */
    private function __construct(
        public bool $ok,
        public ?string $text,
        public array $toolCalls,
        public AiTokenUsage $usage,
        /** Safe code: `timeout`, `rate_limited`, `provider_error`, `truncated`. */
        public ?string $failureCode = null,
    ) {}

    /**
     * @param  list<AiToolCallRequest>  $toolCalls
     */
    public static function answered(?string $text, array $toolCalls, AiTokenUsage $usage): self
    {
        return new self(true, $text, $toolCalls, $usage);
    }

    /**
     * The provider did not produce a usable turn.
     *
     * Usage is still carried where the provider reported it: a request that
     * timed out after the model had generated tokens COST those tokens, and a
     * center should see them (docs/26-USAGE-QUOTAS.md §6).
     */
    public static function failed(string $failureCode, ?AiTokenUsage $usage = null): self
    {
        return new self(false, null, [], $usage ?? AiTokenUsage::unknown(), $failureCode);
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
