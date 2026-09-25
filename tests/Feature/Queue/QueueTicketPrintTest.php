<?php

declare(strict_types=1);

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\TicketPrinter;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| The printable ticket
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §17.
|
| 80mm paper, rendered by CSS and printed by the browser. No ESC/POS, no device
| credentials, no print agent.
|
| NO CUSTOMER PII. A queue ticket is left on tables and handed to whoever is
| next: a number, a place, a time.
|
*/

function qpSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['laser'] = test()->seedServicePoint($seed['branch'], 'L2', 'Laser Room 2', prefix: 'L');

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qpTicket(array $seed, User $owner): QueueTicket
{
    return app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: 'Sara Ahmed',
            phone: '+9647512345678',
            idempotencyToken: (string) Str::uuid(),
        ),
        $owner,
        ['service_point' => $seed['laser']->uuid],
    )['ticket'];
}

it('builds a payload with the number, the place and the local time', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qpSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qpTicket($seed, $owner);

        $payload = app(TicketPrinter::class)->payload($ticket, $owner);

        expect($payload['number'])->toBe('L001')
            ->and($payload['branch_name'])->not->toBeNull()
            ->and($payload['destination_code'])->toBe('L2')
            ->and($payload['destination_name'])->toBe('Laser Room 2')
            // The CENTER'S wall clock. A ticket printed in Baghdad showing a
            // UTC time would be an hour out of every conversation about it.
            ->and($payload['issued_at'])->toMatch('/^\d{2}:\d{2}$/')
            ->and($payload['issued_date'])->toMatch('/^\d{4}-\d{2}-\d{2}$/')
            // Direction comes from the language registry, so the same markup
            // prints RTL for Arabic and Kurdish (docs/07 §10).
            ->and($payload['direction'])->toBe('ltr');
    });
});

it('prints no customer PII by default', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qpSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qpTicket($seed, $owner);

        $payload = app(TicketPrinter::class)->payload($ticket, $owner);
        $serialised = json_encode($payload);

        expect($serialised)->not->toContain('Sara Ahmed')
            ->not->toContain('+9647')
            ->not->toContain('customer');

        // The SERVICE is allowed, and is the only thing on the paper that says
        // anything about why the customer came.
        expect($payload)->toHaveKey('service_name');
    });
});

it('renders an 80mm page with the number as the largest thing on it', function (): void {
    $center = $this->registerCenter();

    $uuid = $this->asCenter($center['tenant'], function (): string {
        $seed = qpSeed();

        return qpTicket($seed, $this->ownerWithCatalogAccess())->uuid;
    });

    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    /*
     * A real web request on the center's own host, the way a signed-in member
     * of staff reaches it since the Manager moved to `{center}.…/manager` —
     * `tenant` middleware resolves the host before authentication, and the
     * staff account lives in that center's database. (The old `/center/…`
     * path no longer exists.)
     */
    $html = (string) $this->asCenter($center['tenant'], function () use ($owner, $slug, $uuid) {
        $this->actingAs($owner);

        return $this->get("http://{$slug}.localhost:8000/manager/queue/tickets/{$uuid}/print")
            ->assertStatus(200)
            ->getContent();
    });

    expect($html)->toContain('L001')
        ->toContain('80mm')
        ->toContain('dir="ltr"')
        // Still no PII on the paper.
        ->not->toContain('Sara Ahmed')
        ->not->toContain('+9647');
});

it('refuses to print without the permission', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qpSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qpTicket($seed, $owner);

        $viewer = $this->staffWith([Permission::QueueView], 'viewer@alpha.test');

        expect(fn (): array => app(TicketPrinter::class)->payload($ticket, $viewer))
            ->toThrow(AuthorizationException::class, 'may not print');
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
