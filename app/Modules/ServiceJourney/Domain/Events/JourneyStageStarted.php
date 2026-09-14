<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Domain\Events;

/**
 * A service actually began.
 *
 * ## The one seam that points upward
 *
 * ServiceJourney must not know Queue exists — Booking does not know Journey
 * exists, and the same rule keeps Phase 8 from tangling the two. But a queue
 * ticket has to stop saying "called" the moment the stylist actually starts,
 * or a board and a television disagree about the same customer
 * (docs/04-MODULE-BOUNDARIES.md, docs/17-QUEUE.md §12).
 *
 * So Journey states a FACT and does not care who listens. Queue listens.
 * Nothing else in Journey changes, and a center with no queue at all is
 * unaffected because no listener is doing anything.
 *
 * ## Identifiers, never models
 *
 * The project's event convention (CLAUDE.md): a past-tense fact carrying ids. A
 * listener that wants the row reads it — inside the same transaction, so it
 * reads the state this event is telling it about.
 */
final readonly class JourneyStageStarted
{
    public function __construct(
        public int $stageId,
        public int $journeyId,
        public int $branchId,
    ) {}
}
