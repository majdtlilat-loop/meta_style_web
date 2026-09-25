<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Application;

use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;

/**
 * "May this visit be reviewed, and what may be rated in it?"
 *
 * ## A review is about something that happened
 *
 * Not a public form anybody can fill in about a center, which is a ratings
 * board rather than a record of service, and which a competitor can flood in an
 * afternoon. The bar is a COMPLETED journey with at least one COMPLETED stage:
 * the customer arrived, and something was actually performed
 * (docs/22-REVIEWS.md §3).
 *
 * A visit that was abandoned, or one where every stage was skipped, produces no
 * invitation at all. Nothing was done; there is nothing to rate.
 *
 * Payment is deliberately NOT required. A complimentary visit, a service
 * covered entirely by a package and a bill settled next week are all real
 * visits, and tying feedback to money would quietly silence exactly the
 * customers a center most wants to hear from.
 *
 * ## The stages are the menu of what may be rated
 *
 * {@see ratableStages()} is the only list a submission may draw from, and it is
 * read from the visit rather than from the request — so an employee who was
 * merely booked, a service that was skipped, and anything at all from another
 * customer's visit are not on it (§§11–12).
 */
final class ReviewEligibility
{
    /**
     * Whether this visit can be reviewed at all.
     */
    public function isReviewable(ServiceJourney $journey): bool
    {
        return $journey->status === JourneyStatus::Completed
            && $journey->completed_at !== null
            && $this->ratableStages($journey) !== [];
    }

    /**
     * The performed services of this visit, keyed by stage uuid.
     *
     * Completed only. A skipped stage is a service the customer DECLINED, and a
     * waiting one never ran (§12).
     *
     * @return array<string, JourneyStage>
     */
    public function ratableStages(ServiceJourney $journey): array
    {
        /** @var list<JourneyStage> $stages */
        $stages = JourneyStage::query()
            ->where('service_journey_id', $journey->getKey())
            ->where('status', StageStatus::Completed->value)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();

        $byUuid = [];

        foreach ($stages as $stage) {
            $byUuid[$stage->uuid] = $stage;
        }

        return $byUuid;
    }
}
