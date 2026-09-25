<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Booking\Application\Actions\ReassignAppointmentEmployee;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\EmployeeSelection;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Changing who does a booked service
|--------------------------------------------------------------------------
|
| docs/15-BOOKING.md §§8, 17. "We deactivated Ahmed — who takes his
| Thursday?" answered through the engine's rules: current eligibility, the
| branch, the authoritative conflict check under the branch lock, and an
| audited before/after. Time, price and rooms never move.
|
*/

function bkaDate(): string
{
    return CarbonImmutable::now('Asia/Baghdad')->addDays(7)->format('Y-m-d');
}

/**
 * @param  array<string, mixed>  $seed
 */
function bkaBook(array $seed, string $time, ?string $employeeUuid = null, string $phone = '+9647506660000'): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid, employeeUuid: $employeeUuid)],
            startsAt: test()->localTime($seed['branch'], bkaDate(), $time),
            customer: CustomerRef::details('Customer '.$phone, $phone),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

it('gives a booked service to a named, qualified, free team member — and keeps everything else', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $appointment = bkaBook($seed, '10:00');
        $item = $appointment->items()->firstOrFail();

        expect($item->employee_id)->toBe($seed['employee']->id);

        app(ReassignAppointmentEmployee::class)($appointment, $item->uuid, $sara->uuid, $owner);

        $item->refresh();

        expect($item->employee_id)->toBe($sara->id)
            // Named by the desk: a later move keeps her.
            ->and($item->employee_selection)->toBe(EmployeeSelection::Specific)
            ->and($item->starts_at->equalTo($appointment->starts_at))->toBeTrue()
            ->and($item->price_minor)->toBe(20000);

        $audit = TenantAuditLog::query()->where('action', 'booking.appointment.reassigned')->firstOrFail();

        // Before and after in the one snapshot shape; no customer contact.
        expect($audit->before['items'][0]['employee_id'])->toBe($seed['employee']->id)
            ->and($audit->after['items'][0]['employee_id'])->toBe($sara->id)
            ->and(json_encode([$audit->before, $audit->after, $audit->meta]))->not->toContain('7506660000');
    });
});

it('refuses somebody busy, unqualified, inactive or at another branch', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $action = app(ReassignAppointmentEmployee::class);

        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');
        $appointment = bkaBook($seed, '10:00');
        $item = $appointment->items()->firstOrFail();

        // Sara already has a customer at 10:00.
        bkaBook($seed, '10:00', $sara->uuid, '+9647501112222');

        expect(fn () => $action($appointment, $item->uuid, $sara->uuid, $owner))->toThrow(BookingFailed::class);

        // Works here but does not perform this service.
        $receptionist = $this->seedEmployee('Receptionist', $seed['branch']);
        expect(fn () => $action($appointment, $item->uuid, $receptionist->uuid, $owner))->toThrow(BookingFailed::class);

        // Performs it, but at another branch.
        $elsewhere = $this->seedEmployee('Elsewhere', $this->seedBranch('Mansour'));
        $seed['service']->eligibleEmployees()->attach($elsewhere->id);
        expect(fn () => $action($appointment, $item->uuid, $elsewhere->uuid, $owner))->toThrow(BookingFailed::class);

        // Qualified, but no longer active.
        $former = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Former');
        $former->forceFill(['status' => EmployeeStatus::Inactive])->save();
        expect(fn () => $action($appointment, $item->uuid, $former->uuid, $owner))->toThrow(BookingFailed::class);

        // An item uuid from another booking is not part of this one.
        $otherItem = bkaBook($seed, '12:00', null, '+9647503334444')->items()->firstOrFail();
        expect(fn () => $action($appointment, $otherItem->uuid, null, $owner))->toThrow(BookingFailed::class);

        expect($item->refresh()->employee_id)->toBe($seed['employee']->id);
    });
});

it('picks the first free eligible person for "any available", the booking policy', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        // Named for Sara; the desk opens it back up to anybody.
        $appointment = bkaBook($seed, '10:00', $sara->uuid);
        $item = $appointment->items()->firstOrFail();

        app(ReassignAppointmentEmployee::class)($appointment, $item->uuid, null, $owner);

        $item->refresh();

        // Lowest eligible id who is free: Ahmed (§8).
        expect($item->employee_id)->toBe($seed['employee']->id)
            ->and($item->employee_selection)->toBe(EmployeeSelection::Any);
    });
});

it('checks permission, branch, status and plan', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');
        $action = app(ReassignAppointmentEmployee::class);

        $appointment = bkaBook($seed, '10:00');
        $item = $appointment->items()->firstOrFail();

        $viewer = $this->staffWith([Permission::AppointmentView], 'viewer@alpha.test');
        expect(fn () => $action($appointment, $item->uuid, $sara->uuid, $viewer))->toThrow(AuthorizationException::class);

        $elsewhere = $this->staffWith([Permission::AppointmentUpdate], 'elsewhere@alpha.test');
        $elsewhere->forceFill(['all_branches' => false])->save();
        $elsewhere->syncBranchScope([(int) $this->seedBranch('Mansour')->id]);
        $elsewhere->forgetPermissionCache();
        expect(fn () => $action($appointment, $item->uuid, $sara->uuid, $elsewhere))->toThrow(AuthorizationException::class);

        app(BookingEngine::class)->cancel($appointment, BookingActor::staff($owner));
        expect(fn () => $action($appointment->refresh(), $item->uuid, $sara->uuid, $owner))->toThrow(BookingFailed::class);

        $open = bkaBook($seed, '12:00', null, '+9647505556666');
        DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
            ['tenant_id' => $center['tenant']->id, 'entitlement' => 'booking'],
            ['mode' => 'revoke', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
        );
        app(Entitlements::class)->invalidate($center['tenant']->id);

        expect(fn () => app(ReassignAppointmentEmployee::class)($open, $open->items()->firstOrFail()->uuid, $sara->uuid, $owner))->toThrow(EntitlementRequired::class);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
