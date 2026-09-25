<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Rayan\Contracts\AiProvider;
use App\Modules\Rayan\Domain\Data\AiCapabilities;
use App\Modules\Rayan\Domain\Data\AiResponse;
use App\Modules\Rayan\Domain\Data\AiTokenUsage;
use App\Modules\Rayan\Domain\Data\AiToolCallRequest;

/**
 * A deterministic assistant provider.
 *
 * ## Why a fake rather than a mocked HTTP call
 *
 * Because what these tests are about is the RUN LOOP — bounded turns, bounded
 * tool calls, quota spent before the provider is reached, every failure ending
 * in a human. None of that is about OpenAI's wire format, which has its own
 * contract tests against recorded response shapes.
 *
 * It also means the suite never needs a key and never spends a credit
 * (docs/13-ROADMAP.md Phase 13 §16).
 *
 * Queue up turns with {@see willSay()} and {@see willCall()}; the loop consumes
 * them in order, and anything past the end is a plain answer — so a test that
 * accidentally loops forever fails on the turn ceiling rather than hanging.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<AiResponse> */
    private array $queued = [];

    /** @var list<array{model: string, instructions: string, transcript: array<int, mixed>, tools: array<int, mixed>}> */
    public array $received = [];

    public function __construct(
        private readonly bool $available = true,
        private readonly string $default = 'fake-model',
    ) {}

    public function code(): string
    {
        // The registered provider's code, so a test double replaces the real
        // adapter rather than sitting beside it.
        return 'openai';
    }

    public function displayName(): string
    {
        return 'Fake assistant';
    }

    public function capabilities(): AiCapabilities
    {
        return $this->available
            ? new AiCapabilities(true, true, [$this->default], $this->default)
            : AiCapabilities::unavailable();
    }

    public function willSay(string $text, ?AiTokenUsage $usage = null): self
    {
        $this->queued[] = AiResponse::answered($text, [], $usage ?? new AiTokenUsage(120, 40, 160));

        return $this;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function willCall(string $tool, array $arguments = [], string $callId = 'call_1'): self
    {
        $this->queued[] = AiResponse::answered(
            null,
            [new AiToolCallRequest($callId, $tool, $arguments)],
            new AiTokenUsage(200, 20, 220),
        );

        return $this;
    }

    public function willFail(string $code = 'provider_error'): self
    {
        $this->queued[] = AiResponse::failed($code);

        return $this;
    }

    public function respond(
        string $model,
        string $instructions,
        array $transcript,
        array $tools,
        int $maxOutputTokens,
    ): AiResponse {
        unset($maxOutputTokens);

        $this->received[] = [
            'model' => $model,
            'instructions' => $instructions,
            'transcript' => $transcript,
            'tools' => $tools,
        ];

        return array_shift($this->queued)
            ?? AiResponse::answered('Anything else I can help with?', [], new AiTokenUsage(50, 10, 60));
    }

    public function turns(): int
    {
        return count($this->received);
    }
}
