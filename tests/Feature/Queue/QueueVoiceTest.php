<?php

declare(strict_types=1);

use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\PlatformHosts;
use App\Modules\Queue\Application\Actions\CallTicket;
use App\Modules\Queue\Application\Actions\CreateWalkInTicket;
use App\Modules\Queue\Application\Actions\StartServingTicket;
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

/**
 * The display feed on the center's own host. Since Phase 15 the public slug in
 * the path must agree with the host (ResolvePublicTenant); the internal public
 * key is no longer a path segment.
 */
function qvFeedUrl(array $center, QueueDisplay $display): string
{
    $slug = (string) $center['registration']->requested_slug;

    return app(PlatformHosts::class)->centerUrl($slug, '/api/v1/queue/'.$slug.'/displays/'.$display->public_key);
}

it('builds one sentence per language, from templates rather than from PHP', function (): void {
    $center = $this->registerCenter();

    $this->asCenter($center['tenant'], function (): void {
        $seed = qvSeed();
        $owner = $this->ownerWithCatalogAccess();

        $ticket = qvTicket($seed, $owner);
        app(CallTicket::class)($ticket, $owner, $seed['laser']->uuid);

        $payload = app(Announcement::class)->forTicket($ticket->fresh(), ['en', 'ar', 'ckb'], 'screen-key');

        expect($payload['number'])->toBe('A001')
            ->and($payload['destination_code'])->toBe('L2')
            // The id is the key it was handed, never the call event's uuid.
            ->and($payload['announcement_id'])->toBe('screen-key')
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

        $payload = app(Announcement::class)->forTicket($ticket->fresh(), ['en'], 'screen-key');

        expect($payload['destination_code'])->toBeNull()
            ->and($payload['lines']['en'])->toBe('Ticket A001, please come forward.');
    });
});

it('carries a new announcement id on a recall, so the screen speaks again', function (): void {
    $center = $this->registerCenter();

    [$display, $seed] = $this->asCenter($center['tenant'], function (): array {
        $seed = qvSeed();
        $owner = $this->ownerWithCatalogAccess();

        $display = $this->seedDisplay($seed['branch']);

        app(CallTicket::class)(qvTicket($seed, $owner), $owner, $seed['laser']->uuid);

        return [$display, $seed];
    });

    $first = $this->getJson(qvFeedUrl($center, $display))->assertOk();

    // The internal identifiers the wire must never carry: both call events'
    // uuids and the ticket's own.
    $internal = $this->asCenter($center['tenant'], function () use ($seed): array {
        $ticket = QueueTicket::query()->firstOrFail();
        $before = (string) $ticket->last_announcement_uuid;

        app(CallTicket::class)($ticket, $this->ownerWithCatalogAccess(), $seed['laser']->uuid);

        return [$before, (string) $ticket->fresh()?->last_announcement_uuid, (string) $ticket->uuid];
    });

    $second = $this->getJson(qvFeedUrl($center, $display))->assertOk();

    // Same number, same destination, DIFFERENT announcement — which is
    // exactly what pressing recall means (§13, correction 2). The words and
    // the line carry the same id, the screen's opaque key for the call.
    expect($first->json('data.announcement.number'))->toBe('A001')
        ->and($second->json('data.announcement.announcement_id'))->not->toBe($first->json('data.announcement.announcement_id'))
        ->and($first->json('data.announcement.announcement_id'))->toBe($first->json('data.now_calling.announcement_id'))
        ->and($second->json('data.announcement.announcement_id'))->toBe($second->json('data.now_calling.announcement_id'))
        ->and($second->json('data.announcement.announcement_id'))->toBe($second->json('data.call_key'))
        ->and($second->json('data.announcement.announcement_id'))->toMatch('/^[0-9a-f]{16}$/');

    // Never the call event's uuid, before or after the recall — anywhere on
    // the wire.
    $body = (string) $first->getContent().(string) $second->getContent();

    foreach ($internal as $uuid) {
        expect($uuid)->not->toBe('')
            ->and(str_contains($body, $uuid))->toBeFalse();
    }
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

    $response = $this->getJson(qvFeedUrl($center, $display))->assertStatus(200);

    // Nothing for a browser to read out, and nothing extra on the wire every
    // three seconds (§16).
    expect($response->json('data.announcement'))->toBeNull()
        ->and($response->json('data.display.voice_enabled'))->toBeFalse()
        // The visual half keeps working regardless, which is the fallback the
        // whole voice design rests on.
        ->and($response->json('data.now_calling.number'))->toBe('A001');
});

it('tells a chime-only screen about a new call with an opaque key, and never with words', function (): void {
    $center = $this->registerCenter();

    [$chimes, $second, $speaks, $seed] = $this->asCenter($center['tenant'], function (): array {
        $seed = qvSeed();

        // Sound on, voice off: no speech payload, but it still has to chime.
        $chimes = $this->seedDisplay($seed['branch'], 'Door TV', voice: false);
        $second = $this->seedDisplay($seed['branch'], 'Hall TV', voice: false);
        $speaks = $this->seedDisplay($seed['branch'], 'Desk TV');

        return [$chimes, $second, $speaks, $seed];
    });

    // Nobody called yet: no key.
    expect($this->getJson(qvFeedUrl($center, $chimes))->assertOk()->json('data.call_key'))->toBeNull();

    $ticket = $this->asCenter($center['tenant'], function () use ($seed): QueueTicket {
        $owner = $this->ownerWithCatalogAccess();
        $ticket = qvTicket($seed, $owner);
        app(CallTicket::class)($ticket, $owner, $seed['laser']->uuid);

        return $ticket->fresh() ?? $ticket;
    });

    $first = $this->getJson(qvFeedUrl($center, $chimes))->assertOk();
    $key = $first->json('data.call_key');

    expect($first->json('data.display.sound_enabled'))->toBeTrue()
        ->and($first->json('data.display.voice_enabled'))->toBeFalse()
        ->and($first->json('data.announcement'))->toBeNull()
        // Opaque: a short digest, never the call event's uuid or the ticket's.
        ->and($key)->toBeString()
        ->and($key)->toMatch('/^[0-9a-f]{16}$/')
        ->and($key)->not->toBe($ticket->last_announcement_uuid)
        ->and($key)->not->toBe($ticket->uuid);

    // The next poll sees the same call: the same key, so no second chime.
    expect($this->getJson(qvFeedUrl($center, $chimes))->assertOk()->json('data.call_key'))->toBe($key);

    // Scoped to the screen: another screen's key for the same call differs, and
    // a screen that speaks gets a key as well as its words.
    $spoken = $this->getJson(qvFeedUrl($center, $speaks))->assertOk();

    expect($this->getJson(qvFeedUrl($center, $second))->assertOk()->json('data.call_key'))->toBeString()->not->toBe($key)
        ->and($spoken->json('data.call_key'))->toBeString()->not->toBe($key)
        ->and($spoken->json('data.announcement.number'))->toBe('A001');

    // A recall is a new call event: a new key, and still no words.
    $this->asCenter($center['tenant'], function () use ($ticket, $seed): void {
        app(CallTicket::class)($ticket->fresh() ?? $ticket, $this->ownerWithCatalogAccess(), $seed['laser']->uuid);
    });

    $recalled = $this->getJson(qvFeedUrl($center, $chimes))->assertOk();

    expect($recalled->json('data.call_key'))->toBeString()->not->toBe($key)
        ->and($recalled->json('data.now_calling.number'))->toBe('A001')
        ->and($recalled->json('data.now_calling.state'))->toBe('called')
        ->and($recalled->json('data.announcement'))->toBeNull();

    // The desk presses Start: the line changes, the call does not — the same
    // key, so the screen does not chime a second time for the same person.
    $this->asCenter($center['tenant'], function () use ($ticket): void {
        app(StartServingTicket::class)($ticket->fresh() ?? $ticket, $this->ownerWithCatalogAccess());
    });

    $serving = $this->getJson(qvFeedUrl($center, $chimes))->assertOk();

    expect($serving->json('data.now_calling.state'))->toBe('serving')
        ->and($serving->json('data.call_key'))->toBe($recalled->json('data.call_key'));
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

    $response = $this->getJson(qvFeedUrl($center, $display))->assertStatus(200);

    expect($response->json('data.display.voice_locales'))->toBe(['en', 'ar', 'ckb'])
        ->and(array_keys($response->json('data.announcement.lines')))->toBe(['en', 'ar', 'ckb']);
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
