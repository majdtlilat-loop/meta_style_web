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
use App\Kernel\Money\Currency;
use App\Kernel\Privacy\Fingerprint;
use App\Modules\Booking\Application\Actions\ResolveBookingCustomer;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\ServiceJourney\Application\JourneySnapshot;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
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
 * A visit that was never booked.
 *
 * ## No fake appointment
 *
 * The cheap way to support walk-ins is to write an appointment for the current
 * minute and check it in. It would have put reservations into the booking
 * tables for reservations nobody made, and every availability query, calendar
 * screen and later report would spend the rest of the product's life filtering
 * them out. Phase 8 made `appointment_id` nullable instead
 * (docs/16-JOURNEY-RESOURCES.md §22, docs/17-QUEUE.md §2).
 *
 * So a walk-in journey carries the two facts it can no longer derive — the
 * customer and the branch — and each stage carries its own service snapshot.
 * Nothing else about it is different: the same stages, the same statuses, the
 * same resource rules, the same board.
 *
 * ## The customer is resolved, never duplicated
 *
 * A phone goes through {@see ResolveBookingCustomer}, which is where the
 * center's identity rule lives: normalise, and if that number already belongs
 * to somebody, USE that record. A walk-in must not create a second copy of a
 * regular customer just because they came in without booking (Phase 6 §16).
 *
 * A walk-in with no phone at all gets a new record with a name and nothing
 * else, which is the honest representation of what the desk actually knows. It
 * never creates a `CustomerAccount`: a customer and a login are different
 * things, and walking in is not registering (ADR-041, docs/17-QUEUE.md §23).
 *
 * ## Idempotent, by database invariant
 *
 * Reception double-clicks. The request carries a token, `service_journeys`
 * has a unique index on it, and the loser of the race is handed the visit the
 * winner created — indistinguishable from having been the winner, which is what
 * a double-clicked button should experience (§11).
 */
final class CreateWalkInVisit
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly ResolveBookingCustomer $customers,
        private readonly Audit $audit,
    ) {}

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    public function __invoke(
        WalkInRequest $request,
        User $actingUser,
        ?CarbonImmutable $now = null,
    ): ServiceJourney {
        /*
         * A walk-in is a JOURNEY, so it is gated on `booking` like the rest of
         * the operational module — NOT on the queue. A center without the queue
         * entitlement can still take somebody who walked in; it just cannot
         * hand them a number (docs/17-QUEUE.md §19).
         */
        $this->entitlements->ensure('booking');

        // UTC, explicitly. "Store UTC, compute in the branch timezone" is the
        // rule (CLAUDE.md), and an Action that stores whichever zone its caller
        // happened to be holding writes a wall clock three hours out without
        // anything failing.
        $now = ($now ?? CarbonImmutable::now())->utc();

        $branch = $this->branch($request->branchUuid);

        $this->authorize($branch, $actingUser);

        $existing = $this->findByToken($request->idempotencyToken);

        if ($existing instanceof ServiceJourney) {
            return $existing;
        }

        $services = $this->services($request->serviceUuids, $branch);
        $customer = $this->customer($request, $actingUser);
        $employee = $this->employee($request->employeeUuid, $services, $branch);

        try {
            /** @var ServiceJourney $journey */
            $journey = DB::connection('tenant')->transaction(
                fn (): ServiceJourney => $this->create($request, $branch, $customer, $services, $employee, $actingUser, $now)
            );
        } catch (UniqueConstraintViolationException) {
            // The other click won. Its visit is the canonical one.
            $winner = $this->findByToken($request->idempotencyToken);

            if ($winner instanceof ServiceJourney) {
                return $winner;
            }

            throw JourneyFailed::policy('That visit could not be started. Please try again.');
        }

        $this->audit->record(new AuditEvent(
            action: 'journey.walk_in_created',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceJourney::class,
            targetId: $journey->uuid,
            targetLabel: $customer->name,
            after: JourneySnapshot::of($journey),
            meta: [
                'branch_id' => $branch->getKey(),
                'services' => count($services),
                // FINGERPRINTED, never the number. An audit row is read by
                // people who may not hold `customer.contact.view`
                // (docs/08-AUDIT-SECURITY.md).
                'customer' => Fingerprint::of((string) $customer->uuid),
            ],
        ));

        return $journey;
    }

    /**
     * @param  list<Service>  $services
     */
    private function create(
        WalkInRequest $request,
        Branch $branch,
        Customer $customer,
        array $services,
        ?Employee $employee,
        User $actingUser,
        CarbonImmutable $now,
    ): ServiceJourney {
        /** @var ServiceJourney $journey */
        $journey = ServiceJourney::query()->create([
            // THE INVARIANT: no appointment, and the context it replaces.
            'appointment_id' => null,
            'customer_id' => $customer->getKey(),
            'branch_id' => $branch->getKey(),
            'source' => JourneySource::WalkIn,
            'idempotency_token' => $request->idempotencyToken,
            'status' => JourneyStatus::Active,
            // A walk-in arrives at the moment it is created. There was no
            // earlier appointment to be late for.
            'arrived_at' => $now,
            'created_by_type' => 'staff',
            'created_by_id' => $actingUser->uuid,
            'created_by_label' => $actingUser->name,
        ]);

        $currency = Currency::default();

        foreach ($services as $position => $service) {
            JourneyStage::query()->create([
                'service_journey_id' => $journey->getKey(),
                'appointment_item_id' => null,
                'service_id' => $service->getKey(),

                /*
                 * SNAPSHOTS, for the same reason `appointment_items` snapshots:
                 * renaming the service, repricing it or changing its duration
                 * must never rewrite a visit that already happened. And the
                 * duration is not decoration — it is the window the resource
                 * admission check runs against (ADR-050).
                 */
                'service_name' => $service->name,
                'duration_minutes' => max(1, (int) $service->duration_minutes),
                'price_minor' => (int) $service->price_minor,
                'currency' => $currency->value,

                'position' => $position,
                // The operational routing axis, never the menu category
                // (ADR-037).
                'department_id' => $service->department_id,
                'employee_id' => $employee?->getKey(),
                'status' => StageStatus::Waiting,
                'waiting_started_at' => $now,
            ]);
        }

        return $journey->load('stages');
    }

    private function findByToken(?string $token): ?ServiceJourney
    {
        if ($token === null) {
            return null;
        }

        return ServiceJourney::query()
            ->with('stages')
            ->where('idempotency_token', $token)
            ->first();
    }

    /**
     * @throws JourneyFailed
     */
    private function branch(string $uuid): Branch
    {
        $branch = Branch::query()->where('uuid', $uuid)->first();

        if (! $branch instanceof Branch) {
            throw JourneyFailed::policy('That branch was not found.');
        }

        return $branch;
    }

    /**
     * The services this visit is here for, in the order they will be performed.
     *
     * @param  list<string>  $uuids
     * @return list<Service>
     *
     * @throws JourneyFailed
     */
    private function services(array $uuids, Branch $branch): array
    {
        if ($uuids === []) {
            throw JourneyFailed::policy('A visit needs at least one service.');
        }

        $services = [];

        foreach ($uuids as $uuid) {
            $service = Service::query()->where('uuid', $uuid)->first();

            if (! $service instanceof Service || $service->isArchived() || ! $service->is_active) {
                throw JourneyFailed::policy('One of those services is not available.');
            }

            /*
             * Phase 4's rule: "available at all branches" is the ABSENCE of
             * pivot rows, so a service restricted to another branch must be
             * refused here rather than silently performed at the wrong one.
             */
            if (! $service->isAvailableAtBranch((int) $branch->getKey())) {
                throw JourneyFailed::policy('That service is not offered at this branch.');
            }

            $services[] = $service;
        }

        return $services;
    }

    /**
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    private function customer(WalkInRequest $request, User $actingUser): Customer
    {
        $actor = BookingActor::staff($actingUser);

        if ($request->customerUuid !== null || $request->phone !== null) {
            /*
             * THE CENTER'S IDENTITY RULE, not a second copy of it. A phone that
             * already belongs to somebody resolves to that person — one person,
             * one record — and a uuid requires `customer.view`, because
             * "start a visit for customer X" against an arbitrary uuid is a
             * read of the customer list by another name (Phase 6 §§16, 30).
             */
            $ref = $request->customerUuid !== null
                ? CustomerRef::existing($request->customerUuid)
                : CustomerRef::details((string) $request->name, (string) $request->phone);

            try {
                return ($this->customers)($ref, $actor);
            } catch (BookingFailed $failure) {
                // Translated so a walk-in surface reports a JOURNEY error. The
                // rule that refused it is the same one; only the module telling
                // the caller about it differs.
                throw JourneyFailed::policy($failure->getMessage());
            }
        }

        return $this->withoutPhone($request, $actingUser);
    }

    /**
     * The walk-in who will not leave a number.
     *
     * A real gap in the booking paths rather than a shortcut around them: every
     * booking form has a phone field and this desk does not. The identity rule
     * is untouched — there is simply no phone to apply it to, and
     * `customers.phone` is nullable with a unique index, which both engines
     * read as "many rows may have none".
     *
     * A NAME IS STILL REQUIRED. The obvious fallback of using the number as the
     * name is what ADR-042 exists to prevent, and with no number there is
     * nothing else to put there.
     *
     * @throws JourneyFailed
     * @throws AuthorizationException
     */
    private function withoutPhone(WalkInRequest $request, User $actingUser): Customer
    {
        if (! $actingUser->hasPermission(Permission::CustomerCreate)) {
            throw new AuthorizationException('You may not add customers.');
        }

        $name = trim((string) $request->name);

        if ($name === '') {
            throw JourneyFailed::policy('A walk-in needs a name.');
        }

        /** @var Customer $customer */
        $customer = Customer::query()->create([
            'name' => $name,
            'phone' => null,
            'phone_display' => null,
            // Created at the desk by a member of staff, which is exactly what
            // `staff` means. No new source case: a walk-in is not a channel.
            'source' => CustomerSource::Staff,
        ]);

        return $customer;
    }

    /**
     * The preferred employee, validated as a reassignment would be.
     *
     * @param  list<Service>  $services
     *
     * @throws JourneyFailed
     */
    private function employee(?string $uuid, array $services, Branch $branch): ?Employee
    {
        if ($uuid === null) {
            // "Whoever is free", which is the normal case and is settled when
            // the service actually starts.
            return null;
        }

        $employee = Employee::query()->where('uuid', $uuid)->first();

        if (! $employee instanceof Employee) {
            throw JourneyFailed::policy('That team member does not exist.');
        }

        if ($employee->status !== EmployeeStatus::Active) {
            throw JourneyFailed::policy('That team member is not active.');
        }

        if (! in_array((int) $branch->getKey(), $employee->branchIds(), true)) {
            throw JourneyFailed::policy('That team member does not work at this branch.');
        }

        foreach ($services as $service) {
            $eligible = DB::connection('tenant')
                ->table('employee_service')
                ->where('employee_id', $employee->getKey())
                ->where('service_id', $service->getKey())
                ->exists();

            if (! $eligible) {
                // Refused, not overridden — the same answer a mid-visit
                // reassignment gives, and for the same reason (Phase 7 §24).
                throw JourneyFailed::policy('That team member is not qualified for one of those services.');
            }
        }

        return $employee;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(Branch $branch, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::JourneyWalkInCreate)) {
            throw new AuthorizationException('You may not start a walk-in visit.');
        }

        // Permission AND branch scope, both. A host at one branch must not
        // start a visit at another (docs/06 §5).
        if (! $actingUser->canAccessBranch((int) $branch->getKey())) {
            throw new AuthorizationException('You may not work in that branch.');
        }
    }
}
