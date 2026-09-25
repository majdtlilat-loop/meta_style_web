<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

use App\Modules\Rayan\Domain\Enums\RayanTool;

/**
 * One tool, as the provider needs to see it.
 *
 * Provider-neutral: a name, a description, and a JSON Schema for the arguments.
 * The adapter translates it into whatever shape its API wants — for OpenAI's
 * Responses API that is a flat `{type: "function", name, description,
 * parameters, strict}` object, which is NOT the nested shape Chat Completions
 * used (docs/27-RAYAN.md §6).
 *
 * ## The schema is also the validator
 *
 * The same `parameters` that go to the provider are what the registry checks
 * the returned arguments against. One definition, so a tool cannot advertise
 * one contract and accept another — which is how an argument nobody expected
 * ends up reaching a handler (§11).
 */
final readonly class ToolDefinition
{
    /**
     * @param  array<string, array{type: string, description: string, enum?: list<string>}>  $properties
     * @param  list<string>  $required
     */
    public function __construct(
        public RayanTool $tool,
        public string $description,
        public array $properties = [],
        public array $required = [],
    ) {}

    public function name(): string
    {
        return $this->tool->value;
    }

    /**
     * JSON Schema for the arguments.
     *
     * `additionalProperties: false` always. It is required for OpenAI's strict
     * mode, and it is what makes an invented extra field a schema violation the
     * provider itself refuses rather than something a handler has to notice.
     *
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => $this->properties === [] ? new \stdClass : $this->properties,
            'required' => $this->required,
            'additionalProperties' => false,
        ];
    }
}
