<?php

declare(strict_types=1);

use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Application\NotificationPresenter;
use App\Modules\Reviews\Application\RatingSummary;
use App\Modules\Reviews\Application\ReviewPresenter;
use App\Modules\Reviews\Application\ReviewsQuery;

/*
|--------------------------------------------------------------------------
| The Reviews and Notifications boundary
|--------------------------------------------------------------------------
|
| docs/22-REVIEWS.md §2 · docs/23-NOTIFICATIONS.md §2 · docs/04-MODULE-BOUNDARIES.md.
|
| Both sit at the TOP. Reviews reads the visit, the catalog, the team and — for
| traceability only — the invoice. Notifications reads everything and is
| imported by nothing: modules emit facts, and this one listens. The day
| somebody "just calls the notifier" from Booking, these fail.
|
| Neither is allowed to become something else: a review is not a financial
| record, and a notification is not the audit log.
|
*/

arch('nothing below reviews depends on reviews')
    ->expect([
        'App\Modules\Booking',
        'App\Modules\ServiceJourney',
        'App\Modules\Queue',
        'App\Modules\Resources',
        'App\Modules\Sales',
        'App\Modules\Payments',
        'App\Modules\Finance',
        'App\Modules\Loyalty',
        'App\Modules\Memberships',
        'App\Modules\Packages',
        'App\Modules\Customers',
        'App\Modules\Catalog',
        'App\Modules\Branches',
        'App\Modules\Employees',
        'App\Modules\Menu',
        'App\Kernel',
    ])
    ->not->toUse('App\Modules\Reviews');

arch('nothing anywhere imports notifications except the app wiring')
    ->expect([
        'App\Modules\Booking',
        'App\Modules\ServiceJourney',
        'App\Modules\Queue',
        'App\Modules\Resources',
        'App\Modules\Sales',
        'App\Modules\Payments',
        'App\Modules\Finance',
        'App\Modules\Loyalty',
        'App\Modules\Memberships',
        'App\Modules\Packages',
        'App\Modules\Customers',
        'App\Modules\Catalog',
        'App\Modules\Branches',
        'App\Modules\Employees',
        'App\Modules\Menu',
        'App\Modules\Reviews',
        'App\Kernel',
    ])
    ->not->toUse('App\Modules\Notifications');

arch('reviews and notifications do not depend on HTTP or Livewire')
    ->expect(['App\Modules\Reviews', 'App\Modules\Notifications'])
    ->not->toUse([
        'App\Http\Controllers',
        'App\Livewire',
        'Livewire\Component',
        'Illuminate\Support\Facades\Request',
        'Illuminate\Support\Facades\Auth',
        'Illuminate\Support\Facades\Session',
    ]);

arch('their enums are string backed, so stored values survive a release')
    ->expect(['App\Modules\Reviews\Domain\Enums', 'App\Modules\Notifications\Domain\Enums'])
    ->toBeStringBackedEnum();

arch('a review is never a financial record')
    ->expect('App\Modules\Reviews')
    ->not->toUse(['App\Modules\Finance', 'App\Modules\Payments', 'App\Kernel\Money']);

arch('a notification is never the audit log')
    ->expect('App\Modules\Notifications')
    ->not->toUse('App\Kernel\Audit');

arch('reading reviews or an inbox never moderates, delivers or repairs anything')
    ->expect([ReviewsQuery::class, ReviewPresenter::class, RatingSummary::class, NotificationPresenter::class])
    ->not->toUse([
        'App\Modules\Reviews\Application\Actions',
        'App\Modules\Reviews\Application\ReviewSync',
        NotificationCenter::class,
    ]);

arch('the inbox reads and marks read, and delivers nothing')
    ->expect(Inbox::class)
    ->not->toUse([NotificationCenter::class, 'App\Modules\Notifications\Application\ReminderSweep']);

it('keeps review submission and moderation rules out of controllers and Livewire', function (): void {
    /*
     * Eligibility, the capability check, the rating targets and the moderation
     * trail belong to the Actions. A controller that decided any of them would
     * be a second implementation with its own missing audit row, reachable from
     * one surface and not the other (docs/22-REVIEWS.md §2).
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (preg_match('#^app/(Http|Livewire)/#', $path) !== 1) {
            continue;
        }

        foreach ([
            'ReviewInvitation::query' => 'resolves invitations itself',
            'ReviewRating::query' => 'writes rating rows itself',
            'ReviewToken::hash' => 'resolves a capability token itself',
            "->forceFill(['status' => ReviewStatus" => 'moderates a review itself',
        ] as $needle => $why) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$why.' ('.$needle.')';
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps recipient rules out of controllers and Livewire', function (): void {
    /*
     * WHO receives a notification is decided by the type's audience, the staff
     * targeting rules and the person's preferences — never by a screen. A
     * controller that wrote `NotificationRecipient` rows would be able to put
     * anything in anybody's inbox (docs/23-NOTIFICATIONS.md §5).
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (preg_match('#^app/(Http|Livewire)/#', $path) !== 1) {
            continue;
        }

        foreach (['NotificationRecipient::query', 'Notification::query', 'new NotificationRequest'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('stores a capability secret only as a digest', function (): void {
    /*
     * The plaintext exists in the URL the minting call returned and nowhere
     * else. A `token` column, or a second field beside `token_hash`, would be
     * the whole point of the design undone (docs/22-REVIEWS.md §5, ADR-035).
     */
    $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_20_120001_create_review_invitations_table.php');

    expect($migration)->toBeString()
        ->and((string) $migration)->toContain("char('token_hash', 64)")
        ->and(str_contains((string) $migration, "string('token'"))->toBeFalse('a plaintext token column exists')
        ->and(str_contains((string) $migration, "text('token'"))->toBeFalse('a plaintext token column exists');
});

it('never writes a rendered message, or any markup, into a notification', function (): void {
    /*
     * `notifications.params` carries VALUES; the sentence is built at read time
     * in the reader's own language. A stored sentence would freeze a customer's
     * inbox in whatever language they used last month, and stored markup would
     * be a template injection waiting for its first customer-authored value
     * (docs/23-NOTIFICATIONS.md §7).
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Notifications/')) {
            continue;
        }

        foreach (['view(', 'Blade::', '<br', '<b>', '<span', 'nl2br('] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('builds no messaging channel beyond the inbox', function (): void {
    /*
     * IN-APP is the only channel Notifications has. Creating the recipient row
     * IS delivery; there is no transport, no outbox and no provider
     * (docs/23-NOTIFICATIONS.md §2).
     *
     * Phase 13 put a real WhatsApp channel in the product, and it lives in
     * `Modules\Conversations` — which is why this scans for TRANSPORT rather
     * than for the word "whatsapp". Notifications now legitimately names a
     * `whatsapp_provider_failed` TYPE and listens for a Conversations event;
     * what it must never do is send anything itself.
     *
     * The word match was a proxy for that. These two checks are the property,
     * and they are strictly stronger: a mail transport under an innocent name
     * would have passed the old scan and fails this one.
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Notifications/')) {
            continue;
        }

        // Every way a PHP application sends something out of the building.
        foreach (['Mail::', 'Mailable', 'Notification::send', 'Twilio', 'Firebase', 'FCM', 'APNs', 'WebPush', 'Http::', 'Guzzle', 'curl_', 'fsockopen'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.'  '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('reaches the WhatsApp channel only through a domain event, never its seam', function (): void {
    /*
     * The companion to the rule above, and the one that actually keeps the
     * channel out.
     *
     * Notifications may LISTEN to `Conversations\Domain\Events\*` — that is
     * the permitted direction for any module (CLAUDE.md, "Structure"). It may
     * not touch the messaging seam: no provider contract, no registry, no
     * adapter, no `OutboundMessages`. Holding any of those would make
     * Notifications able to send a WhatsApp message, which is precisely the
     * channel Phase 12 said it would never grow (docs/25-WHATSAPP.md §13).
     */
    $violations = [];

    foreach (appSourceWithoutComments() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Notifications/')) {
            continue;
        }

        if (preg_match_all('/use\s+(App\\\\Modules\\\\Conversations\\\\[A-Za-z0-9_\\\\]+)\s*;/', $contents, $matches) === 0) {
            continue;
        }

        foreach ($matches[1] as $imported) {
            if (! str_starts_with($imported, 'App\\Modules\\Conversations\\Domain\\Events\\')) {
                $violations[] = $path.'  '.$imported;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('puts no tenant column in the Phase 12 schema', function (): void {
    /*
     * A tenant database holds ONE center's data; a `tenant_id` column there is
     * a second, unreliable answer to a question the connection has already
     * answered (docs/02-TENANCY.md).
     */
    $violations = [];

    foreach (glob(dirname(__DIR__, 2).'/database/migrations/tenant/2026_09_20_1200*.php') ?: [] as $path) {
        $contents = (string) file_get_contents($path);

        if (str_contains($contents, "'tenant_id'") || str_contains($contents, 'tenantId')) {
            $violations[] = basename($path);
        }
    }

    expect($violations)->toBe([]);
});
