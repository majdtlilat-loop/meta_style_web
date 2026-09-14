<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Events;

/**
 * A stage reached a terminal status: completed, or skipped.
 *
 * ONE EVENT FOR BOTH, because the only listener cares about the same thing
 * either way — there is nothing left to wait for, so the ticket is finished.
 * `$status` carries which it was for anybody who needs the difference
 * (docs/17-QUEUE.md §12).
 *
 * Note the asymmetry with a queue SKIP, which is a completely different thing:
 * a stage is skipped when the customer declines the service, and a ticket is
 * skipped when they do not answer their number being called. One ends the work;
 * the other is still waiting for them (§15).
 */
final readonly class JourneyStageSettled
{
    public function __construct(
        public int $stageId,
        public int $journeyId,
        public int $branchId,
        public string $status,
    ) {}
}
