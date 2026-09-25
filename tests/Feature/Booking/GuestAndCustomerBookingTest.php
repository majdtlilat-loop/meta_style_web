<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Modules\Booking\Application\AppointmentPresenter;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Enums\BookingSource;
use App\Modules\Booking\Domain\Exceptions\BookingFailed;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Enums\CustomerSource;
use App\Modules\Customers\Domain\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Customer resolution, guest booking and customer-account booking
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §§16, 26, 27, 31, 41.
|
| Booking does not own customer rules — the Customers module does. What Booking
| adds is one rule: a guest booking attaches to the person who already exists,
| never a second copy of them.
|
*/

const GUEST_DATE = '2026-10-14';

/**
 * @param  array<string, mixed>  $seed
 */
function guestBooking(array $seed, string $name, string $phone, string $at = '10:00'): Appointment
{
    return app(BookingEngine::class)->book(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            lines: [new BookingLine($seed['service']->uuid)],
            startsAt: test()->localTime($seed['branch'], GUEST_DATE, $at),
            customer: CustomerRef::details($name, $phone),
        ),
        BookingActor::guest(),
    )->appointment;
}

function grantBooking(string $tenantId, bool $granted = true): void
{
    DB::connection('control')->table('tenant_entitlement_overrides')->updateOrInsert(
        ['tenant_id' => $tenantId, 'entitlement' => 'booking'],
        ['mode' => $granted ? 'grant' : 'revoke', 'source' => 'test', 'created_at' => now(), 'updated_at' => now()],
    );

    app(Entitlements::class)->invalidate($tenantId);
}

it('creates a customer for a guest who has never been here', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $appointment = guestBooking($seed, 'Sara Ahmed', '0750 123 4567');

        $customer = $appointment->customer()->firstOrFail();

        expect(Customer::query()->count())->toBe(1)
            ->and($customer->name)->toBe('Sara Ahmed')
            // Normalised on the way in, like everywhere else.
            ->and($customer->phone)->toBe('+9647501234567')
            ->and($customer->source)->toBe(CustomerSource::Booking)
            // A guest booking creates no login.
            ->and($customer->isRegistered())->toBeFalse()
            ->and($appointment->source)->toBe(BookingSource::PublicWeb);
    });
});

it('attaches a guest booking to the existing customer with that number', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // Reception created her last month, in a different format.
        $existing = $this->seedCustomer('Sara Ahmed', '+964 750 123 4567');

        // She books online later, typing the local form.
        $appointment = guestBooking($seed, 'sara', '0750-123-4567');

        expect(Customer::query()->count())->toBe(1)
            ->and($appointment->customer_id)->toBe($existing->id)
            // Her staff-recorded name is not overwritten by what she typed on a
            // public form.
            ->and($existing->refresh()->name)->toBe('Sara Ahmed');
    });
});

it('tells a guest nothing about a customer their number already matches', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $this->seedCustomer('Fatima Hassan', '0750 123 4567');

        $appointment = guestBooking($seed, 'Someone Else', '0750 123 4567');

        $shape = app(AppointmentPresenter::class)
            ->forCustomer($appointment->load(['items', 'branch']));

        // The booking hangs off the right record, and the response says nothing
        // about whose it is. Otherwise this endpoint would answer "is this
        // number a customer here, and what is their name" for anyone (§16).
        expect(json_encode($shape))->not->toContain('Fatima');
    });
});

it('leaves no customer behind when a guest booking loses the slot', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // The only stylist is taken at 10:00.
        guestBooking($seed, 'First Customer', '0750 111 1111');

        expect(Customer::query()->count())->toBe(1);

        // A second guest, a new phone number, the same slot. The customer would
        // be created and then the booking refused — leaving a person in the
        // center's CRM who was never served and never will be. Resolution
        // happens inside the transaction, so it rolls back with everything else.
        expect(fn (): Appointment => guestBooking($seed, 'Second Customer', '0750 222 2222'))
            ->toThrow(BookingFailed::class);

        expect(Customer::query()->count())->toBe(1)
            ->and(Customer::query()->first()->name)->toBe('First Customer')
            ->and(Appointment::query()->count())->toBe(1);
    });
});

it('refuses a guest booking with no name', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // A privacy rule, not a data-quality one: the obvious fallback is to
        // use the phone number as the name, and a name is never masked
        // (ADR-042).
        expect(fn (): Appointment => guestBooking($seed, '   ', '0750 123 4567'))
            ->toThrow(BookingFailed::class, 'needs a name');

        expect(Customer::query()->count())->toBe(0);
    });
});

it('refuses a guest booking with an unusable phone number', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        expect(fn (): Appointment => guestBooking($seed, 'Sara', 'not a phone'))
            ->toThrow(BookingFailed::class, 'phone number');
    });
});

it('refuses a guest booking against an archived customer, without saying why', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $customer = $this->seedCustomer('Retired Person', '0750 123 4567');
        $customer->forceFill(['archived_at' => CarbonImmutable::now()])->save();

        $failure = null;

        try {
            guestBooking($seed, 'Retired Person', '0750 123 4567');
        } catch (BookingFailed $e) {
            $failure = $e;
        }

        expect($failure)->toBeInstanceOf(BookingFailed::class)
            // Deliberately vague: "that customer is archived" would confirm to
            // an anonymous visitor that the number belongs to somebody here.
            ->and($failure->getMessage())->toContain('contact the center')
            ->and($failure->getMessage())->not->toContain('archived');
    });
});

it('books a signed-in customer against their own linked record', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        $account = $this->seedCustomerAccount($customer);

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], GUEST_DATE, '10:00'),
                // No uuid, no phone: identity comes from the account.
                customer: CustomerRef::self(),
            ),
            BookingActor::customer($account, $customer->name),
        )->appointment;

        expect($appointment->customer_id)->toBe($customer->id)
            ->and($appointment->source)->toBe(BookingSource::CustomerAccount)
            ->and($appointment->created_by_id)->toBe($account->uuid)
            ->and(Customer::query()->count())->toBe(1);
    });
});

it('cannot be told to book for a different customer', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $mine = $this->seedCustomer('Sara Ahmed', '0750 111 1111');
        $theirs = $this->seedCustomer('Fatima Hassan', '0750 222 2222');

        $account = $this->seedCustomerAccount($mine);

        // A customer actor supplying somebody else's uuid. The reference type
        // is what the ENDPOINT constructs, and the customer endpoints construct
        // `self()` unconditionally — but even handed an `existing()` reference,
        // the resolver refuses it for a non-staff actor rather than trusting it
        // (§27).
        expect(fn (): Appointment => app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], GUEST_DATE, '10:00'),
                customer: CustomerRef::existing($theirs->uuid),
            ),
            BookingActor::customer($account, $mine->name),
        )->appointment)->toThrow(BookingFailed::class);

        expect(Appointment::query()->count())->toBe(0);
    });
});

it('lets a customer cancel their own booking and nobody else\'s', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $mine = $this->seedCustomer('Sara Ahmed', '0750 111 1111');
        $theirs = $this->seedCustomer('Fatima Hassan', '0750 222 2222');

        $account = $this->seedCustomerAccount($mine);

        $ownAppointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: CarbonImmutable::now()->addDays(3)->setTime(10, 0)->utc(),
                customer: CustomerRef::self(),
            ),
            BookingActor::customer($account, $mine->name),
        )->appointment;

        $otherAppointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: CarbonImmutable::now()->addDays(3)->setTime(11, 0)->utc(),
                customer: CustomerRef::existing($theirs->uuid),
            ),
            BookingActor::staff($owner),
        )->appointment;

        app(BookingEngine::class)->cancel($ownAppointment, BookingActor::customer($account, $mine->name));

        expect($ownAppointment->refresh()->isCancelled())->toBeTrue()
            ->and($ownAppointment->cancelled_by_type)->toBe('customer');

        expect(fn (): Appointment => app(BookingEngine::class)
            ->cancel($otherAppointment, BookingActor::customer($account, $mine->name)))
            ->toThrow(AuthorizationException::class, 'not yours');

        expect($otherAppointment->refresh()->isCancelled())->toBeFalse();
    });
});

it('refuses a customer trying to confirm or complete their own booking', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $customer = $this->seedCustomer('Sara Ahmed', '0750 111 1111');

        $account = $this->seedCustomerAccount($customer);

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: CarbonImmutable::now()->addDays(3)->setTime(10, 0)->utc(),
                customer: CustomerRef::self(),
            ),
            BookingActor::customer($account, $customer->name),
        )->appointment;

        // Cancelling is the only transition a customer may make. Confirming on
        // their own behalf would make "confirmed" meaningless, and completing
        // is the center's judgement about its own operation (§11).
        foreach ([
            AppointmentStatus::Confirmed,
            AppointmentStatus::Completed,
            AppointmentStatus::NoShow,
        ] as $target) {
            expect(fn (): Appointment => app(BookingEngine::class)
                ->transition($appointment, $target, BookingActor::customer($account, $customer->name)))
                ->toThrow(AuthorizationException::class);
        }
    });
});

it('refuses a customer cancelling a booking that has already started', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();

        $customer = $this->seedCustomer('Sara Ahmed', '0750 111 1111');

        $account = $this->seedCustomerAccount($customer);

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: CarbonImmutable::now()->addDays(3)->setTime(10, 0)->utc(),
                customer: CustomerRef::self(),
            ),
            BookingActor::customer($account, $customer->name),
        )->appointment;

        $appointment->forceFill(['starts_at' => CarbonImmutable::now()->subMinutes(10)])->save();

        expect(fn (): Appointment => app(BookingEngine::class)
            ->cancel($appointment, BookingActor::customer($account, $customer->name)))
            ->toThrow(BookingFailed::class, 'contact the center');

        // Staff are not bound by the customer notice window: the desk has to be
        // able to cancel anything.
        app(BookingEngine::class)->cancel($appointment, BookingActor::staff($owner), 'Did not arrive');

        expect($appointment->refresh()->isCancelled())->toBeTrue();
    });
});

it('refuses every booking action when the center does not own the entitlement', function (): void {
    $center = $this->registerCenter();

    grantBooking($center['tenant']->id, granted: false);

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        // Enforced in the ACTION, not only in middleware — the WhatsApp bot and
        // RAYAN never pass through HTTP middleware (docs/05-ENTITLEMENTS.md §6).
        expect(fn (): Appointment => guestBooking($seed, 'Sara Ahmed', '0750 123 4567'))
            ->toThrow(EntitlementRequired::class);

        expect(Appointment::query()->count())->toBe(0);
    });
});

it('keeps the staff CRM working when booking is switched off', function (): void {
    $center = $this->registerCenter();

    grantBooking($center['tenant']->id, granted: false);

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();

        // Booking is a purchasable capability; keeping customer records is not.
        // A center that has not bought booking still runs its CRM (§31).
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        expect($customer->exists)->toBeTrue()
            ->and(Customer::query()->count())->toBe(1);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
