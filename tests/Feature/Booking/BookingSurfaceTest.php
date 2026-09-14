<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Http\Middleware\EnsureIdempotency;
use App\Kernel\Identity\Actions\IssueApiToken;
use App\Kernel\Identity\TenantApiToken;
use App\Kernel\Tenancy\Infrastructure\StanclTenantResolver;
use App\Livewire\Center\Calendar;
use App\Livewire\Customer\Account as CustomerAccountPage;
use App\Modules\Booking\Contracts\BookingEngine;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Enums\AppointmentStatus;
use App\Modules\Booking\Domain\Enums\BookingSource;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Customers\Domain\Models\Customer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Booking surfaces: staff API, staff calendar, public form, customer account
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 6 §§15, 22, 23, 24, 26, 27, 33, 43.
|
| Every one of these is an ADAPTER. The point of the tests below is that they
| all reach the same engine and get the same answers — and that the public one
| cannot claim to be anything it is not.
|
*/

const SURFACE_DATE = '2026-10-14';

it('books, confirms, moves and completes through the staff API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $availability = $this->withHeaders($headers)->getJson('/api/v1/tenant/availability?'.http_build_query([
        'branch' => $seed['branch']->uuid,
        'from' => SURFACE_DATE,
        'services' => [['service' => $seed['service']->uuid]],
    ]))->assertOk();

    expect($availability->json('data.slots'))->not->toBeEmpty();

    $created = $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', [
            'branch' => $seed['branch']->uuid,
            'starts_at' => $availability->json('data.slots.0.starts_at'),
            'services' => [['service' => $seed['service']->uuid]],
            'customer_name' => 'Sara Ahmed',
            'customer_phone' => '0750 123 4567',
            'note' => 'Please be gentle',
        ])->assertStatus(201);

    $uuid = $created->json('data.uuid');

    expect($uuid)->toBeString()
        // Uuids on the wire, never the auto-increment id.
        ->and($created->json('data'))->not->toHaveKey('id')
        ->and($created->json('data.status'))->toBe('booked')
        ->and($created->json('data.source'))->toBe('staff')
        ->and($created->json('data.customer_note'))->toBe('Please be gentle')
        // ISO-8601 with offset for machines, wall clock for people.
        ->and($created->json('data.starts_at'))->toContain('+00:00')
        ->and($created->json('data.local_start'))->toMatch('/^\d{2}:\d{2}$/')
        ->and($created->json('data.timezone'))->toBe('Asia/Baghdad')
        ->and($created->json('data.total.currency'))->toBe('IQD');

    $this->withHeaders($headers)->postJson("/api/v1/tenant/appointments/{$uuid}/confirm")
        ->assertOk()->assertJsonPath('data.status', 'confirmed');

    $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson("/api/v1/tenant/appointments/{$uuid}/reschedule", [
            'starts_at' => $availability->json('data.slots.8.starts_at'),
        ])->assertOk();

    $this->withHeaders($headers)->postJson("/api/v1/tenant/appointments/{$uuid}/complete")
        ->assertOk()->assertJsonPath('data.status', 'completed');

    $this->withHeaders($headers)->getJson("/api/v1/tenant/appointments/{$uuid}")
        ->assertOk()->assertJsonPath('data.status', 'completed');
});

it('returns the calendar for a date range and refuses an unbounded one', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', [
            'branch' => $seed['branch']->uuid,
            'starts_at' => $this->localTime($seed['branch'], SURFACE_DATE, '10:00')->toIso8601String(),
            'services' => [['service' => $seed['service']->uuid]],
            'customer_name' => 'Sara Ahmed',
            'customer_phone' => '0750 123 4567',
        ])->assertStatus(201);

    $day = $this->withHeaders($headers)
        ->getJson('/api/v1/tenant/calendar?from='.SURFACE_DATE)
        ->assertOk();

    expect($day->json('data.appointments'))->toHaveCount(1);

    // A screen that could ask for "everything" is a screen that one day loads a
    // year of appointments into a browser (§22).
    $this->withHeaders($headers)
        ->getJson('/api/v1/tenant/calendar?from=2026-01-01&to=2026-12-31')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'BOOKING.POLICY_VIOLATION');
});

it('lists future appointments whose employee is no longer active', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', [
            'branch' => $seed['branch']->uuid,
            'starts_at' => CarbonImmutable::now()->addDays(2)->setTime(10, 0)->toIso8601String(),
            'services' => [['service' => $seed['service']->uuid]],
            'customer_name' => 'Sara Ahmed',
            'customer_phone' => '0750 123 4567',
        ])->assertStatus(201);

    $this->asCenter($center['tenant'], function () use ($seed): void {
        $seed['employee']->forceFill(['status' => 'inactive'])->save();
    });

    // Deactivating a stylist must not silently delete or reassign the customers
    // already booked with them. A query is the whole feature (§21).
    $affected = $this->withHeaders($headers)
        ->getJson('/api/v1/tenant/appointments/affected')
        ->assertOk();

    expect($affected->json('data.appointments'))->toHaveCount(1);

    $this->asCenter($center['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(1)
            ->and(Appointment::query()->first()->items()->first()->employee_id)->not->toBeNull();
    });
});

it('refuses to let a public booking claim it came from staff', function (): void {
    $center = $this->registerCenter();
    $key = $this->publicKeyOf($center['tenant']);

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $response = $this->withHeaders(['Accept' => 'application/json', EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson("/api/v1/menu/{$key}/bookings", [
            'branch' => $seed['branch']->uuid,
            'starts_at' => $this->localTime($seed['branch'], SURFACE_DATE, '10:00')->toIso8601String(),
            'services' => [['service' => $seed['service']->uuid]],
            'name' => 'Sara Ahmed',
            'phone' => '0750 123 4567',
            // The forgery attempt. `staff` is what tells an investigation a
            // member of staff made this booking (§15).
            'source' => 'staff',
            'created_by_type' => 'staff',
        ])->assertStatus(201);

    $this->asCenter($center['tenant'], function () use ($response): void {
        $appointment = Appointment::query()->where('uuid', $response->json('data.uuid'))->firstOrFail();

        expect($appointment->source)->toBe(BookingSource::PublicWeb)
            ->and($appointment->created_by_type)->toBe('guest');
    });

    // And the response carries the CUSTOMER shape: no internal source, no
    // record of who created it, no staff notes.
    expect($response->json('data'))->not->toHaveKey('source')
        ->and($response->json('data'))->not->toHaveKey('created_by')
        ->and($response->json('data'))->not->toHaveKey('notes');
});

it('renders the public booking page and takes a guest booking end to end', function (): void {
    $center = $this->registerCenter();
    $key = $this->publicKeyOf($center['tenant']);

    $seed = $this->asCenter($center['tenant'], function (): array {
        $seed = $this->seedBookableCenter();
        $this->publishMenu();

        return $seed;
    });

    // The menu offers the action.
    $this->get("/m/{$key}")->assertOk()->assertSee('Book', false);

    // Step 1: choose a service and a date.
    $page = $this->get("/m/{$key}/book?".http_build_query([
        'branch' => $seed['branch']->uuid,
        'service' => $seed['service']->uuid,
        'date' => SURFACE_DATE,
    ]))->assertOk();

    $page->assertSee('Available times', false)
        // No account required, and the page says so — the single biggest reason
        // a customer abandons a booking form (§26).
        ->assertSee('No account needed', false);

    // Step 3: the form posts, and one appointment exists.
    $this->post("/m/{$key}/book", [
        'branch' => $seed['branch']->uuid,
        'service' => $seed['service']->uuid,
        'starts_at' => $this->localTime($seed['branch'], SURFACE_DATE, '10:00')->toIso8601String(),
        'name' => 'Sara Ahmed',
        'phone' => '0750 123 4567',
        'idempotency_key' => (string) Str::uuid(),
    ])->assertRedirect();

    $this->asCenter($center['tenant'], function (): void {
        expect(Appointment::query()->count())->toBe(1)
            ->and(Appointment::query()->first()->source)->toBe(BookingSource::PublicWeb)
            ->and(Customer::query()->count())->toBe(1);
    });
});

it('does not offer booking on the menu when the center does not own it', function (): void {
    $center = $this->registerCenter();
    $key = $this->publicKeyOf($center['tenant']);

    DB::connection('control')->table('tenant_entitlement_overrides')->insert([
        'tenant_id' => $center['tenant']->id,
        'entitlement' => 'booking',
        'mode' => 'revoke',
        'source' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(Entitlements::class)->invalidate($center['tenant']->id);

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();
        $this->publishMenu();
    });

    // The MENU still renders — it is the center's shop window, and switching it
    // off would punish their customers for a billing decision (§31).
    $this->get("/m/{$key}")->assertOk()->assertDontSee('/book', false);

    // The booking page is simply not there. 404 rather than 403: a guest has no
    // billing relationship with the center.
    $this->get("/m/{$key}/book")->assertNotFound();
});

it('does not show a Book action for a service that must be booked by phone', function (): void {
    $center = $this->registerCenter();
    $key = $this->publicKeyOf($center['tenant']);

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $seed['service']->forceFill(['is_online_bookable' => false])->save();
        $this->publishMenu();
    });

    // A working button that leads to a refusal is worse than no button (§26).
    $this->get("/m/{$key}")->assertOk()->assertDontSee('/book?', false);
});

it('drives the staff calendar and booking desk through Livewire', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        $component = Livewire::actingAs($owner)->test(Calendar::class, ['date' => SURFACE_DATE])
            ->set('date', SURFACE_DATE)
            ->set('branchUuid', $seed['branch']->uuid);

        // Customer → service → find times → book: the flow reception actually
        // performs (§24).
        $component->call('startBooking')
            ->set('bookingDate', SURFACE_DATE)
            ->set('serviceUuid', $seed['service']->uuid)
            ->set('customerUuid', $customer->uuid)
            ->call('findSlots')
            ->assertSet('error', '');

        $slots = $component->get('slots');

        expect($slots)->not->toBeEmpty();

        $component->call('book', $slots[0]['starts_at'])
            ->assertSet('error', '')
            ->assertSet('booking', false);

        $appointment = Appointment::query()->firstOrFail();

        expect($appointment->customer_id)->toBe($customer->id)
            ->and($appointment->source)->toBe(BookingSource::Staff);

        // Lifecycle from the same screen.
        $component->call('open', $appointment->uuid)
            ->call('confirm')
            ->assertSet('error', '');

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);

        $component->set('cancelReason', 'Customer called')->call('cancel');

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled)
            ->and($appointment->cancellation_reason)->toBe('Customer called');
    });
});

it('shows the note advisory on the booking screen', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        $owner = $this->ownerWithCatalogAccess();
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: $this->localTime($seed['branch'], SURFACE_DATE, '10:00'),
                customer: CustomerRef::existing($customer->uuid),
            ),
            BookingActor::staff($owner),
        );

        // The Phase 5 follow-up: an explicit warning in front of the person
        // about to type, not a filter guessing afterwards (§1).
        Livewire::actingAs($owner)->test(Calendar::class, ['date' => SURFACE_DATE])
            ->set('branchUuid', $seed['branch']->uuid)
            ->call('open', $appointment->uuid)
            ->assertSee('not a medical record', false);
    });
});

it('returns the note advisory from the API too', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $created = $this->withHeaders($headers + [EnsureIdempotency::HEADER => (string) Str::uuid()])
        ->postJson('/api/v1/tenant/appointments', [
            'branch' => $seed['branch']->uuid,
            'starts_at' => $this->localTime($seed['branch'], SURFACE_DATE, '10:00')->toIso8601String(),
            'services' => [['service' => $seed['service']->uuid]],
            'customer_name' => 'Sara Ahmed',
            'customer_phone' => '0750 123 4567',
        ])->assertStatus(201);

    $note = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/appointments/'.$created->json('data.uuid').'/notes', [
            'body' => 'Asked for the senior stylist',
        ])->assertStatus(201);

    // A mobile client that never reads the docs still gets a string to render
    // above its own text box.
    expect($note->json('meta.note_advisory'))->toContain('medical');
});

it('lets a signed-in customer see and cancel their own bookings on the web', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();

        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        $account = $this->seedCustomerAccount($customer);

        $appointment = app(BookingEngine::class)->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: CarbonImmutable::now()->addDays(3)->setTime(10, 0)->utc(),
                customer: CustomerRef::self(),
            ),
            BookingActor::customer($account, $customer->name),
        );

        session()->put(StanclTenantResolver::SESSION_KEY, 'irrelevant-in-component-test');

        Livewire::actingAs($account, 'customer')->test(CustomerAccountPage::class)
            ->assertSee('Upcoming bookings', false)
            ->assertSee('Haircut', false)
            ->call('cancel', $appointment->uuid)
            ->assertSet('error', '');

        expect($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled);
    });
});

it('serves a customer their own appointments over the API and nobody else\'s', function (): void {
    $center = $this->registerCenter();

    [$token, $otherUuid] = $this->asCenter($center['tenant'], function () use ($center): array {
        $seed = $this->seedBookableCenter();

        $mine = $this->seedCustomer('Sara Ahmed', '0750 111 1111');
        $theirs = $this->seedCustomer('Fatima Hassan', '0750 222 2222');

        $account = $this->seedCustomerAccount($mine);

        $engine = app(BookingEngine::class);

        $engine->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: CarbonImmutable::now()->addDays(3)->setTime(10, 0)->utc(),
                customer: CustomerRef::self(),
            ),
            BookingActor::customer($account, $mine->name),
        );

        $other = $engine->book(
            new BookingRequest(
                branchUuid: $seed['branch']->uuid,
                lines: [new BookingLine($seed['service']->uuid)],
                startsAt: CarbonImmutable::now()->addDays(3)->setTime(11, 0)->utc(),
                customer: CustomerRef::existing($theirs->uuid),
            ),
            BookingActor::staff($this->ownerWithCatalogAccess()),
        );

        $publicKey = $this->publicKeyOf($center['tenant']);

        $issued = $account->createToken('test', ['*'], CarbonImmutable::now()->addDay());

        return [
            TenantApiToken::format($publicKey, $issued->plainTextToken),
            $other->uuid,
        ];
    });

    $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];

    $mine = $this->withHeaders($headers)->getJson('/api/v1/customer/appointments')->assertOk();

    expect($mine->json('data.appointments'))->toHaveCount(1)
        // A customer who asked for a stylist must be told which one they got.
        // The relation has to be eager-loaded or the presenter renders null and
        // the page silently says nothing.
        ->and($mine->json('data.appointments.0.items.0.employee.name'))->toBe('Ahmed')
        ->and($mine->json('data.appointments.0.items.0.service'))->toBe('Haircut')

        // …and still nothing internal.
        ->and($mine->json('data.appointments.0'))->not->toHaveKey('notes')
        ->and($mine->json('data.appointments.0'))->not->toHaveKey('source');

    // Another customer's appointment is NOT FOUND, not forbidden: a 403 on a
    // real uuid confirms it exists (docs/08-AUDIT-SECURITY.md §19).
    $this->withHeaders($headers)->getJson("/api/v1/customer/appointments/{$otherUuid}")
        ->assertNotFound();
});

it('keeps staff tokens off customer routes and customer tokens off staff routes', function (): void {
    $center = $this->registerCenter();

    $staffToken = $this->apiTokenFor($center['tenant']);

    $customerToken = $this->asCenter($center['tenant'], function () use ($center): string {
        $customer = $this->seedCustomer('Sara Ahmed', '0750 123 4567');

        $account = $this->seedCustomerAccount($customer);

        $issued = $account->createToken('test', ['*'], CarbonImmutable::now()->addDay());

        return TenantApiToken::format(
            $this->publicKeyOf($center['tenant']),
            $issued->plainTextToken,
        );
    });

    // Sanctum compares a token's owner against its guard's provider model, so
    // the boundary is structural rather than remembered (ADR-041).
    $this->withHeaders($this->tokenHeaders($staffToken))
        ->getJson('/api/v1/customer/appointments')
        ->assertUnauthorized();

    $this->app['auth']->forgetGuards();

    $this->withHeaders($this->tokenHeaders($customerToken))
        ->getJson('/api/v1/tenant/calendar?from='.SURFACE_DATE)
        ->assertUnauthorized();
});

it('refuses appointment endpoints to a user without the permission', function (): void {
    $center = $this->registerCenter();

    $token = $this->asCenter($center['tenant'], function () use ($center): string {
        $this->seedBookableCenter();

        $nobody = $this->staffWith([Permission::CustomerView], 'nobody@alpha.test');

        /** @var array{token: string, expires_at: string|null} $issued */
        $issued = app(IssueApiToken::class)($nobody, 'test');

        unset($center);

        return $issued['token'];
    });

    $this->withHeaders($this->tokenHeaders($token))
        ->getJson('/api/v1/tenant/calendar?from='.SURFACE_DATE)
        ->assertForbidden()
        ->assertJsonPath('error.code', 'PERMISSION.DENIED');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
