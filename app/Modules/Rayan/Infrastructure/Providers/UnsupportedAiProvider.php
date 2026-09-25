<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Infrastructure\Providers;

use App\Modules\Rayan\Contracts\AiProvider;
use App\Modules\Rayan\Domain\Data\AiCapabilities;
use App\Modules\Rayan\Domain\Data\AiResponse;

/**
 * An AI provider Meta Style has NOT implemented.
 *
 * The same honesty valve as the payment and messaging equivalents: a provider
 * that is named but refuses everything is truthful, and a fabricated
 * integration is not. It is also what a stored `provider` code resolves to if a
 * run's adapter is ever withdrawn — so the assistant stops loudly rather than
 * resolving to something else (docs/27-RAYAN.md §6).
 *
 * Phase 13 registers none: OpenAI is the selected provider and is implemented.
 * This exists so adding a second one is an adapter plus a registry line.
 */
final class UnsupportedAiProvider implements AiProvider
{
    public function __construct(
        private readonly string $code,
        private readonly string $displayName,
    ) {}

    public function code(): string
    {
        return $this->code;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function capabilities(): AiCapabilities
    {
        return AiCapabilities::unavailable();
    }

    public function respond(
        string $model,
        string $instructions,
        array $transcript,
        array $tools,
        int $maxOutputTokens,
    ): AiResponse {
        // A returned failure, never a throw: the run records it, meters it and
        // hands the conversation to a person (§16).
        return AiResponse::failed('provider_unsupported');
    }
}
