<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Announcement;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueTicket;
use App\Modules\ServiceJourney\Domain\Data\WalkInRequest;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Voice announcements
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §16, §31.
|
| The server says WHAT to announce; the client says it. These tests are about
| the SEMANTIC PAYLOAD — number, destination, one sentence per language — and
| deliberately nothing about a browser's speech engine, which no test here can
| meaningfully assert against.
|
| Kurdish Sorani is emitted and rendered. It is not reliably SPOKEN: browser
| speech synthesis has effectively no `ckb` voice, so the display announces the
| locales it finds a voice for and plays a chime otherwise. That limitation is
| reported rather than papered over.
|
*/

function qvSeed(): array
{
    $seed = test()->seedBookableCenter();

    // No plan sells the queue, so a center buys it. Without this the
    // Actions below refuse, which is the point of `QueueEntitlementTest`.
    test()->grantQueueEntitlements();

    $seed['laser'] = test()->seedServicePoint($seed['branch'], 'L2', 'Laser Room 2');

    for ($i = 2; $i <= 4; $i++) {
        test()->seedBookableEmployee($seed['service'], $seed['branch'], "Stylist {$i}");
    }

    return $seed;
}

function qvTicket(array $seed, User $owner): QueueTicket
{
    return app(CreateWalkInTicket::class)(
        new WalkInRequest(
            branchUuid: $seed['branch']->uuid,
            serviceUuids: [$seed['service']->uuid],
            name: 'Sara',
            idempotencyToken: (string) Str::uuid(),
        ),
        $owner,
    )['ticket'];
}

it('builds one sentence per language, from templates rather than from PHP', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qvSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qvTicket($seed, $owner);
        app(CallTicket::class)($ticket, $owner, $seed['laser']->uuid);

        $payload = app(Announcement::class)->forTicket($ticket->fresh(), ['en', 'ar', 'ckb']);

        expect($payload['number'])->toBe('A001')
            ->and($payload['destination_code'])->toBe('L2')
            ->and($payload['announcement_id'])->not->toBeNull()
            ->and(array_keys($payload['lines']))->toBe(['en', 'ar', 'ckb']);

        // English reads as English.
        expect($payload['lines']['en'])->toBe('Ticket A001, please proceed to Laser Room 2.');

        /*
         * Arabic and Kurdish are not English with the words swapped. The
         * sentence is a TEMPLATE in each language, and the values are
         * substituted into it — which is the entire reason the server does not
         * build the string (docs/07-LOCALIZATION.md).
         */
        expect($payload['lines']['ar'])->toContain('A001')
            ->and($payload['lines']['ar'])->toContain('Laser Room 2')
            ->and($payload['lines']['ar'])->not->toBe($payload['lines']['en'])
            ->and($payload['lines']['ar'])->toMatch('/[\x{0600}-\x{06FF}]/u');

        expect($payload['lines']['ckb'])->toContain('A001')
            ->and($payload['lines']['ckb'])->not->toBe($payload['lines']['ar'])
            ->and($payload['lines']['ckb'])->toMatch('/[\x{0600}-\x{06FF}]/u');
    });
});

it('announces without a destination when the center has none', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qvSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qvTicket($seed, $owner);

        // A one-room barbershop has nowhere to send anybody but "here", and
        // that is a real configuration rather than a missing one.
        app(CallTicket::class)($ticket, $owner);

        $payload = app(Announcement::class)->forTicket($ticket->fresh(), ['en']);

        expect($payload['destination_code'])->toBeNull()
            ->and($payload['lines']['en'])->toBe('Ticket A001, please come forward.');
    });
});

it('carries a new announcement id on a recall, so the screen speaks again', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qvSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qvTicket($seed, $owner);

        app(CallTicket::class)($ticket, $owner, $seed['laser']->uuid);
        $first = app(Announcement::class)->forTicket($ticket->fresh(), ['en'])['announcement_id'];

        app(CallTicket::class)($ticket->fresh(), $owner, $seed['laser']->uuid);
        $second = app(Announcement::class)->forTicket($ticket->fresh(), ['en'])['announcement_id'];

        // Same number, same destination, DIFFERENT announcement — which is
        // exactly what pressing recall means (§13, correction 2).
        expect($second)->not->toBe($first);
    });
});

it('sends no speech payload at all when a screen is configured silent', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qvSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = $this->seedDisplay($seed['branch'], voice: false);

        $ticket = qvTicket($seed, $owner);
        app(CallTicket::class)($ticket, $owner, $seed['laser']->uuid);

        return $display;
    });

    $response = $this->getJson(
        '/api/v1/queue/'.$center['tenant']->publicKey.'/displays/'.$display->public_key
    )->assertStatus(200);

    // Nothing for a browser to read out, and nothing extra on the wire every
    // three seconds (§16).
    expect($response->json('data.announcement'))->toBeNull()
        ->and($response->json('data.display.voice_enabled'))->toBeFalse()
        // The visual half keeps working regardless, which is the fallback the
        // whole voice design rests on.
        ->and($response->json('data.now_calling.number'))->toBe('A001');
});

it('publishes the locales a screen wants spoken, in order', function (): void {
    $center = $this->registerCenter();

    $display = $this->asCenter($center['tenant'], function (): QueueDisplay {
        $seed = qvSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = $this->seedDisplay($seed['branch']);

        $ticket = qvTicket($seed, $owner);
        app(CallTicket::class)($ticket, $owner, $seed['laser']->uuid);

        return $display;
    });

    $response = $this->getJson(
        '/api/v1/queue/'.$center['tenant']->publicKey.'/displays/'.$display->public_key
    )->assertStatus(200);

    expect($response->json('data.display.voice_locales'))->toBe(['en', 'ar', 'ckb'])
        ->and(array_keys($response->json('data.announcement.lines')))->toBe(['en', 'ar', 'ckb']);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
