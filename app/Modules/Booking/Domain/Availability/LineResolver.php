<?php

declare(strict_types=1);

namespace App\Modules\Booking\Domain\Availability;

use App\Kernel\Money\Currency;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\ResolvedLine;
use App\Modules\Booking\Domain\Data\ResourceRequirement;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;

/**
 * Turns requested uuids into validated, priced, timed lines.
 *
 * ## Everything a caller sends is a claim
 *
 * A service uuid, a variation uuid, an add-on uuid and an employee uuid all
 * arrive from a client — including an unauthenticated one on the public menu.
 * Each is checked against the catalog rather than trusted:
 *
 *  - the service exists, is active, is not archived, and is offered at THIS
 *    branch;
 *  - the variation belongs to THAT service and is active — a variation uuid
 *    from a different service is refused, not silently applied
 *    (docs/13-ROADMAP.md Phase 6 §19);
 *  - every add-on is currently attached to that service and active — an
 *    arbitrary add-on id cannot be pinned onto a cheaper service (§18);
 *  - the employee, if named, is active, eligible for the service and assigned
 *    to the branch (§5).
 *
 * ## And this is where price and duration are decided
 *
 * Once, here, from the live catalog — and then never re-read. A variation with
 * a null price inherits from its service, which is Phase 4's rule and stays
 * true right up to the moment the booking is written; from then on the
 * snapshot on the appointment item is the answer (ADR-037, §3).
 */
final class LineResolver
{
    /**
     * A visit is one arrival. Anything beyond a working day is a data-entry
     * mistake rather than a layout, and an unbounded offset is an unbounded
     * window to search.
     */
    private const MAX_OFFSET_MINUTES = 16 * 60;

    /** @var array<int, list<ResourceRequirement>> */
    private array $requirements = [];

    /**
     * @param  list<BookingLine>  $lines
     * @return list<ResolvedLine>
     *
     * @throws BookingFailed
     */
    public function resolve(array $lines, Branch $branch, bool $publicChannel): array
    {
        if ($lines === []) {
            throw BookingFailed::policy('A booking needs at least one service.');
        }

        $currency = Currency::default();
        $resolved = [];

        foreach ($lines as $line) {
            $resolved[] = $this->resolveOne($line, $branch, $publicChannel, $currency);
        }

        return $resolved;
    }

    /**
     * @throws BookingFailed
     */
    private function resolveOne(
        BookingLine $line,
        Branch $branch,
        bool $publicChannel,
        Currency $currency,
    ): ResolvedLine {
        $service = $this->service($line->serviceUuid, $branch, $publicChannel);

        $variation = $this->variation($line->variationUuid, $service);

        $addons = $this->addons($line->addonUuids, $service);

        // Base or variation, then every add-on. Resolved once and carried on
        // the DTO, because recomputing it downstream is how the schedule and
        // the invoice come to disagree.
        $duration = $variation instanceof ServiceVariation
            ? $variation->effectiveDurationMinutes($service)
            : $service->duration_minutes;

        $price = $variation instanceof ServiceVariation
            ? $variation->effectivePrice($service, $currency)->minor
            : $service->price_minor;

        foreach ($addons as $addon) {
            $duration += $addon->duration_minutes;
            $price += $addon->price_minor;
        }

        if ($duration <= 0) {
            // A zero-length service cannot be scheduled: it would occupy no
            // time, conflict with nothing, and produce an appointment item
            // whose window is not a window.
            throw BookingFailed::policy('That service has no duration configured and cannot be booked.');
        }

        $employee = $this->employee($line->employeeUuid, $service, $branch);

        return new ResolvedLine(
            service: $service,
            variation: $variation,
            addons: $addons,
            durationMinutes: $duration,
            priceMinor: $price,
            currency: $currency,
            employeeId: $employee?->id,
            selection: $employee instanceof Employee ? EmployeeSelection::Specific : EmployeeSelection::Any,
            note: $line->note,
            resourceRequirements: $this->requirements($service),
            pinnedResourceUuids: $this->pinnedResources($line, $branch, $publicChannel),
            offsetMinutes: $this->offset($line, $publicChannel),
        );
    }

    /**
     * What this service needs in rooms, chairs and devices.
     *
     * Read ONCE, here, and carried on the resolved line — the same reason price
     * and duration are resolved here. A requirement that changes between the
     * availability query and the booking must not be picked up halfway through
     * (docs/13-ROADMAP.md Phase 7 §43).
     *
     * A service with no requirements returns an empty list, which is the
     * ordinary case for a barbershop that never modelled its chairs and the
     * reason resources are additive rather than mandatory.
     *
     * @return list<ResourceRequirement>
     */
    private function requirements(Service $service): array
    {
        $key = (int) $service->getKey();

        if (isset($this->requirements[$key])) {
            return $this->requirements[$key];
        }

        /** @var list<ResourceRequirement> $rows */
        $rows = ServiceResourceRequirement::query()
            ->where('service_id', $key)
            ->orderBy('resource_type_id')
            ->get()
            ->map(static fn (ServiceResourceRequirement $r): ResourceRequirement => new ResourceRequirement(
                (int) $r->resource_type_id,
                $r->quantity,
            ))
            ->all();

        return $this->requirements[$key] = $rows;
    }

    /**
     * Resources the caller named explicitly.
     *
     * A PRIVILEGED CLAIM, like the booking source. A guest choosing Laser
     * Machine 2 by uuid is a guest reading the center's equipment list, so the
     * public channel silently ignores the field rather than honouring it — and
     * a customer booking a laser session has no reason to know which machine it
     * is anyway (§6, §37).
     *
     * Each named resource must exist, be bookable, and stand at this branch.
     * One that does not is a refusal rather than a substitution: whoever named
     * it had a reason (§11).
     *
     * @return list<string>
     *
     * @throws BookingFailed
     */
    private function pinnedResources(BookingLine $line, Branch $branch, bool $publicChannel): array
    {
        if ($publicChannel || $line->resourceUuids === []) {
            return [];
        }

        $found = OperationalResource::query()
            ->bookableAt((int) $branch->getKey())
            ->whereIn('uuid', $line->resourceUuids)
            ->pluck('uuid')
            ->all();

        foreach ($line->resourceUuids as $uuid) {
            if (! in_array($uuid, $found, true)) {
                throw BookingFailed::policy('One of the resources you selected is not available at that branch.');
            }
        }

        /** @var list<string> $uuids */
        $uuids = array_values(array_unique($line->resourceUuids));

        return $uuids;
    }

    /**
     * Where this line sits inside the visit.
     *
     * Staff only. A public caller sending an offset gets a sequential booking
     * rather than an error, because the field is not part of the public
     * contract and refusing it would leak that it exists (§34).
     *
     * @throws BookingFailed
     */
    private function offset(BookingLine $line, bool $publicChannel): ?int
    {
        if ($publicChannel || $line->offsetMinutes === null) {
            return null;
        }

        if ($line->offsetMinutes < 0 || $line->offsetMinutes > self::MAX_OFFSET_MINUTES) {
            throw BookingFailed::policy('That service is scheduled too far into the visit.');
        }

        return $line->offsetMinutes;
    }

    /**
     * @throws BookingFailed
     */
    private function service(string $uuid, Branch $branch, bool $publicChannel): Service
    {
        $service = Service::query()->where('uuid', $uuid)->first();

        if (! $service instanceof Service || ! $service->is_active || $service->isArchived()) {
            throw BookingFailed::policy('That service is not available.');
        }

        if (! $service->isAvailableAtBranch((int) $branch->getKey())) {
            // Phase 4's rule: "all branches" is the absence of pivot rows, so
            // this asks the real question rather than looking for a row.
            throw BookingFailed::policy('That service is not offered at that branch.');
        }

        if ($publicChannel && ! $service->is_online_bookable) {
            /*
             * A center can list a service on the menu for information while
             * requiring a phone call to book it — the `is_online_bookable` flag
             * Phase 4 recorded for exactly this moment. Staff are not bound by
             * it: reception taking a booking over the phone IS the intended
             * route for these.
             */
            throw BookingFailed::policy('That service cannot be booked online.');
        }

        return $service;
    }

    /**
     * @throws BookingFailed
     */
    private function variation(?string $uuid, Service $service): ?ServiceVariation
    {
        if ($uuid === null) {
            return null;
        }

        $variation = ServiceVariation::query()
            ->where('uuid', $uuid)
            // Scoped to the service, so a uuid belonging to a different (say,
            // cheaper) service cannot be attached to this one.
            ->where('service_id', $service->getKey())
            ->first();

        if (! $variation instanceof ServiceVariation || ! $variation->is_active) {
            throw BookingFailed::policy('That option is not available for this service.');
        }

        return $variation;
    }

    /**
     * @param  list<string>  $uuids
     * @return list<ServiceAddon>
     *
     * @throws BookingFailed
     */
    private function addons(array $uuids, Service $service): array
    {
        $uuids = array_values(array_unique($uuids));

        if ($uuids === []) {
            return [];
        }

        /** @var list<ServiceAddon> $addons */
        $addons = $service->addons()
            ->whereIn('service_addons.uuid', $uuids)
            ->where('service_addons.is_active', true)
            ->get()
            ->all();

        if (count($addons) !== count($uuids)) {
            // Refused as a set, not silently trimmed. A customer who selected
            // three extras and is quietly booked for two would find out at the
            // till.
            throw BookingFailed::policy('One of the selected extras is not available for this service.');
        }

        return $addons;
    }

    /**
     * @throws BookingFailed
     */
    private function employee(?string $uuid, Service $service, Branch $branch): ?Employee
    {
        if ($uuid === null) {
            // "Any available" — a real choice, resolved later by the assigner
            // once a time is known.
            return null;
        }

        $employee = Employee::query()->where('uuid', $uuid)->first();

        if (! $employee instanceof Employee || ! $employee->status->isActive()) {
            throw BookingFailed::employeeUnavailable('That team member is not available.');
        }

        // CURRENT eligibility, deliberately. An appointment already in the book
        // keeps its assignment when a manager later withdraws eligibility —
        // history is not rewritten — but a NEW booking is checked against the
        // catalog as it stands today (§20).
        if (! $service->eligibleEmployees()->whereKey($employee->getKey())->exists()) {
            throw BookingFailed::employeeUnavailable('That team member does not perform this service.');
        }

        if (! $employee->branches()->whereKey($branch->getKey())->exists()) {
            throw BookingFailed::employeeUnavailable('That team member does not work at that branch.');
        }

        return $employee;
    }
}
