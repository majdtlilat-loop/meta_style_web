<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * One item in the transcript sent to the provider.
 *
 * ## Why the transcript is a list of ITEMS, not a list of messages
 *
 * Because a tool-using turn is not two messages. It is: the customer said this,
 * the model asked to run that tool with these arguments, the tool answered
 * this, and only then did the model speak. All four have to go back on the NEXT
 * request or the model has no idea what it already did — and would cheerfully
 * ask for the same booking again (docs/27-RAYAN.md §6).
 *
 * Four kinds, provider-neutral. OpenAI's Responses API happens to accept almost
 * exactly this shape (`message`, `function_call`, `function_call_output`), but
 * the adapter still translates, so a provider that models tool calls
 * differently needs no change above it.
 *
 * ## Stateless by construction
 *
 * The whole transcript travels on every request. Nothing relies on the provider
 * remembering anything, which is what lets provider-side storage be switched
 * OFF — the request is self-contained, so there is nothing to lose by not
 * retaining it (§10).
 */
final readonly class AiTurnItem
{
    private function __construct(
        /** `user` · `assistant` · `tool_call` · `tool_output` */
        public string $kind,
        public string $text = '',
        public ?string $callId = null,
        public ?string $toolName = null,
        /** @var array<string, mixed> */
        public array $arguments = [],
    ) {}

    public static function user(string $text): self
    {
        return new self('user', $text);
    }

    public static function assistant(string $text): self
    {
        return new self('assistant', $text);
    }

    /**
     * The model's own earlier request, echoed back so it knows what it asked.
     *
     * @param  array<string, mixed>  $arguments
     */
    public static function toolCall(string $callId, string $toolName, array $arguments): self
    {
        return new self('tool_call', '', $callId, $toolName, $arguments);
    }

    /**
     * What the tool answered — already allow-listed by the handler.
     */
    public static function toolOutput(string $callId, string $json): self
    {
        return new self('tool_output', $json, $callId);
    }
}
