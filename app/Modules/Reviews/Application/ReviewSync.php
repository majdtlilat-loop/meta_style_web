<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Kernel\Database\AfterCommit;
use App\Kernel\Reconciliation\Contracts\Reconciler;
use App\Modules\Reviews\Application\Actions\IssueReviewInvitation;
use App\Modules\Reviews\Domain\Models\ReviewInvitation;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Events\JourneyCompleted;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;

/**
 * The visit is over; the customer gets a way to say how it went.
 *
 * ## The visit comes first
 *
 * `JourneyCompleted` is dispatched inside the completion transaction, and this
 * schedules its work for AFTER that commits (ADR-061). A center whose review
 * table was unavailable for a minute still closed its visits — the alternative,
 * a visit that cannot be finished because feedback could not be arranged, is
 * absurd on a salon floor (docs/22-REVIEWS.md §4).
 *
 * ## And reconciliation is the other half
 *
 * Because a lost after-commit callback is dropped rather than retried, an
 * hourly pass finds completed visits with no invitation and issues them. It is
 * bounded and idempotent, and it issues nothing for a center that no longer
 * owns `reviews`.
 */
final class ReviewSync implements Reconciler
{
    /** Never more than this in one pass, so a backlog cannot stall the hour. */
    public const MAX_PER_RUN = 500;

    public function __construct(
        private readonly IssueReviewInvitation $issue,
        private readonly AfterCommit $afterCommit,
    ) {}

    public function name(): string
    {
        return 'reviews';
    }

    public function handleJourneyCompleted(JourneyCompleted $event): void
    {
        $this->afterCommit->run('reviews.invite_on_journey', function () use ($event): void {
            $this->issue->forJourney($event->journeyId);
        });
    }

    public function reconcile(CarbonImmutable $since): int
    {
        $issued = 0;

        $journeys = ServiceJourney::query()
            ->select('id')
            ->where('status', JourneyStatus::Completed->value)
            ->where('completed_at', '>=', $since)
            ->whereNotIn('id', ReviewInvitation::query()->select('service_journey_id'))
            ->orderBy('completed_at')
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->pluck('id');

        foreach ($journeys as $journeyId) {
            $issued += $this->issue->forJourney((int) $journeyId) === null ? 0 : 1;
        }

        return $issued;
    }
}
