<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Livewire\Center\Queue\WalkInForm;
use App\Livewire\Center\QueueBoard;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CancelTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Domain\Enums\TicketState;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Phase 8 surfaces
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §§21, 26, 54.
|
| The staff API and the reception screen. The only public queue surface is the
| display feed, which is read-only and carries numbers.
|
*/

function qapSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

it('keeps every writing queue route behind authentication', function (): void {
    $queueRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains((string) $route->uri(), 'queue')
            || str_starts_with((string) $route->uri(), 'q/'))
        ->values();

    expect($queueRoutes)->not->toBeEmpty();

    foreach ($queueRoutes as $route) {
        $uri = (string) $route->uri();
        $methods = array_diff($route->methods(), ['HEAD']);

        $isPublic = str_starts_with($uri, 'q/') || str_starts_with($uri, 'api/v1/queue/');

        if ($isPublic) {
            /*
             * A public queue surface may only READ. The display is a screen on
             * a wall; anything that could change the queue from there would be
             * reachable by whoever can see the television (§26).
             */
            expect($methods)->toBe(['GET'])
                ->and($route->gatherMiddleware())->toContain('public.tenant')
                // The rule the architecture test enforces platform-wide: a
                // public key in a URL must never become a way to act as a
                // center (ADR-036).
                ->and($route->gatherMiddleware())->not->toContain('auth:sanctum')
                ->and($route->gatherMiddleware())->not->toContain('auth:web');

            continue;
        }

        expect($route->gatherMiddleware())->toContain(
            str_starts_with($uri, 'api/') ? 'auth:sanctum' : 'auth:web',
        );
    }
});

it('drives a queue from walk-in to completion through the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => qapSeed());

    $created = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/queue/walk-ins', [
            'branch' => $seed['branch']->uuid,
            'services' => [$seed['service']->uuid],
            'name' => 'Sara Ahmed',
            'idempotency_token' => 'surface-token-1',
        ])
        ->assertStatus(201);

    $ticketUuid = $created->json('data.ticket.uuid');

    expect($created->json('data.ticket.number'))->toBe('A001')
        ->and($created->json('data.ticket.state'))->toBe('waiting')
        ->and($created->json('data.visit.uuid'))->not->toBeNull();

    // Pressed twice with the same token: one visit, one number (§59).
    $again = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/queue/walk-ins', [
            'branch' => $seed['branch']->uuid,
            'services' => [$seed['service']->uuid],
            'name' => 'Sara Ahmed',
            'idempotency_token' => 'surface-token-1',
        ])
        ->assertStatus(201);

    expect($again->json('data.ticket.uuid'))->toBe($ticketUuid);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/queue/tickets/{$ticketUuid}/call", [
            'service_point' => $seed['reception']->uuid,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.ticket.state', 'called')
        ->assertJsonPath('data.ticket.service_point.code', 'R1');

    // Recall is the same endpoint, because it is the same gesture.
    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/queue/tickets/{$ticketUuid}/call")
        ->assertStatus(200)
        ->assertJsonPath('data.ticket.call_count', 2);

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/queue/tickets/{$ticketUuid}/start")
        ->assertStatus(200)
        ->assertJsonPath('data.ticket.state', 'serving');

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/queue/tickets/{$ticketUuid}/complete")
        ->assertStatus(200)
        ->assertJsonPath('data.ticket.state', 'completed');

    // And the JOURNEY followed, because the queue never wrote it directly.
    $this->asCenter($center['tenant'], function (): void {
        $ticket = QueueTicket::query()->firstOrFail();

        expect($ticket->stage?->status->value)->toBe('completed');
    });
});

it('exposes no endpoint that could complete a ticket behind its service', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => qapSeed());

    $ticketUuid = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/queue/walk-ins', [
            'branch' => $seed['branch']->uuid,
            'services' => [$seed['service']->uuid],
            'name' => 'Sara',
        ])
        ->json('data.ticket.uuid');

    /*
     * `complete` follows the JOURNEY fact. Called on a stage that has not
     * started, it refuses — there is deliberately no way to mark a ticket
     * completed while its service is still waiting (correction 3).
     */
    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/queue/tickets/{$ticketUuid}/complete")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'QUEUE.INVALID_TRANSITION');

    $this->asCenter($center['tenant'], function (): void {
        expect(QueueTicket::query()->firstOrFail()->state)->toBe(TicketState::Waiting);
    });
});

it('returns the board, the history and the print payload', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => qapSeed());

    $ticketUuid = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/queue/walk-ins', [
            'branch' => $seed['branch']->uuid,
            'services' => [$seed['service']->uuid],
            'name' => 'Sara',
        ])
        ->json('data.ticket.uuid');

    $this->withHeaders($headers)
        ->postJson("/api/v1/tenant/queue/tickets/{$ticketUuid}/call", [
            'service_point' => $seed['reception']->uuid,
        ])
        ->assertStatus(200);

    $board = $this->withHeaders($headers)->getJson('/api/v1/tenant/queue/board')->assertStatus(200);

    expect($board->json('data.tickets'))->toHaveCount(1)
        // Staff surface: the customer may be named here, and never on the TV.
        ->and($board->json('data.tickets.0.visit.customer_name'))->toBe('Sara')
        ->and($board->json('data.tickets.0.state'))->toBe('called');

    $detail = $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/queue/tickets/{$ticketUuid}")
        ->assertStatus(200);

    expect($detail->json('data.ticket.history'))->toHaveCount(2)
        ->and($detail->json('data.ticket.history.0.type'))->toBe('issued')
        ->and($detail->json('data.ticket.history.1.type'))->toBe('called');

    $print = $this->withHeaders($headers)
        ->getJson("/api/v1/tenant/queue/tickets/{$ticketUuid}/print")
        ->assertStatus(200);

    expect($print->json('data.ticket.number'))->toBe('A001')
        // Nothing about the customer goes on the paper.
        ->and($print->json('data.ticket'))->not->toHaveKey('customer_name');
});

it('manages service points and displays through the API', function (): void {
    $center = $this->registerCenter();
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));

    $seed = $this->asCenter($center['tenant'], fn (): array => qapSeed());

    $point = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/queue/service-points', [
            'branch' => $seed['branch']->uuid,
            'name' => ['en' => 'Laser Room 2'],
            'display_code' => 'L2',
            'ticket_prefix' => 'l',
        ])
        ->assertStatus(201);

    // Normalised on the way in, so `l` and `L` cannot become two sequences.
    expect($point->json('data.service_point.ticket_prefix'))->toBe('L');

    // A duplicate code at one branch would make a call ambiguous on a screen.
    $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/queue/service-points', [
            'branch' => $seed['branch']->uuid,
            'name' => ['en' => 'Another desk'],
            'display_code' => 'L2',
        ])
        ->assertStatus(422);

    $display = $this->withHeaders($headers)
        ->postJson('/api/v1/tenant/queue/displays', [
            'branch' => $seed['branch']->uuid,
            'name' => 'Entrance TV',
            'locale' => 'ar',
            'voice_locales' => ['ar', 'en'],
        ])
        ->assertStatus(201);

    // Returned ONCE, to the person configuring the screen: it is the URL they
    // have to open on it.
    expect($display->json('data.display.public_key'))->toHaveLength(32);

    $list = $this->withHeaders($headers)
        ->getJson('/api/v1/tenant/queue/service-points')
        ->assertStatus(200);

    expect($list->json('data.service_points'))->toHaveCount(2);
});

it('refuses queue work without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qapSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = app(CreateWalkInTicket::class)(
            new WalkInRequest(
                branchUuid: $seed['branch']->uuid,
                serviceUuids: [$seed['service']->uuid],
                name: 'Sara',
            ),
            $owner,
        )['ticket'];

        $viewer = $this->staffWith([Permission::QueueView], 'viewer@alpha.test');

        expect(fn (): QueueTicket => app(CallTicket::class)($ticket, $viewer))
            ->toThrow(AuthorizationException::class, 'may not call');

        expect(fn (): QueueTicket => app(CancelTicket::class)($ticket, $viewer))
            ->toThrow(AuthorizationException::class, 'may not cancel');
    });
});

it('renders the reception queue screen', function (): void {
    $center = $this->registerCenter();
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function (): void {
        qapSeed();
    });

    // On the center's own host since the Manager moved to `{center}.…/manager`;
    // the old `/center/queue` path no longer exists.
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        $this->actingAs($owner);

        $this->get("http://{$slug}.localhost:8000/manager/queue")->assertOk()->assertSee('Queue');
    });
});

it('creates a walk-in and issues a ticket from the screen', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qapSeed();

        $this->actingAs($this->ownerWithCatalogAccess(), 'web');

        // The board opens the walk-in form; the form (a child component, so a
        // poll never wipes it) runs CreateWalkInTicket.
        Livewire::test(QueueBoard::class)
            ->set('branch', $seed['branch']->uuid)
            ->call('openWalkIn')
            ->assertDispatched('open-walk-in');

        Livewire::test(WalkInForm::class)
            ->call('start', $seed['branch']->uuid, 'queue')
            ->set('name', 'Sara Ahmed')
            ->set('services', [$seed['service']->uuid])
            ->call('save')
            ->assertOk()
            ->assertSet('open', false);

        $ticket = QueueTicket::query()->firstOrFail();

        expect($ticket->display_number)->toBe('A001')
            ->and($ticket->state)->toBe(TicketState::Waiting)
            // Through the Action, so the visit exists and is a walk-in.
            ->and($ticket->journey?->isWalkIn())->toBeTrue();
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
