<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * What a tool handler produced, on its way back to the model.
 *
 * ## A refusal is DATA, not an exception
 *
 * When a tool refuses — the center does not own the feature, the booking is not
 * this customer's, the slot is gone — the model is TOLD, in a short safe
 * sentence, and gets to carry on the conversation sensibly ("that time has just
 * been taken; would 4pm work?"). Throwing would end the run and leave the
 * customer with nothing (docs/27-RAYAN.md §12).
 *
 * What a refusal never contains is why in operational terms. "You may not work
 * in that branch", a permission code, a stack trace or another customer's name
 * would all travel to an external provider and then to a customer's phone.
 *
 * ## `payload` is what leaves the building
 *
 * It is serialised and sent to the model provider, so it is an ALLOW-LIST built
 * by the handler — never a model's `toArray()`. That rule is the only thing
 * standing between a new database column and a customer's phone (§10).
 */
final readonly class ToolResult
{
    private function __construct(
        public bool $ok,
        /** @var array<string, mixed> */
        public array $payload,
        /** A short safe code for the tool-call row: `not_owned`, `not_entitled`. */
        public ?string $refusalCode = null,
        /** What the model is told, in one sentence. Never operational detail. */
        public ?string $message = null,
        /** Resolutions to carry back to the conversation, never model-supplied. */
        public ?int $resolvedCustomerId = null,
        public ?int $resolvedBranchId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function ok(
        array $payload,
        ?int $resolvedCustomerId = null,
        ?int $resolvedBranchId = null,
    ): self {
        return new self(true, $payload, null, null, $resolvedCustomerId, $resolvedBranchId);
    }

    public static function refused(string $code, string $message): self
    {
        return new self(false, [], $code, $message);
    }

    /**
     * What is actually serialised for the provider.
     *
     * @return array<string, mixed>
     */
    public function forModel(): array
    {
        return $this->ok
            ? ['ok' => true] + $this->payload
            : ['ok' => false, 'reason' => $this->message];
    }
}
