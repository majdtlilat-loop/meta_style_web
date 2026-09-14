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
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\ServiceJourney\Application\JourneySnapshot;
use App\Modules\ServiceJourney\Domain\Enums\JourneySource;
use App\Modules\ServiceJourney\Domain\Enums\JourneyStatus;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Exceptions\JourneyFailed;
use App\Modules\ServiceJourney\Domain\Models\JourneyStage;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The customer walked in. Start their visit.
 *
 * Creates the {@see ServiceJourney} if it does not exist and derives one stage
 * per booked service, in the order they were booked, each carrying the
 * DEPARTMENT of the service it came from (docs/13-ROADMAP.md Phase 7 §§16, 21).
 *
 * ## The appointment's status does NOT change
 *
 * There is no `arrived` appointment status and there is not going to be.
 * Arrival is an operational fact about a visit, and the appointment's lifecycle
 * is about the reservation — it stays `booked` or `confirmed` until somebody
 * completes, cancels or no-shows it through the Booking Action that owns those
 * transitions (§16, §20).
 *
 * ## Idempotent, and not merely by luck
 *
 * A host double-clicks. Two tablets check the same customer in a second apart.
 * Both must end with ONE journey and neither may see a duplicate-key error.
 *
 * Three layers, in order of how often each fires:
 *
 *   1. a read first, which handles every ordinary repeat;
 *   2. the unique index on `appointment_id`, which is the actual guarantee;
 *   3. a catch that re-reads and returns the winner's journey, so the loser of
 *      a genuine race gets the canonical row rather than a 500.
 *
 * The read alone would be a check-then-insert race. The index alone would
 * surface as an error page. Both, and the caller cannot tell there was a race
 * at all (Phase 7 §48 and corrections §3).
 */
final class CheckInAppointment
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        Appointment $appointment,
        User $actingUser,
        ?CarbonImmutable $now = null,
    ): ServiceJourney {
        // Journey has no entitlement of its own: it is the operational half of
        // booking, and a center that owns one owns the other (§42).
        $this->entitlements->ensure('booking');

        // UTC, explicitly. "Store UTC, compute in the branch timezone" is the
        // rule (CLAUDE.md), and an Action that stores whichever zone its caller
        // happened to be holding writes a wall clock three hours out without
        // anything failing — the values look plausible and sort wrongly.
        $now = ($now ?? CarbonImmutable::now())->utc();

        $this->authorize($appointment, $actingUser);

        $existing = $this->find($appointment);

        if ($existing instanceof ServiceJourney) {
            return $existing;
        }

        if ($appointment->isTerminal()) {
            throw JourneyFailed::policy(
                'That appointment is already finished or cancelled, so nobody can be checked in against it.',
                ['status' => $appointment->status->value],
            );
        }

        try {
            /** @var ServiceJourney $journey */
            $journey = DB::connection('tenant')->transaction(
                fn (): ServiceJourney => $this->start($appointment, $actingUser, $now)
            );
        } catch (UniqueConstraintViolationException) {
            // Another request won. Its journey is the canonical one; returning
            // it is indistinguishable from having been the winner, which is
            // exactly what a double-clicked button should experience.
            $winner = $this->find($appointment);

            if ($winner instanceof ServiceJourney) {
                return $winner;
            }

            throw JourneyFailed::policy('That visit could not be started. Please try again.');
        }

        $this->audit->record(new AuditEvent(
            action: 'journey.checked_in',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceJourney::class,
            targetId: $journey->uuid,
            targetLabel: $appointment->customer()->value('name'),
            after: JourneySnapshot::of($journey),
            meta: ['appointment_uuid' => $appointment->uuid],
        ));

        return $journey;
    }

    private function find(Appointment $appointment): ?ServiceJourney
    {
        return ServiceJourney::query()
            ->with('stages')
            ->where('appointment_id', $appointment->getKey())
            ->first();
    }

    /**
     * @throws JourneyFailed
     */
    private function start(Appointment $appointment, User $actingUser, CarbonImmutable $now): ServiceJourney
    {
        /** @var list<AppointmentItem> $items */
        $items = $appointment->items()
            // `service` for the department. Lazy-loading it would be a query
            // per booked service inside the transaction.
            ->with('service')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->all();

        if ($items === []) {
            throw JourneyFailed::policy('That appointment has no services, so there is nothing to start.');
        }

        /** @var ServiceJourney $journey */
        $journey = ServiceJourney::query()->create([
            'appointment_id' => $appointment->getKey(),
            /*
             * Stated, not left to the column default. The walk-in half of the
             * invariant leaves `appointment_id` null and fills customer and
             * branch instead; writing the source at both ends keeps the two
             * paths readable side by side (docs/16-JOURNEY-RESOURCES.md §22).
             */
            'source' => JourneySource::Appointment,
            'status' => JourneyStatus::Active,
            'arrived_at' => $now,
            'created_by_type' => 'staff',
            'created_by_id' => $actingUser->uuid,
            'created_by_label' => $actingUser->name,
        ]);

        foreach ($items as $position => $item) {
            JourneyStage::query()->create([
                'service_journey_id' => $journey->getKey(),
                'appointment_item_id' => $item->getKey(),
                'position' => $position,
                /*
                 * The service's DEPARTMENT — Hair, Laser, Hammam — snapshotted
                 * here so re-organising the center later does not rewrite what
                 * happened. Never `service_category_id`: a menu heading groups
                 * services for a customer browsing prices and routes nobody
                 * (ADR-037, §22).
                 */
                'department_id' => $item->service?->department_id,
                /*
                 * The ACTUAL employee starts as the booked one, which is what
                 * usually happens. It is a copy, not a reference: reassigning
                 * it later must not rewrite the booking (§18, §24).
                 */
                'employee_id' => $item->employee_id,
                'status' => StageStatus::Waiting,
                'waiting_started_at' => $now,
            ]);
        }

        return $journey->load('stages');
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Appointment $appointment, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::JourneyManage)) {
            throw new AuthorizationException('You may not manage visits.');
        }

        // Permission and branch scope, both. A host at one branch must not
        // check somebody in at another (docs/06 §5).
        if (! $actingUser->canAccessBranch((int) $appointment->branch_id)) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
