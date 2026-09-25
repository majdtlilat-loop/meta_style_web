<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Livewire\Center\Queue\TicketPanel;
use App\Livewire\Center\Queue\WalkInForm;
use App\Livewire\Center\QueueBoard;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Application\Actions\CheckInAppointment;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use App\Modules\ServiceJourney\Domain\Models\ServiceJourney;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager reception queue
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §§14, 15, 19, 21, 22. The screen offers only what the state
| map and the viewer's grants allow, runs every button through the Action the
| API calls, and stays readable (never a 500) when the plan changes.
|
*/

function qmsSeed(): array
{
    $seed = test()->seedBookableCenter();
    test()->grantQueueEntitlements();
    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');

    return $seed;
}

function qmsWalkIn(array $seed, string $name, ?int $priority = null): QueueTicket
{
    return app(CreateWalkInTicket::class)(
        new WalkInRequest(branchUuid: $seed['branch']->uuid, serviceUuids: [$seed['service']->uuid], name: $name),
        test()->ownerWithCatalogAccess(),
        ['priority' => $priority],
    )['ticket'];
}

it('renders the board in every interface language, with lanes and no raw keys', function (): void {
    $center = $this->registerCenter('Queue Screen Center', 'owner@queue-screen.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $seed = qmsSeed();
        qmsWalkIn($seed, 'Sara Ahmed');

        $this->actingAs($owner);

        foreach (['en' => ['ltr', 'Waiting'], 'ar' => ['rtl', 'في الانتظار'], 'ckb' => ['rtl', 'چاوەڕوان']] as $locale => [$direction, $lane]) {
            $html = $this->get("http://{$slug}.localhost:8000/manager/queue?locale={$locale}")
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'" dir="'.$direction.'"', false)
                ->assertSee($lane)
                ->assertSee('A001')
                ->assertSee('Sara Ahmed')
                ->getContent();

            expect(preg_match('/\b(manager_queue|manager_visits|labels|conversations)\.[a-z_]+(\.[a-z_]+)?\b/', strip_tags($html)))->toBe(0)
                // The staff board names the customer and never shows a phone.
                ->and(str_contains($html, '+964'))->toBeFalse();
        }
    });
});

it('offers only legal buttons per state and runs each through its Action', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = qmsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $first = qmsWalkIn($seed, 'First Walk-in');
        $second = qmsWalkIn($seed, 'Second Walk-in');
        $third = qmsWalkIn($seed, 'Third Walk-in');

        $board = Livewire::actingAs($owner)->test(QueueBoard::class)
            ->assertSet('branch', $seed['branch']->uuid)
            // Called to the host's desk.
            ->set('desk', $seed['reception']->uuid)
            ->call('callTicket', $first->uuid)
            ->assertSet('noticeTone', 'success');

        expect($first->fresh()?->state)->toBe(TicketState::Called)
            ->and($first->fresh()?->service_point_id)->toBe($seed['reception']->id);

        // No answer: HELD, recoverable — and a held ticket is never offered "call".
        $board->call('skip', $first->uuid);
        expect($first->fresh()?->state)->toBe(TicketState::Held);

        $html = $board->html();
        expect(str_contains($html, "callTicket('{$first->uuid}')"))->toBeFalse()
            ->and(str_contains($html, "resume('{$first->uuid}')"))->toBeTrue();

        $board->call('resume', $first->uuid);
        expect($first->fresh()?->state)->toBe(TicketState::Waiting);

        // Start and finish go through Journey; the ticket follows the stage.
        $board->call('start', $second->uuid);
        expect($second->fresh()?->state)->toBe(TicketState::Serving)
            ->and($second->fresh()?->stage?->status)->toBe(StageStatus::InService);

        $board->call('finish', $second->uuid);
        expect($second->fresh()?->state)->toBe(TicketState::Completed)
            ->and($second->fresh()?->stage?->status)->toBe(StageStatus::Completed);

        // Urgent is called before an earlier normal ticket.
        Livewire::actingAs($owner)->test(TicketPanel::class)
            ->call('open', $third->uuid)
            ->call('showForm', 'priority')
            ->set('priority', 20)
            ->set('reason', 'Elderly customer')
            ->call('changePriority')
            ->assertSet('noticeTone', 'success');

        $board->call('callNext');
        expect($third->fresh()?->state)->toBe(TicketState::Called)
            ->and($first->fresh()?->state)->toBe(TicketState::Waiting);

        // Transfer back to waiting at another desk; then cancel with a reason.
        $laser = $this->seedServicePoint($seed['branch'], 'L1', 'Laser Room');

        $panel = Livewire::actingAs($owner)->test(TicketPanel::class)
            ->call('open', $third->uuid)
            ->call('showForm', 'transfer')
            ->set('point', $laser->uuid)
            ->call('transfer')
            ->assertSet('noticeTone', 'success');

        expect($third->fresh()?->state)->toBe(TicketState::Waiting)
            ->and($third->fresh()?->service_point_id)->toBe($laser->id);

        $panel->call('showForm', 'cancel')->set('reason', 'Changed their mind')->call('cancelTicket');

        expect($third->fresh()?->state)->toBe(TicketState::Cancelled)
            ->and($third->fresh()?->close_reason)->toBe('Changed their mind');

        // The history is the domain record, in the viewer's language.
        $panel->call('open', $third->uuid)
            ->assertSee(__('manager_queue.events.issued'))
            ->assertSee(__('manager_queue.events.transferred'))
            ->assertSee(__('manager_queue.events.cancelled'));

        // "Customer left" ends the walk-in visit and closes its number.
        Livewire::actingAs($owner)->test(TicketPanel::class)
            ->call('open', $first->uuid)
            ->call('showForm', 'abandon')
            ->call('abandon');

        expect($first->fresh()?->state)->toBe(TicketState::Cancelled)
            ->and($first->fresh()?->journey?->status->value)->toBe('aborted');
    });
});

it('stays readable without the queue and reports a refusal instead of failing', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug, $center): void {
        URL::defaults(['center' => $slug]);
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        // Never bought, never used: the upgrade offer, and nothing else.
        Livewire::actingAs($owner)->test(QueueBoard::class)
            ->assertSee('feature-lock')
            ->assertDontSee('queue-lanes');

        $this->grantQueueEntitlements();
        $seed['reception'] = $this->seedServicePoint($seed['branch']);
        $ticket = qmsWalkIn($seed, 'History Customer');

        TenantEntitlementOverride::query()->updateOrCreate(
            ['tenant_id' => $center['tenant']->id, 'entitlement' => 'queue_management'],
            ['mode' => OverrideMode::Revoke, 'reason' => 'downgrade', 'expires_at' => null],
        );
        app(Entitlements::class)->invalidate($center['tenant']->id);

        $board = Livewire::actingAs($owner)->test(QueueBoard::class)
            ->assertSee('A001')
            ->assertSee(__('manager_queue.read_only'))
            ->assertDontSee("callTicket('{$ticket->uuid}')", false);

        // A crafted call is refused by the Action and shown, never a 500.
        $board->call('callTicket', $ticket->uuid)
            ->assertSet('noticeTone', 'danger')
            ->assertSet('notice', __('manager_queue.errors.locked'));

        expect($ticket->fresh()?->state)->toBe(TicketState::Waiting);
    });
});

it('gives a number to a visit checked in without one', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = qmsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = app(CreateAppointment::class)(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                startsAt: $this->localTime($seed['branch'], CarbonImmutable::now()->addDays(20)->format('Y-m-d'), '11:00'),
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                customer: CustomerRef::details('Booked Guest', '+9647712345678'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        $journey = app(CheckInAppointment::class)($appointment, $owner);
        $stage = $journey->stages()->firstOrFail();

        $board = Livewire::actingAs($owner)->test(QueueBoard::class)
            ->assertSee(__('manager_queue.pending.title'))
            ->assertSee('Booked Guest')
            ->call('issue', $stage->uuid)
            ->assertSet('issuedNumber', 'A001');

        expect(QueueTicket::query()->where('journey_stage_id', $stage->id)->count())->toBe(1);

        // Numbered now, so it has left the "no number yet" list.
        $board->call('$refresh')->assertDontSee(__('manager_queue.pending.title'));
    });
});

it('takes a walk-in once however often the button is pressed, with an E.164 phone', function (): void {
    $center = $this->registerCenter();
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($slug): void {
        URL::defaults(['center' => $slug]);
        $seed = qmsSeed();
        $owner = $this->ownerWithCatalogAccess();

        $form = Livewire::actingAs($owner)->test(WalkInForm::class)
            ->call('start', $seed['branch']->uuid, 'queue')
            ->assertSet('open', true)
            ->assertSet('ticket', true)
            ->set('name', 'Layla Hassan')
            ->set('phoneCountry', 'IQ')
            ->set('phone', '0750 123 4567')
            ->set('services', [$seed['service']->uuid])
            ->set('employee', $seed['employee']->uuid)
            ->set('priority', 10)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('open', false)
            ->assertDispatched('walk-in-created');

        // The same token again: the database answers with the first visit.
        $form->call('save');

        expect(ServiceJourney::query()->count())->toBe(1)
            ->and(QueueTicket::query()->count())->toBe(1)
            ->and(QueueTicket::query()->firstOrFail()->priority)->toBe(10)
            ->and(Customer::query()->where('name', 'Layla Hassan')->value('phone'))->toBe('+9647501234567');

        // Nothing chosen: refused by validation before any Action runs.
        Livewire::actingAs($owner)->test(WalkInForm::class)
            ->call('start', $seed['branch']->uuid, 'queue')
            ->call('save')
            ->assertHasErrors(['services', 'name']);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
