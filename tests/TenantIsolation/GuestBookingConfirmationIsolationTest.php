<?php

declare(strict_types=1);

use App\Livewire\Center\Integrations\BookingConfirmations;
use App\Modules\Booking\Application\Actions\TransitionAppointment;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Events\AppointmentConfirmed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Conversations\Application\Actions\ConfigureWhatsAppNotifications;
use App\Modules\Conversations\Application\GuestConfirmationReadiness;
use App\Modules\Conversations\Application\GuestConfirmationReconciler;
use App\Modules\Conversations\Domain\Enums\NoticeStatus;
use App\Modules\Conversations\Domain\Models\Conversation;
use App\Modules\Conversations\Domain\Models\Message;
use App\Modules\Conversations\Domain\Models\WhatsAppOutboundNotice;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Guest booking confirmations — tenant isolation
|--------------------------------------------------------------------------
|
| docs/02-TENANCY.md · docs/25-WHATSAPP.md §22. A confirmation is decided,
| recorded and sent inside the center that confirmed the booking: its notice,
| its thread and its message live in that center's database, go out through
| that center's own account, and neither another center's switch nor its
| reconciler can see or send them. The same phone number in two centers is two
| unrelated people.
|
| This is a RELEASE GATE. Never skipped.
|
*/

it('decides, records and sends a guest confirmation only inside the center that confirmed it', function (): void {
    $alpha = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $beta = $this->registerCenter('Salon Beta', 'owner@beta.test');
    $betaOwner = $this->ownerOf($beta['tenant']);

    config(['whatsapp.templates.booking_confirmation' => 'booking_confirmation_v1']);

    $sends = static fn (): int => count(Http::recorded(
        static fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com'),
    )->all());
    $since = CarbonImmutable::now()->subDays(3);

    // Beta owns the channel too, with its own account, and would SEND: its
    // reconciler would confirm anything it could see.
    $this->asCenter($beta['tenant'], function (): void {
        $this->grantWhatsApp();
        $this->seedWhatsAppAccount()->forceFill(['phone_number_id' => '209876543210987'])->save();

        expect(app(GuestConfirmationReadiness::class)->blocker())->toBeNull();
    });

    // Alpha: a CONFIRMED guest booking whose after-commit callback never ran —
    // exactly what a reconciler picks up, and still undecided.
    $alphaAppointment = $this->asCenter($alpha['tenant'], function (): Appointment {
        $this->grantWhatsApp();
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $this->seedWhatsAppAccount();

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                startsAt: CarbonImmutable::parse(CarbonImmutable::now()->addDay()->toDateString().' 10:00', $seed['branch']->timezone)->utc(),
                // The same number Beta's own customer will have.
                customer: CustomerRef::details('Sara Ahmed', '0750 123 4567'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        Event::fakeFor(
            fn () => app(TransitionAppointment::class)($appointment, AppointmentStatus::Confirmed, BookingActor::staff($owner)),
            [AppointmentConfirmed::class],
        );

        expect($appointment->fresh()?->status)->toBe(AppointmentStatus::Confirmed)
            ->and(WhatsAppOutboundNotice::query()->count())->toBe(0);

        return $appointment;
    });

    expect($sends())->toBe(0);

    // Beta's reconciler runs FIRST. Had it read Alpha's database it would find
    // Alpha's undecided booking, send it through Beta's account and record a
    // decision somewhere; it finds nothing.
    $this->asCenter($beta['tenant'], function () use ($since, $sends): void {
        expect(app(GuestConfirmationReconciler::class)->reconcile($since))->toBe(0)
            ->and(WhatsAppOutboundNotice::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and($sends())->toBe(0);
    });

    // Alpha's booking is still undecided — then Alpha's own reconciler sends
    // it, exactly once, through Alpha's account.
    $this->asCenter($alpha['tenant'], function () use ($alphaAppointment, $since, $sends): void {
        expect(WhatsAppOutboundNotice::query()->count())->toBe(0);

        expect(app(GuestConfirmationReconciler::class)->reconcile($since))->toBe(1)
            ->and(app(GuestConfirmationReconciler::class)->reconcile($since))->toBe(0);

        $notice = WhatsAppOutboundNotice::query()->sole();

        expect($notice->status)->toBe(NoticeStatus::Sent)
            ->and($notice->source_uuid)->toBe($alphaAppointment->uuid)
            ->and($notice->attempts)->toBe(1)
            ->and(Message::query()->count())->toBe(1)
            ->and(Conversation::query()->sole()->account?->phone_number_id)->toBe('106540352242922')
            ->and($sends())->toBe(1);
    });

    // Beta switches guest confirmations OFF. Nothing of Alpha's is in Beta's
    // database, and Beta's switch decides only Beta's bookings.
    $this->asCenter($beta['tenant'], function () use ($betaOwner, $alphaAppointment, $since, $sends): void {
        app(ConfigureWhatsAppNotifications::class)->guestBookingConfirmation($betaOwner, false);

        expect(WhatsAppOutboundNotice::query()->count())->toBe(0)
            ->and(Conversation::query()->count())->toBe(0)
            ->and(Message::query()->count())->toBe(0)
            ->and(Appointment::query()->where('uuid', $alphaAppointment->uuid)->exists())->toBeFalse();

        app(GuestConfirmationReconciler::class)->reconcile($since);

        expect($sends())->toBe(1)
            ->and(WhatsAppOutboundNotice::query()->count())->toBe(0);

        // Beta's settings card counts only Beta's own notices.
        expect(app(GuestConfirmationReadiness::class)->summary()['counts'])
            ->toBe(['sent' => 0, 'failed' => 0, 'unconfirmed' => 0, 'skipped' => 0]);

        Livewire::actingAs($betaOwner)->test(BookingConfirmations::class)
            ->assertSet('enabled', false)
            ->assertDontSee('Last 30 days');

        // Beta's own guest with the SAME number: Beta's decision, Beta's row.
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $betaAppointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
                startsAt: CarbonImmutable::parse(CarbonImmutable::now()->addDay()->toDateString().' 11:00', $seed['branch']->timezone)->utc(),
                customer: CustomerRef::details('Another Sara', '0750 123 4567'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        app(TransitionAppointment::class)($betaAppointment, AppointmentStatus::Confirmed, BookingActor::staff($owner));

        $notice = WhatsAppOutboundNotice::query()->sole();

        expect($notice->source_uuid)->toBe($betaAppointment->uuid)
            ->and($notice->status)->toBe(NoticeStatus::Skipped)
            ->and($notice->reason)->toBe('disabled')
            ->and($sends())->toBe(1);
    });

    // Alpha is untouched by everything Beta did.
    $this->asCenter($alpha['tenant'], function (): void {
        expect(WhatsAppOutboundNotice::query()->count())->toBe(1)
            ->and(Message::query()->count())->toBe(1)
            ->and(app(GuestConfirmationReadiness::class)->summary()['counts']['sent'])->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
