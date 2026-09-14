<?php

declare(strict_types=1);

namespace App\Modules\ServiceJourney\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Modules\ServiceJourney\Application\JourneySnapshot;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Events\JourneyAborted;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use App\Modules\ServiceJourney\Domain\StageResources;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The visit stopped happening.
 *
 * The customer arrived, was checked in, and then left — an emergency, a change
 * of mind, a wait they were not prepared to sit through. Or the desk called the
 * visit off mid-way.
 *
 * ## Why this state has to exist
 *
 * Without it, such a journey stays `active` for ever. The board shows a
 * customer in the building who went home an hour ago, the rooms they were using
 * stay held, and every future "who is here" query has to special-case a visit
 * whose appointment is cancelled but whose operational record never ended
 * (docs/13-ROADMAP.md Phase 7 corrections §4).
 *
 * ## It does NOT cancel the appointment
 *
 * Aborting records that the operational visit stopped. Whether the booking is
 * cancelled, completed or left alone is a separate decision with its own rules
 * and its own money implications later — and it belongs to the Booking
 * lifecycle. {@see CancelVisit} is the flow that does both, in that order, by
 * calling the Booking Action rather than reimplementing it.
 *
 * ## Rooms are released
 *
 * Every open usage closes, with the abort reason. A customer who has left is
 * not occupying a chair, and leaving the holds open would make the room
 * unbookable until somebody noticed.
 */
final class AbortJourney
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly StageResources $resources,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        ServiceJourney $journey,
        User $actingUser,
        ?string $reason = null,
        ?CarbonImmutable $now = null,
    ): ServiceJourney {
        $this->entitlements->ensure('booking');

        // UTC, explicitly. "Store UTC, compute in the branch timezone" is the
        // rule (CLAUDE.md), and an Action that stores whichever zone its caller
        // happened to be holding writes a wall clock three hours out without
        // anything failing — the values look plausible and sort wrongly.
        $now = ($now ?? CarbonImmutable::now())->utc();

        /*
         * Loaded here rather than assumed. An Action that relies on its caller
         * having eager-loaded the right relations is an Action that issues a
         * query per row the first time somebody calls it from a loop — and
         * `loadMissing` costs nothing when the caller already did the work.
         */
        $journey->loadMissing(['appointment', 'stages']);

        $this->authorize($journey, $actingUser);

        if (! $journey->status->canTransitionTo(JourneyStatus::Aborted)) {
            // A completed visit cannot be abandoned after the fact, and an
            // abandoned one cannot be abandoned twice.
            throw JourneyFailed::invalidTransition(
                "A visit that is {$journey->status->value} cannot be abandoned.",
                ['from' => $journey->status->value],
            );
        }

        $trimmed = $this->reason($reason);
        $before = JourneySnapshot::of($journey);

        DB::connection('tenant')->transaction(function () use ($journey, $trimmed, $now): void {
            foreach ($journey->stages as $stage) {
                /** @var JourneyStage $stage */
                $this->resources->releaseAll($stage, $now, $trimmed);
            }

            $journey->forceFill([
                'status' => JourneyStatus::Aborted,
                'aborted_at' => $now,
                'abort_reason' => $trimmed,
            ])->save();

            // Inside the transaction, so a ticket cannot survive a visit that
            // was abandoned (docs/17-QUEUE.md §39, correction 4).
            Event::dispatch(new JourneyAborted((int) $journey->getKey(), $journey->branchId()));
        });

        $journey->refresh()->load('stages');

        $this->audit->record(new AuditEvent(
            action: 'journey.aborted',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceJourney::class,
            targetId: $journey->uuid,
            before: $before,
            after: JourneySnapshot::of($journey),
            meta: $trimmed === null ? [] : ['reason' => $trimmed],
        ));

        return $journey;
    }

    private function reason(?string $reason): ?string
    {
        if ($reason === null) {
            return null;
        }

        $trimmed = trim($reason);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, 190);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(ServiceJourney $journey, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::JourneyManage)) {
            throw new AuthorizationException('You may not manage visits.');
        }

        $branchId = $journey->branchId();

        if ($branchId > 0 && ! $actingUser->canAccessBranch($branchId)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
