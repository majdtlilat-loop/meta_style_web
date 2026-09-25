<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Livewire\Center\Journey\VisitPanel;
use App\Livewire\Center\JourneyBoard;
use App\Livewire\Center\Queue\WalkInForm;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Application\Actions\CompleteJourney;
use App\Modules\ServiceJourney\Application\Actions\CreateWalkInVisit;
use App\Modules\ServiceJourney\Application\Actions\TransitionStage;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\JourneyHandoff;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Today's visits — the Manager floor board
|--------------------------------------------------------------------------
|
| docs/16-JOURNEY-RESOURCES.md. Regression for the walk-in crash and the lazy
| loads (preventLazyLoading is on in tests), for finished bookings vanishing
| from their own day, and for the visit drawer running Journey Actions only.
|
*/

function vbDate(): string
{
    return CarbonImmutable::now()->addDays(25)->format('Y-m-d');
}

/**
 * @param  list<string>  $services
 */
function vbBook(array $seed, string $name, string $time, array $services): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], vbDate(), $time),
            lines: array_map(static fn (string $uuid): BookingLine => new BookingLine(serviceUuid: $uuid), $services),
            customer: CustomerRef::details($name, '+96477'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

it('shows walk-ins, several visits and finished bookings on their day without lazy loads', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $colour = $this->seedService('Colour', 45, 30000, $seed['employee']);

        $done = vbBook($seed, 'Done Customer', '10:00', [$seed['service']->uuid]);
        $busy = vbBook($seed, 'Busy Customer', '11:00', [$seed['service']->uuid, $colour->uuid]);
        vbBook($seed, 'Expected Customer', '14:00', [$seed['service']->uuid]);

        $finished = app(CheckInAppointment::class)($done, $owner);
        $stage = $finished->stages()->firstOrFail();
        app(TransitionStage::class)($stage, StageStatus::InService, $owner);
        app(TransitionStage::class)($stage->fresh(), StageStatus::Completed, $owner);
        app(CompleteJourney::class)($finished->fresh(), $owner);

        $running = app(CheckInAppointment::class)($busy, $owner);
        app(TransitionStage::class)($running->stages()->firstOrFail(), StageStatus::InService, $owner);

        expect($done->fresh()?->status->value)->toBe('completed');

        Livewire::actingAs($owner)->test(JourneyBoard::class)
            ->set('date', vbDate())
            ->assertOk()
            ->assertSee('Done Customer')        // completed booking stays on its day
            ->assertSee('Busy Customer')
            ->assertSee('Expected Customer')
            ->assertSee(__('manager_visits.lanes.completed'))
            ->assertViewHas('counts', fn (array $counts): bool => $counts['completed'] === 1
                && $counts['in_service'] === 1
                && $counts['not_arrived'] === 1);

        // Two walk-ins today: the row that used to crash on a null appointment.
        foreach (['Walk One', 'Walk Two'] as $name) {
            app(CreateWalkInVisit::class)(
                new WalkInRequest(branchUuid: $seed['branch']->uuid, serviceUuids: [$seed['service']->uuid, $colour->uuid], name: $name),
                $owner,
            );
        }

        Livewire::actingAs($owner)->test(JourneyBoard::class)
            ->assertOk()
            ->assertSee('Walk One')
            ->assertSee('Walk Two')
            ->assertSee(__('manager_visits.card.walk_in'))
            // The next service, read from the walk-in stage's own snapshot.
            ->assertSee($seed['service']->name->get())
            ->assertSee(__('manager_visits.card.progress', ['done' => 0, 'total' => 2]));
    });
});

it('runs the visit drawer through Journey Actions only', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $colour = $this->seedService('Colour', 45, 30000, $seed['employee']);
        $second = $this->seedBookableEmployee($seed['service'], $seed['branch'], 'Sara');

        $appointment = vbBook($seed, 'Drawer Customer', '10:00', [$seed['service']->uuid, $colour->uuid]);

        $board = Livewire::actingAs($owner)->test(JourneyBoard::class)
            ->set('date', vbDate())
            ->call('checkIn', $appointment->uuid)
            ->assertSet('noticeTone', 'success')
            ->assertDispatched('open-visit');

        $journey = ServiceJourney::query()->where('appointment_id', $appointment->id)->firstOrFail();
        [$cut, $dye] = $journey->stages()->get()->all();

        $panel = Livewire::actingAs($owner)->test(VisitPanel::class)
            ->call('open', $journey->uuid)
            ->assertSee('Drawer Customer')
            ->assertSee('Colour');

        // Reassign: only qualified people at this branch are offered.
        $panel->call('showForm', 'reassign', $cut->uuid)
            ->assertSee('Sara')
            ->set('employee', $second->uuid)
            ->call('reassign')
            ->assertSet('noticeTone', 'success');

        expect($cut->fresh()?->employee_id)->toBe($second->id);

        // A manager-only note, then its removal.
        $panel->call('showForm', 'note', $cut->uuid)
            ->set('noteBody', 'Prefers a quiet chair')
            ->set('noteVisibility', 'manager_only')
            ->call('addNote')
            ->assertSee('Prefers a quiet chair')
            ->assertSee(__('manager_visits.visibility.manager_only'));

        $note = $cut->fresh()?->internalNotes()->firstOrFail();
        $panel->call('deleteNote', $cut->uuid, $note->uuid)->assertDontSee('Prefers a quiet chair');

        // Start, then hand on: the first service completes, the next waits.
        $panel->call('start', $cut->uuid);
        expect($cut->fresh()?->status)->toBe(StageStatus::InService);

        $panel->call('showForm', 'handoff', $cut->uuid)->set('reason', 'Ready for colour')->call('handoff');

        expect($cut->fresh()?->status)->toBe(StageStatus::Completed)
            ->and(JourneyHandoff::query()->where('service_journey_id', $journey->id)->count())->toBe(1);

        $panel->assertSee(__('manager_visits.panel.handoffs'))->assertSee('Ready for colour');

        // Skipping needs the customer's reason — refused and translated without one.
        $panel->call('showForm', 'skip', $dye->uuid)->call('skip')
            ->assertSet('notice', __('manager_queue.errors.skip_reason'));

        $panel->set('reason', 'Declined the colour')->call('skip');
        expect($dye->fresh()?->status)->toBe(StageStatus::Skipped);

        // Everything settled: the visit completes, and the booking through Booking.
        $panel->call('complete')->assertSet('noticeTone', 'success');

        expect($journey->fresh()?->status->value)->toBe('completed')
            ->and($appointment->fresh()?->status->value)->toBe('completed');

        $board->call('$refresh')->assertViewHas('counts', fn (array $counts): bool => $counts['completed'] === 1);
    });
});

it('takes a walk-in without the queue, ends visits, and locks without booking', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug, $center): void {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // No queue_management: the same form creates the visit only.
        Livewire::actingAs($owner)->test(WalkInForm::class)
            ->call('start', $seed['branch']->uuid, 'visit')
            ->assertSet('ticket', false)
            ->assertDontSee(__('manager_queue.walk_in.give_number'))
            ->set('name', 'No Queue Walk-in')
            ->set('services', [$seed['service']->uuid])
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('walk-in-created');

        $walkIn = ServiceJourney::query()->where('source', 'walk_in')->firstOrFail();
        expect(QueueTicket::query()->count())->toBe(0);

        // "Customer left" ends a walk-in visit.
        Livewire::actingAs($owner)->test(VisitPanel::class)
            ->call('open', $walkIn->uuid)
            ->call('showForm', 'leave')
            ->set('reason', 'Could not wait')
            ->call('leave')
            ->assertSet('noticeTone', 'success');

        expect($walkIn->fresh()?->status->value)->toBe('aborted');

        // A checked-in booking ended with "cancel booking" goes through Booking.
        $appointment = vbBook($seed, 'Cancel Customer', '12:00', [$seed['service']->uuid]);
        $journey = app(CheckInAppointment::class)($appointment, $owner);

        Livewire::actingAs($owner)->test(VisitPanel::class)
            ->call('open', $journey->uuid)
            ->call('showForm', 'cancel')
            ->call('cancelBooking')
            ->assertSet('noticeTone', 'success');

        expect($appointment->fresh()?->status->value)->toBe('cancelled')
            ->and($journey->fresh()?->status->value)->toBe('aborted');

        // Booking withdrawn: history stays readable, actions disappear, and a
        // crafted check-in is refused on the server and reported.
        $other = vbBook($seed, 'Downgrade Customer', '15:00', [$seed['service']->uuid]);

        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $center['tenant']->id, 'entitlement' => 'booking'],
            ['mode' => OverrideMode::Revoke, 'reason' => 'downgrade', 'expires_at' => null],
        );
        app(Entitlements::class)->invalidate($center['tenant']->id);

        Livewire::actingAs($owner)->test(JourneyBoard::class)
            ->set('date', vbDate())
            ->assertSee('Downgrade Customer')
            ->assertDontSee("checkIn('{$other->uuid}')", false)
            ->call('checkIn', $other->uuid)
            ->assertSet('notice', __('manager_queue.errors.locked'));

        expect(ServiceJourney::query()->where('appointment_id', $other->id)->exists())->toBeFalse();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
