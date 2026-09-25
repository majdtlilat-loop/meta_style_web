<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Livewire\Center\Booking\AppointmentPanel;
use App\Modules\Booking\Application\Actions\ReassignAppointmentResource;
use App\Modules\Booking\Application\BookingOptions;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Booking\Domain\Models\ResourceReservation;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Changing the room or device a booked service holds
|--------------------------------------------------------------------------
|
| docs/15-BOOKING.md §§8, 17, 36. The explicit decision a reservation
| snapshot otherwise never gets: another resource of the SAME kind at the
| booking's branch, free for the item's whole window under the branch lock,
| audited with the resource before and after. Time, price and person never
| move.
|
*/

function bkrrDate(): string
{
    return CarbonImmutable::now('Asia/Baghdad')->addDays(7)->format('Y-m-d');
}

/**
 * @param  array<string, mixed>  $seed
 */
function bkrrBook(array $seed, string $time, ?string $employeeUuid = null, string $phone = '+9647508880000'): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid, employeeUuid: $employeeUuid)],
            startsAt: test()->localTime($seed['branch'], bkrrDate(), $time),
            customer: CustomerRef::details('Customer '.$phone, $phone),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

function bkrrHeld(Appointment $appointment): ResourceReservation
{
    /** @var ResourceReservation $reservation */
    $reservation = ResourceReservation::query()
        ->whereIn('appointment_item_id', $appointment->items()->pluck('id'))
        ->with('resource')
        ->firstOrFail();

    return $reservation;
}

it('gives a booked service another room of the same kind from the booking drawer, and audits it', function (): void {
    $center = $this->registerCenter();
    URL::defaults(['center' => $center['registration']->requested_slug]);

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $type = $this->seedResourceType('Treatment room');
        $first = $this->seedResource($type, $seed['branch'], 'Room 1');
        $second = $this->seedResource($type, $seed['branch'], 'Room 2', 1, null, 1);
        $this->requireResource($seed['service'], $type);

        $appointment = bkrrBook($seed, '10:00');
        $item = $appointment->items()->firstOrFail();
        $held = bkrrHeld($appointment);

        expect($held->resource_id)->toBe($first->id);

        // The drawer offers exactly the other room of that kind.
        expect(app(BookingOptions::class)->roomCandidates($appointment, $item->uuid))->toBe([[
            'uuid' => $first->uuid,
            'name' => 'Room 1',
            'type' => 'Treatment room',
            'options' => [['uuid' => $second->uuid, 'name' => 'Room 2']],
        ]]);

        Livewire::actingAs($owner)
            ->test(AppointmentPanel::class, ['appointment' => $appointment->uuid])
            ->assertSee(__('manager_booking.panel.change_room'))
            ->call('startRoomChange', $item->uuid, $first->uuid)
            ->assertSet('mode', 'room')
            ->assertSee('Room 2')
            ->set('roomTo', $second->uuid)
            ->call('changeRoom')
            ->assertSet('error', '')
            ->assertSet('mode', 'detail')
            ->assertSet('notice', __('manager_booking.notices.room_changed'))
            ->assertDispatched('appointment-changed');

        $held->refresh();
        $item->refresh();

        // A new snapshot for the new room; the time, the price and the person stay.
        expect($held->resource_id)->toBe($second->id)
            ->and($held->resource_name->get())->toBe('Room 2')
            ->and($held->resource_type_name->get())->toBe('Treatment room')
            ->and($item->starts_at->equalTo($appointment->starts_at))->toBeTrue()
            ->and($item->employee_id)->toBe($seed['employee']->id)
            ->and($item->price_minor)->toBe(20000);

        $audit = TenantAuditLog::query()->where('action', 'booking.appointment.resource_changed')->firstOrFail();

        expect($audit->before['resource_uuid'])->toBe($first->uuid)
            ->and($audit->after['resource_uuid'])->toBe($second->uuid)
            ->and($audit->after['item_uuid'])->toBe($item->uuid)
            ->and(json_encode([$audit->before, $audit->after, $audit->meta]))->not->toContain('7508880000');
    });
});

it('refuses a room that is busy, of another kind, at another branch, or already held', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');
        $action = app(ReassignAppointmentResource::class);

        $rooms = $this->seedResourceType('Treatment room');
        $first = $this->seedResource($rooms, $seed['branch'], 'Room 1');
        $second = $this->seedResource($rooms, $seed['branch'], 'Room 2', 1, null, 1);
        $elsewhere = $this->seedResource($rooms, $this->seedBranch('Mansour'), 'Mansour room');
        $laser = $this->seedResource($this->seedResourceType('Laser machine'), $seed['branch'], 'Laser 1');
        $this->requireResource($seed['service'], $rooms);

        // Two customers at 10:00, one per room.
        $appointment = bkrrBook($seed, '10:00');
        $other = bkrrBook($seed, '10:00', $sara->uuid, '+9647508881111');
        $item = $appointment->items()->firstOrFail();

        expect(bkrrHeld($appointment)->resource_id)->toBe($first->id)
            ->and(bkrrHeld($other)->resource_id)->toBe($second->id);

        $refusals = [
            'busy' => [$first->uuid, $second->uuid],
            'another kind' => [$first->uuid, $laser->uuid],
            'another branch' => [$first->uuid, $elsewhere->uuid],
            'already held' => [$first->uuid, $first->uuid],
            'not held' => [$second->uuid, $first->uuid],
        ];

        foreach ($refusals as $case => [$from, $to]) {
            expect(fn () => $action($appointment, $item->uuid, $from, $to, $owner))
                ->toThrow(BookingFailed::class, null, $case);
        }

        // Nothing moved, and nothing was audited.
        expect(bkrrHeld($appointment)->resource_id)->toBe($first->id)
            ->and(TenantAuditLog::query()->where('action', 'booking.appointment.resource_changed')->exists())->toBeFalse();

        // At 12:00 Room 2 is free again: the same change is accepted.
        $later = bkrrBook($seed, '12:00', null, '+9647508882222');
        $action($later, $later->items()->firstOrFail()->uuid, $first->uuid, $second->uuid, $owner);

        expect(bkrrHeld($later)->resource_id)->toBe($second->id);
    });
});

it('checks permission, branch, status and plan before changing a room', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $action = app(ReassignAppointmentResource::class);

        $type = $this->seedResourceType('Treatment room');
        $first = $this->seedResource($type, $seed['branch'], 'Room 1');
        $second = $this->seedResource($type, $seed['branch'], 'Room 2', 1, null, 1);
        $this->requireResource($seed['service'], $type);

        $appointment = bkrrBook($seed, '10:00');
        $item = $appointment->items()->firstOrFail();

        $viewer = $this->staffWith([Permission::AppointmentView], 'viewer@alpha.test');
        expect(fn () => $action($appointment, $item->uuid, $first->uuid, $second->uuid, $viewer))->toThrow(AuthorizationException::class);

        $outside = $this->staffWith([Permission::AppointmentUpdate], 'outside@alpha.test');
        $outside->forceFill(['all_branches' => false])->save();
        $outside->syncBranchScope([(int) $this->seedBranch('Mansour')->id]);
        $outside->forgetPermissionCache();
        expect(fn () => $action($appointment, $item->uuid, $first->uuid, $second->uuid, $outside))->toThrow(AuthorizationException::class);

        app(BookingEngine::class)->cancel($appointment, BookingActor::staff($owner));
        expect(fn () => $action($appointment->refresh(), $item->uuid, $first->uuid, $second->uuid, $owner))->toThrow(BookingFailed::class);

        $open = bkrrBook($seed, '12:00', null, '+9647508883333');
        DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
            ['tenant_id' => $center['tenant']->id, 'entitlement' => 'booking'],
            ['mode' => 'revoke', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
        );
        app(Entitlements::class)->invalidate($center['tenant']->id);

        expect(fn () => app(ReassignAppointmentResource::class)($open, $open->items()->firstOrFail()->uuid, $first->uuid, $second->uuid, $owner))
            ->toThrow(EntitlementRequired::class);

        expect(bkrrHeld($open)->resource_id)->toBe($first->id);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
