<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\TenantAuditLog;
use App\Kernel\Privacy\Fingerprint;
use App\Modules\Booking\Application\Actions\ManageAppointmentNotes;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Models\Appointment;

/*
|--------------------------------------------------------------------------
| Booking audit
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §32 · docs/08-AUDIT-SECURITY.md §§3, 17.
|
| Every schedule mutation is attributable, and no customer phone number, email
| or note body ever reaches an audit row.
|
*/

const AUDIT_DATE = '2026-10-14';

it('attributes a booking to the actor and the channel', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], AUDIT_DATE, '10:00'),
                customer: CustomerRef::details('Sara Ahmed', '0750 123 4567'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        $entry = TenantAuditLog::query()->where('action', 'booking.appointment.created')->firstOrFail();

        expect($entry->actor_type)->toBe('staff')
            ->and($entry->actor_id)->toBe($owner->uuid)
            // Captured now, so the entry stays readable after the receptionist
            // who made the booking has left.
            ->and($entry->actor_label)->toBe($owner->name)
            ->and($entry->meta['source'])->toBe('staff')
            ->and($entry->target_type)->toBe(Appointment::class);
    });
});

it('attributes a guest booking to a guest, with no staff identity', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], AUDIT_DATE, '10:00'),
                customer: CustomerRef::details('Sara Ahmed', '0750 123 4567'),
            ),
            BookingActor::guest(),
        )->appointment;

        $entry = TenantAuditLog::query()->where('action', 'booking.appointment.created')->firstOrFail();

        // The same actor/source model a WhatsApp or RAYAN booking will use
        // (§40) — a future channel changes the two values, not the shape.
        expect($entry->actor_type)->toBe('guest')
            ->and($entry->actor_id)->toBeNull()
            ->and($entry->meta['source'])->toBe('public_web');
    });
});

it('never writes a customer phone number or email into an audit row', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], AUDIT_DATE, '10:00'),
                customer: CustomerRef::details('Sara Ahmed', '0750 123 4567', 'sara@example.com'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        app(BookingEngine::class)->reschedule(
            $appointment,
            $this->localTime($seed['branch'], AUDIT_DATE, '14:00'),
            BookingActor::staff($owner),
        );

        app(BookingEngine::class)->cancel($appointment->refresh(), BookingActor::staff($owner), 'Changed mind');

        $rows = TenantAuditLog::query()->get();
        $dump = $rows->toJson();

        expect($rows)->toHaveCount(3)
            ->and($dump)->not->toContain('+9647501234567')
            ->and($dump)->not->toContain('0750 123 4567')
            ->and($dump)->not->toContain('sara@example.com');

        $created = $rows->firstWhere('action', 'booking.appointment.created');

        // A keyed fingerprint instead. It answers "is this the same number as
        // last week" and reveals nothing on its own — a bare sha256 of an Iraqi
        // mobile is enumerable in seconds (ADR-042).
        expect($created->meta['phone_fingerprint'])->toBe(Fingerprint::of('+9647501234567'))
            ->and($created->meta['phone_fingerprint'])->not->toContain('964')

            // The NAME is the one piece of customer PII deliberately kept, as
            // the target label: a trail of anonymous uuids answers nothing
            // during an investigation.
            ->and($created->target_label)->toBe('Sara Ahmed');
    });
});

it('records that a booking note was written, never what it said', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], AUDIT_DATE, '10:00'),
                customer: CustomerRef::details('Sara Ahmed', '0750 123 4567'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        app(ManageAppointmentNotes::class)->add($appointment, 'Allergic to the blue dye', $owner);

        $entry = TenantAuditLog::query()->where('action', 'booking.appointment_note.created')->firstOrFail();

        // Copying free text into the audit trail duplicates whatever sensitive
        // thing it says into a second table with different readers and
        // different retention (Phase 5 §22).
        expect($entry->meta)->toHaveKey('note_uuid')
            ->and($entry->meta['visibility'])->toBe('internal')
            ->and($entry->meta['length'])->toBe(24)
            ->and($entry->toJson())->not->toContain('Allergic');
    });
});

it('audits every status change with its own action name', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], AUDIT_DATE, '10:00'),
                customer: CustomerRef::details('Sara Ahmed', '0750 123 4567'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        app(BookingEngine::class)->transition($appointment, AppointmentStatus::Confirmed, BookingActor::staff($owner));
        app(BookingEngine::class)->transition($appointment->refresh(), AppointmentStatus::Completed, BookingActor::staff($owner));

        expect(TenantAuditLog::query()->orderBy('id')->pluck('action')->all())->toBe([
            'booking.appointment.created',
            'booking.appointment.confirmed',
            'booking.appointment.completed',
        ]);
    });
});

it('keeps the cancellation reason as the audit reason, not in the diff', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], AUDIT_DATE, '10:00'),
                customer: CustomerRef::details('Sara Ahmed', '0750 123 4567'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        app(BookingEngine::class)->cancel($appointment, BookingActor::staff($owner), 'Customer is ill');

        $entry = TenantAuditLog::query()->where('action', 'booking.appointment.cancelled')->firstOrFail();

        expect($entry->reason)->toBe('Customer is ill')
            ->and($entry->before['status'])->toBe('booked')
            ->and($entry->after['status'])->toBe('cancelled');
    });
});

it('records the price and duration snapshots so a change would be visible', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], AUDIT_DATE, '10:00'),
                customer: CustomerRef::details('Sara Ahmed', '0750 123 4567'),
            ),
            BookingActor::staff($owner),
        )->appointment;

        $entry = TenantAuditLog::query()->where('action', 'booking.appointment.created')->firstOrFail();

        // If a later price change ever DID reach an existing appointment, the
        // audit trail is where it would show up (§32).
        expect($entry->after['items'][0]['price_minor'])->toBe(20000)
            ->and($entry->after['items'][0]['duration_minutes'])->toBe(30)
            ->and($entry->after['items'][0]['currency'])->toBe('IQD')
            ->and($entry->after['timezone'])->toBe('Asia/Baghdad');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
