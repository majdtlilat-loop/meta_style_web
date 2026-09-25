<?php

declare(strict_types=1);

namespace App\Modules\Booking\Application;

use App\Kernel\Identity\Models\User;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Kernel\Time\BranchClock;
use App\Modules\Booking\Domain\Availability\BranchCalendar;
use App\Modules\Booking\Domain\Availability\EmployeeAssigner;
use App\Modules\Booking\Domain\Availability\LineResolver;
use App\Modules\Booking\Domain\Availability\ResourceAllocator;
use App\Modules\Booking\Domain\Availability\Scheduler;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\AppointmentItem;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceAddon;
use App\Modules\Catalog\Domain\Models\ServiceVariation;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a booking form may offer — the choices, never the decisions.
 *
 * The desk needs lists: branches it may work in, services offered at the
 * branch, who may perform each one there, which rooms a service could take.
 * Each list comes from the SAME collaborators the engine decides with —
 * {@see EmployeeAssigner::eligibleIds()} for who may perform a service,
 * {@see ResourceAllocator::candidates()} for which rooms count — so a form
 * never offers a person or a room the engine would refuse for being the wrong
 * one (docs/15-BOOKING.md §§1, 8). Whether they are FREE is availability's
 * question, asked separately.
 *
 * Every method re-resolves the branch inside the viewer's branch scope: a
 * uuid a browser sent back is a claim, not a fact.
 *
 * Returns plain arrays with names already in the viewer's language — nothing a
 * template must query or translate.
 */
final class BookingOptions
{
    public function __construct(
        private readonly EmployeeAssigner $assigner,
        private readonly ResourceAllocator $resources,
        private readonly LineResolver $resolver,
        private readonly Scheduler $scheduler,
        private readonly BranchCalendar $calendar,
    ) {}

    /**
     * Branches this viewer may work in, in the center's own order.
     *
     * @return list<array{uuid: string, name: string, timezone: string}>
     */
    public function branches(User $viewer): array
    {
        $query = Branch::query()->active()->orderBy('sort_order')->orderBy('id');

        $viewer->branchScope()->applyTo($query, 'id');

        return $query->get()
            ->map(static fn (Branch $branch): array => [
                'uuid' => (string) $branch->uuid,
                'name' => (string) $branch->name->get(),
                'timezone' => $branch->timezone,
            ])
            ->values()
            ->all();
    }

    /**
     * Services the desk may book at a branch, with their active options and
     * extras. Staff are not bound by `is_online_bookable` — reception taking a
     * phone booking is exactly what that flag routes to (§10).
     *
     * @return list<array{uuid: string, name: string, duration_minutes: int, price: array{amount: int, currency: string}, variations: list<array{uuid: string, name: string, duration_minutes: int, price: array{amount: int, currency: string}}>, addons: list<array{uuid: string, name: string, duration_minutes: int, price: array{amount: int, currency: string}}>}>
     */
    public function services(string $branchUuid, User $viewer): array
    {
        $branch = $this->branch($branchUuid, $viewer);

        if (! $branch instanceof Branch) {
            return [];
        }

        $currency = Currency::default();

        return Service::query()
            ->active()
            ->atBranch((int) $branch->getKey())
            ->with([
                'variations' => fn ($q) => $q->where('is_active', true),
                'addons' => fn ($q) => $q->where('service_addons.is_active', true),
            ])
            ->get()
            ->map(fn (Service $service): array => [
                'uuid' => (string) $service->uuid,
                'name' => (string) $service->name->get(),
                'duration_minutes' => $service->duration_minutes,
                'price' => $this->money($service->price_minor, $currency),
                'variations' => $service->variations
                    ->map(fn (ServiceVariation $variation): array => [
                        'uuid' => (string) $variation->uuid,
                        'name' => (string) $variation->name->get(),
                        'duration_minutes' => $variation->effectiveDurationMinutes($service),
                        'price' => $this->money($variation->effectivePrice($service, $currency)->minor, $currency),
                    ])->values()->all(),
                'addons' => $service->addons
                    ->map(fn (ServiceAddon $addon): array => [
                        'uuid' => (string) $addon->uuid,
                        'name' => (string) $addon->name->get(),
                        'duration_minutes' => $addon->duration_minutes,
                        'price' => $this->money($addon->price_minor, $currency),
                    ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Who may perform a service at a branch — active, eligible, assigned —
     * in the order "any available" would try them.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function employeesFor(string $serviceUuid, string $branchUuid, User $viewer): array
    {
        $branch = $this->branch($branchUuid, $viewer);
        $service = $branch instanceof Branch ? $this->service($serviceUuid, $branch) : null;

        if (! $branch instanceof Branch || ! $service instanceof Service) {
            return [];
        }

        return $this->named($this->assigner->eligibleIds($service, $branch));
    }

    /**
     * The rooms, chairs and devices a service needs at a branch, and which
     * concrete ones could satisfy each need. Empty for a service with no
     * requirements — the ordinary case for a barbershop.
     *
     * @return list<array{type: string, quantity: int, options: list<array{uuid: string, name: string}>}>
     */
    public function resourcesFor(string $serviceUuid, string $branchUuid, User $viewer): array
    {
        $branch = $this->branch($branchUuid, $viewer);
        $service = $branch instanceof Branch ? $this->service($serviceUuid, $branch) : null;

        if (! $branch instanceof Branch || ! $service instanceof Service) {
            return [];
        }

        return ServiceResourceRequirement::query()
            ->with('type')
            ->where('service_id', $service->getKey())
            ->orderBy('resource_type_id')
            ->get()
            ->map(fn (ServiceResourceRequirement $requirement): array => [
                'type' => (string) ($requirement->type?->name->get() ?? ''),
                'quantity' => $requirement->quantity,
                'options' => array_map(
                    static fn (OperationalResource $resource): array => [
                        'uuid' => (string) $resource->uuid,
                        'name' => (string) $resource->name->get(),
                    ],
                    $this->resources->candidates((int) $requirement->resource_type_id, $branch),
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * The team at the viewer's branches — for filters and calendar lanes.
     *
     * Inactive people are included and flagged: their future bookings still
     * exist and still need to be found (§17).
     *
     * @return list<array{uuid: string, name: string, active: bool}>
     */
    public function team(User $viewer, ?string $branchUuid = null): array
    {
        $ids = $this->branchIds($viewer, $branchUuid);

        if ($ids === []) {
            return [];
        }

        return Employee::query()
            ->whereHas('branches', fn (Builder $q) => $q->whereIn('branches.id', $ids))
            ->orderByRaw('case when status = ? then 0 else 1 end', [EmployeeStatus::Active->value])
            ->orderBy('id')
            ->get()
            ->map(static fn (Employee $employee): array => [
                'uuid' => (string) $employee->uuid,
                'name' => (string) $employee->name->get(),
                'active' => $employee->status->isActive(),
            ])
            ->values()
            ->all();
    }

    /**
     * Bookable rooms and devices at the viewer's branches — calendar lanes.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function rooms(User $viewer, ?string $branchUuid = null): array
    {
        $ids = $this->branchIds($viewer, $branchUuid);

        if ($ids === []) {
            return [];
        }

        return OperationalResource::query()
            ->active()
            ->whereIn('branch_id', $ids)
            ->get()
            ->map(static fn (OperationalResource $resource): array => [
                'uuid' => (string) $resource->uuid,
                'name' => (string) $resource->name->get(),
            ])
            ->values()
            ->all();
    }

    /**
     * When a branch is open on a local date, as minutes from that date's local
     * midnight (an overnight interval runs past 1440).
     *
     * @return list<array{from: int, to: int}>
     */
    public function openHours(string $branchUuid, string $date, User $viewer): array
    {
        $branch = $this->branch($branchUuid, $viewer);

        if (! $branch instanceof Branch) {
            return [];
        }

        $midnight = CarbonImmutable::parse($date.' 00:00:00', $branch->timezone);
        $hours = [];

        foreach ($this->calendar->windowsForDate($branch, $date) as $window) {
            $from = BranchClock::toLocal($window->start, $branch->timezone);
            $to = BranchClock::toLocal($window->end, $branch->timezone);

            $hours[] = [
                'from' => (int) $midnight->diffInMinutes($from, false),
                'to' => (int) $midnight->diffInMinutes($to, false),
            ];
        }

        return $hours;
    }

    /**
     * What these lines would take and cost if booked — resolved by the SAME
     * resolver and scheduler the booking uses, so the figure on the form is
     * the figure on the appointment. Null while the lines are incomplete or
     * something in them is not bookable.
     *
     * @param  list<BookingLine>  $lines
     * @return array{duration_minutes: int, price: array{amount: int, currency: string}}|null
     */
    public function estimate(array $lines, string $branchUuid, User $viewer): ?array
    {
        $branch = $this->branch($branchUuid, $viewer);

        if (! $branch instanceof Branch || $lines === []) {
            return null;
        }

        try {
            $resolved = $this->resolver->resolve($lines, $branch, false);
        } catch (BookingFailed) {
            return null;
        }

        $minor = 0;

        foreach ($resolved as $line) {
            $minor += $line->priceMinor;
        }

        return [
            'duration_minutes' => $this->scheduler->totalMinutes($resolved),
            'price' => $this->money($minor, $resolved[0]->currency),
        ];
    }

    /**
     * Who could take one service of an existing booking instead — the list
     * {@see Actions\ReassignAppointmentEmployee} accepts from.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function reassignCandidates(Appointment $appointment, string $itemUuid): array
    {
        /** @var AppointmentItem|null $item */
        $item = $appointment->items()->with('service')->where('uuid', $itemUuid)->first();
        $branch = $appointment->branch()->first();

        if (! $item instanceof AppointmentItem || $item->service === null || ! $branch instanceof Branch) {
            return [];
        }

        return $this->named($this->assigner->eligibleIds($item->service, $branch));
    }

    /**
     * The rooms and devices one service of an existing booking holds, each
     * with the others of its kind it could be given instead — the list
     * {@see Actions\ReassignAppointmentResource} accepts from (same type, a
     * resource a new booking could take at the branch, not one the service
     * already holds).
     *
     * @return list<array{uuid: string, name: string, type: string, options: list<array{uuid: string, name: string}>}>
     */
    public function roomCandidates(Appointment $appointment, string $itemUuid): array
    {
        /** @var AppointmentItem|null $item */
        $item = $appointment->items()->with('resourceReservations.resource')->where('uuid', $itemUuid)->first();
        $branch = $appointment->branch()->first();

        if (! $item instanceof AppointmentItem || ! $branch instanceof Branch) {
            return [];
        }

        $held = [];

        foreach ($item->resourceReservations as $reservation) {
            if ($reservation->resource instanceof OperationalResource) {
                $held[(string) $reservation->resource->uuid] = $reservation;
            }
        }

        $rooms = [];

        foreach ($held as $uuid => $reservation) {
            /** @var OperationalResource $resource */
            $resource = $reservation->resource;
            $options = [];

            foreach ($this->resources->candidates((int) $resource->resource_type_id, $branch) as $candidate) {
                if (! isset($held[(string) $candidate->uuid])) {
                    $options[] = ['uuid' => (string) $candidate->uuid, 'name' => (string) $candidate->name->get()];
                }
            }

            $rooms[] = [
                'uuid' => $uuid,
                'name' => (string) $reservation->resource_name->get(),
                'type' => (string) $reservation->resource_type_name->get(),
                'options' => $options,
            ];
        }

        return $rooms;
    }

    /**
     * Names for employee ids — to say "with Ahmed" beside a slot, since a slot
     * carries the ids the engine would assign.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    public function employeeNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        $names = [];

        foreach (Employee::query()->whereKey($ids)->get() as $employee) {
            $names[(int) $employee->getKey()] = (string) $employee->name->get();
        }

        return $names;
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{uuid: string, name: string}>
     */
    private function named(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $employees = Employee::query()->whereKey($ids)->get()->keyBy('id');
        $named = [];

        // In the assigner's order, which is the order "any" tries them.
        foreach ($ids as $id) {
            $employee = $employees->get($id);

            if ($employee instanceof Employee) {
                $named[] = ['uuid' => (string) $employee->uuid, 'name' => (string) $employee->name->get()];
            }
        }

        return $named;
    }

    private function branch(string $uuid, User $viewer): ?Branch
    {
        if ($uuid === '') {
            return null;
        }

        $query = Branch::query()->active()->where('uuid', $uuid);

        $viewer->branchScope()->applyTo($query, 'id');

        $branch = $query->first();

        return $branch instanceof Branch ? $branch : null;
    }

    /**
     * @return list<int>
     */
    private function branchIds(User $viewer, ?string $branchUuid): array
    {
        $query = Branch::query()->active();

        $viewer->branchScope()->applyTo($query, 'id');

        if (is_string($branchUuid) && $branchUuid !== '') {
            $query->where('uuid', $branchUuid);
        }

        return $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all();
    }

    private function service(string $uuid, Branch $branch): ?Service
    {
        if ($uuid === '') {
            return null;
        }

        $service = Service::query()->active()->atBranch((int) $branch->getKey())->where('uuid', $uuid)->first();

        return $service instanceof Service ? $service : null;
    }

    /**
     * @return array{amount: int, currency: string}
     */
    private function money(int $minor, Currency $currency): array
    {
        $money = Money::fromMinor($minor, $currency);

        return ['amount' => $money->minor, 'currency' => $money->currency->value];
    }
}
