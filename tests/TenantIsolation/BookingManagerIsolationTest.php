<?php

declare(strict_types=1);

use App\Livewire\Center\Booking\AppointmentPanel;
use App\Livewire\Center\Booking\RescheduleForm;
use App\Modules\Booking\Application\BookingOptions;
use App\Modules\Booking\Application\CalendarQuery;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\AvailabilityQuery;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager bookings desk, across tenants
|--------------------------------------------------------------------------
|
| A release gate for the desk's new reads: the scoped finder, the move
| question, the option lists and the drawers. Another center's appointment
| uuid is NOT FOUND everywhere — never forbidden, never shown.
|
*/

function bkiDate(): string
{
    return CarbonImmutable::now('Asia/Baghdad')->addDays(7)->format('Y-m-d');
}

it('never finds, shows, moves or counts another center\'s booking', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');

    [$uuid, $alphaService] = $this->asCenter($alpha['tenant'], function (): array {
        $seed = $this->seedBookableCenter();

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], bkiDate(), '10:00'),
                customer: CustomerRef::details('Alpha Secret Customer', '+9647500001111'),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        )->appointment;

        return [$appointment->uuid, $seed['service']->uuid];
    });

    $this->asCenter($beta['tenant'], function () use ($uuid, $alphaService): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // The scoped finder: not found, not forbidden.
        expect(fn () => app(CalendarQuery::class)->find($uuid, $owner))->toThrow(ModelNotFoundException::class);

        // The move question cannot reach it either.
        expect(fn () => app(BookingEngine::class)->availability(AvailabilityQuery::forMove($seed['branch']->uuid, $uuid, bkiDate())))
            ->toThrow(BookingFailed::class);

        // Beta's desk counts only Beta's book.
        expect(array_sum(app(CalendarQuery::class)->statusCounts(bkiDate(), bkiDate(), $owner)))->toBe(0)
            // Alpha's catalog uuid means nothing here.
            ->and(app(BookingOptions::class)->employeesFor($alphaService, $seed['branch']->uuid, $owner))->toBe([]);

        // The drawers say "not found" and show nothing of Alpha's.
        Livewire::actingAs($owner)
            ->test(AppointmentPanel::class, ['appointment' => $uuid])
            ->assertSee(__('manager_booking.panel.missing_title'))
            ->assertDontSee('Alpha Secret Customer')
            ->call('confirm')
            ->assertSet('error', __('manager_booking.errors.not_found'))
            // Nor can its room be changed from here.
            ->set('roomFrom', $uuid)
            ->set('roomTo', $uuid)
            ->call('changeRoom')
            ->assertSet('error', __('manager_booking.errors.not_found'));

        Livewire::actingAs($owner)
            ->test(RescheduleForm::class, ['appointment' => $uuid])
            ->assertSet('slots', [])
            ->assertSet('error', __('manager_booking.errors.not_found'));
    });

    // And Alpha's booking is untouched.
    $this->asCenter($alpha['tenant'], function () use ($uuid): void {
        expect(app(CalendarQuery::class)->find($uuid, $this->ownerWithCatalogAccess())->status->value)->toBe('booked');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
