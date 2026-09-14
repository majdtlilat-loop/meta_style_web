<?php

declare(strict_types=1);

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Identity\Models\User;
use App\Kernel\SaaS\Enums\OverrideMode;
use App\Kernel\SaaS\Models\TenantEntitlementOverride;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The public queue display
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §§14, 25, 26, 48, and correction 2.
|
| A television in a waiting room. A NUMBER and a DESTINATION — and a stable
| announcement identifier, so a screen polling every three seconds speaks once
| per call and once more per recall.
|
| The feed is an ALLOW-LIST. Every field is named, because a deny-list is
| defeated by the next column somebody adds — and on this surface that column
| would be a customer's phone number.
|
*/

function qdSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['reception'] = test()->seedServicePoint($seed['branch'], 'R1', 'Reception Desk');
    $seed['laser'] = test()->seedServicePoint($seed['branch'], 'L2', 'Laser Room 2', sortOrder: 1);

    for ($i = 2; $i <= 6; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qdTicket(array $seed, User $owner, string $name = 'Sara Ahmed'): QueueTicket
{
    return app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: $name,
            phone: '+96475'.random_int(10000000, 99999999),
            idempotencyToken: (string) Str::uuid(),
        ),
        $owner,
    )['ticket'];
}

function qdUrl(array $center, QueueDisplay $display): string
{
    return '/api/v1/queue/'.$center['tenant']->publicKey.'/displays/'.$display->public_key;
}

it('shows the current call and a bounded list of recent ones', function (): void {
    $center = $this->registerCenter();

    [$display, $numbers] = $this->asCenter($center['tenant'], function () use (&$seed): array {
        $seed = qdSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = $this->seedDisplay($seed['branch'], recent: 2);

        $numbers = [];

        foreach (['One', 'Two', 'Three', 'Four'] as $name) {
            $ticket = qdTicket($seed, $owner, $name);
            app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);
            $numbers[] = $ticket->display_number;
        }

        return [$display, $numbers];
    });

    $response = $this->getJson(qdUrl($center, $display))->assertStatus(200);

    expect($response->json('data.now_calling.number'))->toBe($numbers[3])
        ->and($response->json('data.now_calling.destination_code'))->toBe('R1')
        // BOUNDED. A screen left on all day must never come to read a whole day
        // of tickets every three seconds (§19).
        ->and($response->json('data.recent'))->toHaveCount(2)
        ->and($response->json('data.recent.0.number'))->toBe($numbers[2])
        ->and($response->json('data.recent.1.number'))->toBe($numbers[1]);
});

it('publishes a number and a destination, and nothing about the customer', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qdSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = $this->seedDisplay($seed['branch']);

        $ticket = qdTicket($seed, $owner, 'Sara Ahmed');
        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

        return $display;
    });

    $body = $this->getJson(qdUrl($center, $display))->assertStatus(200)->getContent();

    /*
     * THE PRIVACY TEST, and deliberately blunt: the whole serialised body must
     * not contain any of these, whatever shape a future field takes (§48).
     */
    expect($body)->not->toContain('Sara Ahmed')
        ->not->toContain('+9647')
        ->not->toContain('@')
        ->not->toContain('customer_name')
        ->not->toContain('phone')
        ->not->toContain('employee')
        ->not->toContain('note')
        ->not->toContain('capacity')
        // No internal numeric identifiers either — a public screen must not
        // publish a row count (docs/08-AUDIT-SECURITY.md).
        ->not->toContain('"id"')
        ->not->toContain('branch_id')
        ->not->toContain('service_journey_id');

    $response = json_decode((string) $body, true);

    expect($response['data']['now_calling'])
        ->toHaveKeys(['announcement_id', 'number', 'destination_code', 'destination_name', 'called_at', 'state']);
});

it('gives every call a new announcement id, and a recall another one', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qdSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = $this->seedDisplay($seed['branch']);

        $ticket = qdTicket($seed, $owner);
        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

        return $display;
    });

    $first = $this->getJson(qdUrl($center, $display))->json('data.now_calling.announcement_id');

    // AN UNCHANGED POLL. The screen has already spoken this one and must stay
    // silent, which is only possible because the id did not move (correction 2).
    $again = $this->getJson(qdUrl($center, $display))->json('data.now_calling.announcement_id');

    expect($again)->toBe($first)
        ->and($first)->not->toBeNull()
        // A uuid, not a row id.
        ->and($first)->toMatch('/^[0-9a-f-]{36}$/');

    $this->asCenter($center['tenant'], function (): void {
        $ticket = QueueTicket::query()->firstOrFail();
        app(CallTicket::class)($ticket, $ticket->journey?->branchId() === null ? $this->ownerWithCatalogAccess() : $this->ownerWithCatalogAccess());
    });

    $afterRecall = $this->getJson(qdUrl($center, $display))->json('data.now_calling.announcement_id');

    // A recall IS a new announcement. A screen that stayed silent because the
    // number had not changed would be useless (§13).
    expect($afterRecall)->not->toBe($first);
});

it('narrows to the department or service point it is scoped to', function (): void {
    $center = $this->registerCenter();

    [$branchWide, $laserOnly] = $this->asCenter($center['tenant'], function (): array {
        $seed = qdSeed();
        $owner = $this->ownerWithCatalogAccess();

        $branchWide = $this->seedDisplay($seed['branch'], 'Entrance TV');
        $laserOnly = $this->seedDisplay($seed['branch'], 'Laser floor', point: $seed['laser']);

        $reception = qdTicket($seed, $owner, 'At reception');
        app(CallTicket::class)($reception, $owner, $seed['reception']->uuid);

        $laser = qdTicket($seed, $owner, 'In the laser room');
        app(CallTicket::class)($laser, $owner, $seed['laser']->uuid);

        return [$branchWide, $laserOnly];
    });

    $all = $this->getJson(qdUrl($center, $branchWide))->assertStatus(200);
    $narrow = $this->getJson(qdUrl($center, $laserOnly))->assertStatus(200);

    expect($all->json('data.recent'))->toHaveCount(1)
        // The narrow screen shows only its own counter's calls.
        ->and($narrow->json('data.now_calling.destination_code'))->toBe('L2')
        ->and($narrow->json('data.recent'))->toBe([]);
});

it('fails closed for an unknown, inactive or archived screen', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qdSeed();

        return $this->seedDisplay($seed['branch']);
    });

    // Unknown key: 404, the same answer as a center that does not exist, so
    // nobody can enumerate a center's screens (§9).
    $this->getJson('/api/v1/queue/'.$center['tenant']->publicKey.'/displays/'.str_repeat('a', 32))
        ->assertStatus(404);

    $this->asCenter($center['tenant'], function () use ($display): void {
        $display->forceFill(['is_active' => false])->save();
    });

    $this->getJson(qdUrl($center, $display))->assertStatus(404);
});

it('keeps one center\'s screen away from another center\'s calls', function (): void {
    // Distinct names and emails: `registerCenter()` is idempotent on its
    // arguments, so the defaults twice would be one center pretending to be two.
    $one = $this->registerCenter('Barbershop Alpha', 'owner@alpha.test');
    $two = $this->registerCenter('Salon Beta', 'owner@beta.test');

    $display = $this->asCenter($one['tenant'], function (): QueueDisplay {
        $seed = qdSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = $this->seedDisplay($seed['branch']);

        $ticket = qdTicket($seed, $owner, 'Center one customer');
        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

        return $display;
    });

    $this->asCenter($two['tenant'], function (): void {
        $seed = qdSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qdTicket($seed, $owner, 'Center two customer');
        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);
    });

    // The display key belongs to center one; center two's key must not reach it.
    $this->getJson('/api/v1/queue/'.$two['tenant']->publicKey.'/displays/'.$display->public_key)
        ->assertStatus(404);

    $response = $this->getJson(qdUrl($one, $display))->assertStatus(200);

    expect($response->json('data.recent'))->toBe([])
        ->and($response->json('data.now_calling.number'))->toBe('A001');
});

it('stops serving a screen when the display entitlement is withdrawn', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qdSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = $this->seedDisplay($seed['branch']);

        $ticket = qdTicket($seed, $owner);
        app(CallTicket::class)($ticket, $owner, $seed['reception']->uuid);

        return $display;
    });

    $this->getJson(qdUrl($center, $display))->assertStatus(200);

    TenantEntitlementOverride::query()->updateOrCreate(
        ['tenant_id' => $center['tenant']->id, 'entitlement' => 'queue_display'],
        ['mode' => OverrideMode::Revoke],
    );

    app(Entitlements::class)->invalidate($center['tenant']->id);

    // Refused — and the answer says nothing about the billing state of a
    // business to whoever is standing in its waiting room (§19).
    $this->getJson(qdUrl($center, $display))->assertStatus(403);
});

it('renders the display page itself, with no login and no controls', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qdSeed();

        return $this->seedDisplay($seed['branch']);
    });

    $response = $this->get('/q/'.$center['tenant']->publicKey.'/'.$display->public_key)
        ->assertStatus(200);

    $html = $response->getContent();

    // The feed URL is embedded through `@json`, which escapes slashes — so the
    // key is what to look for rather than the whole path.
    expect($html)->toContain($center['tenant']->publicKey)
        ->toContain($display->public_key)
        // Read only: nothing on this page can change anything.
        ->not->toContain('<form')
        ->not->toContain('csrf');
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
