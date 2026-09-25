<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\AppointmentActions;
use App\Modules\Booking\Application\BookingOptions;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The Manager bookings desk — its read models
|--------------------------------------------------------------------------
|
| docs/15-BOOKING.md §§9, 14, 16. The scoped finder, the calendar filters and
| paging, the allowed-actions read and the form's option lists. Each one is a
| READ; every rule in it is the engine's own, and these tests pin that.
|
*/

function bkrDate(int $days = 7): string
{
    return CarbonImmutable::now('Asia/Baghdad')->addDays($days)->format('Y-m-d');
}

/**
 * @param  array<string, mixed>  $seed
 */
function bkrBook(array $seed, User $by, string $time, ?string $customerUuid = null, string $name = 'Sara Ahmed', string $phone = '+9647501110000', int $days = 7): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: test()->localTime($seed['branch'], bkrDate($days), $time),
            customer: $customerUuid !== null ? CustomerRef::existing($customerUuid) : CustomerRef::details($name, $phone),
        ),
        BookingActor::staff($by),
    )->appointment;
}

it('finds an appointment only for a viewer who may see it, and says NOT FOUND otherwise', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $other = $this->seedBranch('Mansour');
        $this->openEveryDay($other);
        $seed['employee']->branches()->attach($other->id);

        $here = bkrBook($seed, $owner, '10:00');
        $there = bkrBook(['branch' => $other, 'service' => $seed['service']], $owner, '11:00', null, 'Fatima', '+9647502220000');

        $calendar = app(CalendarQuery::class);

        // The detail relations come loaded, so the presenter never lazy-loads.
        $found = $calendar->find($here->uuid, $owner);
        expect($found->is($here))->toBeTrue()
            ->and($found->relationLoaded('items'))->toBeTrue()
            ->and($found->relationLoaded('internalNotes'))->toBeTrue();

        $scoped = $this->staffWith([Permission::AppointmentView], 'scoped@alpha.test');
        $scoped->forceFill(['all_branches' => false])->save();
        $scoped->syncBranchScope([(int) $seed['branch']->id]);
        $scoped->forgetPermissionCache();

        expect($calendar->find($here->uuid, $scoped)->uuid)->toBe($here->uuid)
            // Another branch is not "forbidden", it is not there.
            ->and(fn () => $calendar->find($there->uuid, $scoped))->toThrow(ModelNotFoundException::class);

        // No appointment permission at all: nothing is found.
        $nobody = $this->staffWith([Permission::CustomerView], 'nobody@alpha.test');
        expect(fn () => $calendar->find($here->uuid, $nobody))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $calendar->find('not-a-uuid', $owner))->toThrow(ModelNotFoundException::class);
    });
});

it('finds only a view-own user\'s own appointments', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $sara = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $stylist = $this->staffWith([Permission::AppointmentViewOwn], 'stylist@alpha.test');
        $sara->forceFill(['user_id' => $stylist->id])->save();

        $mine = app(BookingEngine::class)->book(new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid, employeeUuid: $sara->uuid)],
            startsAt: $this->localTime($seed['branch'], bkrDate(), '10:00'),
            customer: CustomerRef::details('Mine', '+9647503330000'),
        ), BookingActor::staff($owner))->appointment;

        $theirs = bkrBook($seed, $owner, '12:00', null, 'Theirs', '+9647504440000');

        $calendar = app(CalendarQuery::class);

        expect($calendar->find($mine->uuid, $stylist)->uuid)->toBe($mine->uuid)
            ->and(fn () => $calendar->find($theirs->uuid, $stylist))->toThrow(ModelNotFoundException::class);
    });
});

it('filters the calendar by service, customer, reference, name and source — and never by a masked phone', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $beard = $this->seedService('Beard trim', 20, 8000, $seed['employee']);

        $sara = $this->seedCustomer('Sara Ahmed', '0750 111 2222');
        $fatima = $this->seedCustomer('Fatima Hassan', '0750 333 4444');

        $first = bkrBook($seed, $owner, '10:00', $sara->uuid);
        $second = bkrBook(['branch' => $seed['branch'], 'service' => $beard], $owner, '12:00', $fatima->uuid);

        $calendar = app(CalendarQuery::class);
        $day = bkrDate();
        $uuids = static fn (iterable $rows): array => collect($rows)->pluck('uuid')->all();

        expect($uuids($calendar->forRange($day, $day, $owner, ['service' => $beard->uuid])))->toBe([$second->uuid])
            ->and($uuids($calendar->forRange($day, $day, $owner, ['customer' => $sara->uuid])))->toBe([$first->uuid])
            ->and($uuids($calendar->forRange($day, $day, $owner, ['search' => (string) $second->reference])))->toBe([$second->uuid])
            ->and($uuids($calendar->forRange($day, $day, $owner, ['search' => 'fatima'])))->toBe([$second->uuid])
            ->and($uuids($calendar->forRange($day, $day, $owner, ['source' => 'staff'])))->toHaveCount(2)
            ->and($uuids($calendar->forRange($day, $day, $owner, ['source' => 'public_web'])))->toBe([])
            // An unknown source matches nothing rather than everything.
            ->and($uuids($calendar->forRange($day, $day, $owner, ['source' => 'forged'])))->toBe([]);

        // The owner may see phones, so a phone finds the customer…
        expect($uuids($calendar->forRange($day, $day, $owner, ['search' => '0750 333 4444'])))->toBe([$second->uuid]);

        // …and a viewer who may NOT see phones cannot use the search box to
        // test which number belongs to whom (ADR-042).
        $masked = $this->staffWith([Permission::AppointmentView, Permission::CustomerView], 'masked@alpha.test');

        expect($uuids($calendar->forRange($day, $day, $masked, ['search' => '0750 333 4444'])))->toBe([])
            ->and($uuids($calendar->forRange($day, $day, $masked, ['search' => 'Fatima'])))->toBe([$second->uuid]);
    });
});

it('pages the list and counts every status under the other filters', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $a = bkrBook($seed, $owner, '09:00', null, 'A', '+9647500000001');
        bkrBook($seed, $owner, '10:00', null, 'B', '+9647500000002');
        bkrBook($seed, $owner, '11:00', null, 'C', '+9647500000003');

        app(BookingEngine::class)->cancel($a, BookingActor::staff($owner), 'Called');

        $calendar = app(CalendarQuery::class);
        $day = bkrDate();

        $page = $calendar->paginate($day, bkrDate(9), $owner, [], 2);

        expect($page->total())->toBe(3)
            ->and($page->items())->toHaveCount(2)
            ->and($page->lastPage())->toBe(2);

        $counts = $calendar->statusCounts($day, $day, $owner, ['status' => 'cancelled']);

        // The status filter itself is ignored by the counts: the switch shows
        // what each choice WOULD give.
        expect($counts)->toMatchArray(['booked' => 2, 'cancelled' => 1, 'confirmed' => 0, 'completed' => 0, 'no_show' => 0])
            ->and($calendar->paginate($day, $day, $owner, ['status' => 'cancelled'])->total())->toBe(1)
            // Still range-capped: paging is not a way round the 31-day limit.
            ->and(fn () => $calendar->paginate($day, bkrDate(60), $owner))->toThrow(BookingFailed::class);
    });
});

it('knows whether a center has any booking history at all', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        expect(app(CalendarQuery::class)->hasHistory())->toBeFalse();

        bkrBook($seed, $this->ownerWithCatalogAccess(), '10:00');

        expect(app(CalendarQuery::class)->hasHistory())->toBeTrue();
    });
});

it('offers exactly the actions the engine would allow, by status, permission, time and plan', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function () use ($center): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $appointment = bkrBook($seed, $owner, '10:00');
        $actions = app(AppointmentActions::class);

        $before = $appointment->starts_at->subHour();
        $after = $appointment->starts_at->addMinutes(5);

        $early = $actions->for($appointment, $owner, $before);
        expect($early)->toMatchArray([
            'confirm' => true, 'complete' => true, 'cancel' => true, 'reschedule' => true,
            'reassign' => true, 'issue_code' => true, 'manage_notes' => true, 'writable' => true,
            // A no-show cannot be known before the start (§9).
            'no_show' => false, 'no_show_later' => true,
        ]);

        expect($actions->for($appointment, $owner, $after))->toMatchArray(['no_show' => true, 'no_show_later' => false]);

        // Reception that books and cancels but may not mark a no-show.
        $host = $this->staffWith([Permission::AppointmentView, Permission::AppointmentCancel, Permission::AppointmentNoteView], 'host@alpha.test');
        expect($actions->for($appointment, $host, $after))->toMatchArray([
            'confirm' => false, 'complete' => false, 'no_show' => false, 'no_show_later' => false,
            'cancel' => true, 'reschedule' => false, 'issue_code' => false, 'view_notes' => true, 'manage_notes' => false,
        ]);

        // A closed booking offers nothing that changes it.
        app(BookingEngine::class)->cancel($appointment, BookingActor::staff($owner));
        $closed = $actions->for($appointment->refresh(), $owner, $after);
        expect($closed)->toMatchArray(['confirm' => false, 'complete' => false, 'cancel' => false, 'reschedule' => false, 'issue_code' => false]);

        // After a downgrade: readable, nothing writable (§14).
        $open = bkrBook($seed, $owner, '12:00', null, 'Later', '+9647505550000');
        DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
            ['tenant_id' => $center['tenant']->id, 'entitlement' => 'booking'],
            ['mode' => 'revoke', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
        );
        app(Entitlements::class)->invalidate($center['tenant']->id);

        // Resolved afresh, as every request does: the entitlement is read per request.
        expect(app(AppointmentActions::class)->for($open, $owner, $before))->toMatchArray([
            'writable' => false, 'confirm' => false, 'cancel' => false, 'reschedule' => false, 'manage_notes' => false, 'view_notes' => true,
        ]);
    });
});

it('lists only qualified people, offered services and real rooms at the viewer\'s branches', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $options = app(BookingOptions::class);

        // Somebody at the branch who does NOT perform the service, and somebody
        // who does but works elsewhere: neither is offered for it.
        $this->seedEmployee('Receptionist', $seed['branch']);
        $other = $this->seedBranch('Mansour');
        $elsewhere = $this->seedEmployee('Elsewhere', $other);
        $seed['service']->eligibleEmployees()->attach($elsewhere->id);

        $people = $options->employeesFor($seed['service']->uuid, $seed['branch']->uuid, $owner);

        expect(array_column($people, 'name'))->toBe(['Ahmed']);

        $services = $options->services($seed['branch']->uuid, $owner);
        expect(array_column($services, 'uuid'))->toContain($seed['service']->uuid);

        // Priced by the same resolver the booking uses.
        $estimate = $options->estimate([new BookingLine($seed['service']->uuid, addonUuids: [$seed['addon']->uuid])], $seed['branch']->uuid, $owner);
        expect($estimate)->not->toBeNull()
            ->and($estimate['duration_minutes'])->toBe($seed['service']->duration_minutes + $seed['addon']->duration_minutes)
            ->and($estimate['price']['amount'])->toBe($seed['service']->price_minor + $seed['addon']->price_minor);

        // Rooms: a requirement lists the bookable rooms of that type here.
        $type = $this->seedResourceType('Treatment room');
        $room = $this->seedResource($type, $seed['branch'], 'Room 1');
        $this->requireResource($seed['service'], $type);

        $needs = $options->resourcesFor($seed['service']->uuid, $seed['branch']->uuid, $owner);
        expect($needs)->toHaveCount(1)
            ->and(array_column($needs[0]['options'], 'uuid'))->toBe([$room->uuid]);

        // A viewer limited to another branch gets nothing for this one.
        $scoped = $this->staffWith([Permission::AppointmentCreate], 'scoped@alpha.test');
        $scoped->forceFill(['all_branches' => false])->save();
        $scoped->syncBranchScope([(int) $other->id]);
        $scoped->forgetPermissionCache();

        expect($options->services($seed['branch']->uuid, $scoped))->toBe([])
            ->and($options->employeesFor($seed['service']->uuid, $seed['branch']->uuid, $scoped))->toBe([])
            ->and(array_column($options->branches($scoped), 'uuid'))->toBe([$other->uuid]);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
