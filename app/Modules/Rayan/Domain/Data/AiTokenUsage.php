<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Data;

/**
 * Token counts, AS THE PROVIDER REPORTED THEM.
 *
 * Every field is nullable, and that is the point. If the provider did not
 * return a count, it is UNKNOWN — not zero, and not estimated from the text.
 * An invented token count would show up on a manager's usage screen as a fact
 * and would eventually be reconciled against a provider invoice that says
 * something else (docs/27-RAYAN.md §7, docs/26-USAGE-QUOTAS.md §6).
 *
 * OpenAI's Responses API returns `usage.input_tokens`, `usage.output_tokens`
 * and `usage.total_tokens` (verified 2026-09-20). `total` is read rather than
 * summed, because a provider that bills cached input differently is entitled to
 * report a total that is not the sum.
 */
final readonly class AiTokenUsage
{
    public function __construct(
        public ?int $input = null,
        public ?int $output = null,
        public ?int $total = null,
    ) {}

    public static function unknown(): self
    {
        return new self;
    }

    public function hasAny(): bool
    {
        return $this->input !== null || $this->output !== null || $this->total !== null;
    }
}
