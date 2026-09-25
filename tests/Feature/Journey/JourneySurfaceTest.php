<?php

declare(strict_types=1);

use App\Livewire\Center\JourneyBoard;
use App\Livewire\Center\Resources;
use App\Modules\Booking\Application\Actions\CreateAppointment;
use App\Modules\Booking\Domain\Data\BookingActor;
use App\Modules\Booking\Domain\Data\BookingLine;
use App\Modules\Booking\Domain\Data\BookingRequest;
use App\Modules\Booking\Domain\Data\CustomerRef;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\ServiceJourney\Domain\Enums\StageStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Phase 7 surfaces
|--------------------------------------------------------------------------
|
| docs/13-ROADMAP.md Phase 7 §§31, 32, 33, 37, 46, 56.
|
| Staff API and staff screens. There is deliberately NO public or customer
| journey surface — a customer does not need to know which room they are in.
|
*/

function svDate(): string
{
    return CarbonImmutable::now()->addDays(29)->format('Y-m-d');
}

function svBook(array $seed): Appointment
{
    return app(CreateAppointment::class)(
        new BookingRequest(
            branchUuid: $seed['branch']->uuid,
            startsAt: test()->localTime($seed['branch'], svDate(), '10:00'),
            lines: [new BookingLine(serviceUuid: $seed['service']->uuid)],
            customer: CustomerRef::details('Sara Ahmed', '+96475'.random_int(10000000, 99999999)),
        ),
        BookingActor::staff(test()->ownerWithCatalogAccess()),
    )->appointment;
}

it('exposes no public or customer journey route', function (): void {
    $journeyRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains((string) $route->uri(), 'journey'))
        ->map(fn ($route): string => (string) $route->uri())
        ->values()
        ->all();

    expect($journeyRoutes)->not->toBeEmpty();

    foreach ($journeyRoutes as $uri) {
        // Everything lives behind the authenticated tenant prefix. A `/m/` or
        // `/customer/` journey route would be an operational workflow on a
        // customer's phone (§46).
        expect($uri)->toStartWith('api/v1/tenant/');
    }
});

it('drives a visit end to end through the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());
    $appointment = $this->asCenter($center['tenant'], fn (): Appointment => svBook($seed));

    $checkIn = $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/appointments/{$appointment->uuid}/check-in")
        ->assertStatus(200);

    $journeyUuid = $checkIn->json('data.journey.uuid');
    $stageUuid = $checkIn->json('data.journey.stages.0.uuid');

    expect($journeyUuid)->not->toBeNull()
        ->and($checkIn->json('data.journey.status'))->toBe('active');

    // Pressed twice: same journey, still 200. Idempotent by construction (§48).
    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/appointments/{$appointment->uuid}/check-in")
        ->assertStatus(200)
        ->assertJsonPath('data.journey.uuid', $journeyUuid);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/journey-stages/{$stageUuid}/start")
        ->assertStatus(200)
        ->assertJsonPath('data.stage.status', StageStatus::InService->value);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/journey-stages/{$stageUuid}/complete")
        ->assertStatus(200)
        ->assertJsonPath('data.stage.status', StageStatus::Completed->value);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/journeys/{$journeyUuid}/complete")
        ->assertStatus(200)
        ->assertJsonPath('data.journey.status', 'completed');

    // And the APPOINTMENT went through the Booking lifecycle, not a direct
    // write from the journey module.
    $this->asCenter($center['tenant'], function () use ($appointment): void {
        expect($appointment->fresh()?->status->value)->toBe('completed');
    });
});

it('shows planned beside actual on the board', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());
    $appointment = $this->asCenter($center['tenant'], fn (): Appointment => svBook($seed));

    $board = $this->withHeaders($headers)
        ->getJson('/api/v1/tenant/journey/board?date='.svDate())
        ->assertStatus(200);

    expect($board->json('data.visits'))->toHaveCount(1)
        // Not arrived: the journey is ABSENT, which is the state (§17).
        ->and($board->json('data.visits.0.group'))->toBe('not_arrived')
        ->and($board->json('data.visits.0.journey'))->toBeNull();

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/appointments/{$appointment->uuid}/check-in")
        ->assertStatus(200);

    $board = $this->withHeaders($headers)
        ->getJson('/api/v1/tenant/journey/board?date='.svDate())
        ->assertStatus(200);

    expect($board->json('data.visits.0.group'))->toBe('waiting')
        // BOTH facts published, so a host can see a divergence (§18, §37).
        ->and($board->json('data.visits.0.journey.stages.0'))
        ->toHaveKeys(['employee', 'booked_employee_uuid', 'planned_starts_at', 'service_started_at']);
});

it('manages resources through the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());

    $type = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/resource-types', ['name' => ['en' => 'Treatment Room']])
        ->assertStatus(201);

    $resource = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/resources', [
            'resource_type' => $type->json('data.uuid'),
            'branch' => $seed['branch']->uuid,
            'name' => ['en' => 'Room 1'],
            'capacity' => 2,
        ])
        ->assertStatus(201);

    $this->withHeaders($headers)
        ->putJson("/api/v1/tenant/services/{$seed['service']->uuid}/resource-requirements", [
            'requirements' => [['type' => $type->json('data.uuid'), 'quantity' => 1]],
        ])
        ->assertStatus(200);

    $list = $this->withHeaders($headers)->getJson('/api/v1/tenant/resources')->assertStatus(200);

    expect($list->json('data.resources'))->toHaveCount(1)
        ->and($list->json('data.resources.0.capacity'))->toBe(2);

    $this->withHeaders($headers)
        ->deleteJson('/api/v1/tenant/resources/'.$resource->json('data.uuid'))
        ->assertStatus(200);
});

it('lists the bookings a new availability block would affect', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => $this->seedBookableCenter());
    $this->asCenter($center['tenant'], fn (): Appointment => svBook($seed));

    $response = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/availability-blocks', [
            'employee' => $seed['employee']->uuid,
            'branch' => $seed['branch']->uuid,
            'starts_at' => svDate().' 09:00',
            'ends_at' => svDate().' 17:00',
            'type' => 'training',
        ])
        ->assertStatus(201);

    // Surfaced, never acted on: the appointment is still exactly where it was
    // (§14).
    expect($response->json('data.affected_appointments'))->toHaveCount(1);

    $this->asCenter($center['tenant'], function (): void {
        expect(Appointment::query()->first()?->status->value)->toBe('booked');
    });
});

it('renders the resource screen', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $this->seedBookableCenter();

        $this->actingAs($this->ownerWithCatalogAccess(), 'web');

        // The page is titled from the Manager navigation ("Rooms & equipment"
        // since the Manager build); assert the translated title, not a word.
        Livewire::test(Resources::class)
            ->assertOk()
            ->assertSee(__('ui.manager_nav.items.resources'));
    });
});

it('renders the operational board', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = $this->seedBookableCenter();
        svBook($seed);

        $this->actingAs($this->ownerWithCatalogAccess(), 'web');

        Livewire::test(JourneyBoard::class)
            ->set('date', svDate())
            ->assertOk()
            // The five operational groupings (§32).
            ->assertSee('Not arrived')
            ->assertSee('In service')
            ->assertSee('Sara Ahmed');
    });
});

it('checks a customer in from the board', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function () use ($center): void {
        // The host normally supplies {center}; an in-process component has
        // none, and the checked-in card now links to the till on that host.
        URL::defaults(['center' => $center['registration']->requested_slug]);

        $seed = $this->seedBookableCenter();
        $appointment = svBook($seed);

        $this->actingAs($this->ownerWithCatalogAccess(), 'web');

        Livewire::test(JourneyBoard::class)
            ->set('date', svDate())
            ->call('checkIn', $appointment->uuid)
            ->assertOk();

        // Through the Action, so the appointment is untouched.
        expect($appointment->fresh()?->status->value)->toBe('booked');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
