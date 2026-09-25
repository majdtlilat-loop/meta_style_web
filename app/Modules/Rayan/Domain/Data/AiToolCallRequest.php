<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * The model asking for a tool to be run.
 *
 * A REQUEST, and the word is chosen. Nothing about this has been authorised,
 * validated or believed: `name` may be a tool that does not exist, and
 * `arguments` may be anything at all, including fields no schema declared and
 * values crafted by whoever is messaging the center
 * (docs/27-RAYAN.md §§11–13).
 *
 * `callId` is the provider's correlation id — OpenAI's `call_id` — and is how
 * the result is returned to the right request when a model asks for several at
 * once.
 */
final readonly class AiToolCallRequest
{
    /**
     * @param  array<string, mixed>  $arguments  UNVALIDATED
     */
    public function __construct(
        public string $callId,
        public string $name,
        public array $arguments = [],
    ) {}
}
