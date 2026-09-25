<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Enums;

/**
 * The metered-resource codes RAYAN reports to `Kernel\Usage`.
 *
 * They live HERE, in the module that produces the usage, so the Kernel never
 * has to know what an AI run is. It validates the code against
 * `config/usage.php` and counts it; the meaning stays this module's business
 * (Phase 13 correction 3, docs/26-USAGE-QUOTAS.md §2).
 *
 * ## Only `Runs` is a hard limit
 *
 * A run is a discrete thing that can be refused BEFORE it starts, so "500 runs
 * a month" is a promise the code can keep. Token counts are only known after
 * the provider answers, which makes a hard monthly token cap a reserve-then-
 * settle problem nobody asked for — so tokens are metered exactly, reported,
 * and refuse nothing. Nothing anywhere claims otherwise (§42).
 */
enum AiUsage: string
{
    /** The enforced one. Consumed atomically before the provider is called. */
    case Runs = 'ai_runs';

    case InputTokens = 'ai_input_tokens';
    case OutputTokens = 'ai_output_tokens';
    case ToolCalls = 'ai_tool_calls';

    /**
     * Runs that broke. Metered separately from the total so a center's failure
     * RATE is visible — the number that says an integration is degrading while
     * the run count still looks healthy.
     */
    case FailedRuns = 'ai_failed_runs';

    public function code(): string
    {
        return $this->value;
    }
}
