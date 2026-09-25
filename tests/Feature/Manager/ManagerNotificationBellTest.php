<?php

declare(strict_types=1);

use App\Kernel\Authorization\SystemRole;
use App\Livewire\Center\NotificationInbox;
use App\Livewire\Center\Shell\NotificationBell;
use App\Modules\Notifications\Application\NotificationCenter;
use App\Modules\Notifications\Application\NotificationPreferences;
use App\Modules\Notifications\Domain\Data\NotificationRequest;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Enums\PreferenceKey;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Notifications\Domain\Models\NotificationPreference;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| The Manager bell and inbox
|--------------------------------------------------------------------------
|
| docs/23-NOTIFICATIONS.md §16: a person's own notifications and nobody
| else's. The recipient comes from the session; a uuid of somebody else's row
| is "not found". The bell polls, chimes (through the page) only for something
| newer than this session has seen, and never navigates on its own.
|
*/

function shellBellNotify(int $userId, int $rating = 1): void
{
    app(NotificationCenter::class)->deliver(new NotificationRequest(
        type: NotificationType::LowRatingReceived,
        sourceType: 'review',
        sourceUuid: (string) Str::uuid(),
        params: ['rating' => $rating],
        recipients: [new Recipient(RecipientKind::Staff, $userId)],
    ));
}

it('shows only the viewer\'s own notifications and marks only their own read', function (): void {
    $center = $this->registerCenter('Bell Center', 'owner@bell-center.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app()->setLocale('en');
        $colleague = $this->seedStaffMember(SystemRole::Manager, name: 'Manager Hana');

        shellBellNotify((int) $owner->getKey(), 1);
        shellBellNotify((int) $colleague->getKey(), 2);

        $mine = (string) NotificationRecipient::query()->where('recipient_id', $owner->getKey())->value('uuid');
        $theirs = (string) NotificationRecipient::query()->where('recipient_id', $colleague->getKey())->value('uuid');

        Livewire::actingAs($owner)
            ->test(NotificationBell::class)
            ->assertSee('rated 1 out of 5')
            ->assertDontSee('rated 2 out of 5')
            ->assertSee('<span class="notification-count" aria-hidden="true">1</span>', false)
            ->assertSee(__('notifications_inbox.kind_low_rating_received'))
            // Somebody else's row: nothing happens, however it is asked.
            ->call('markRead', $theirs)
            ->assertHasNoErrors()
            ->call('markRead', $mine)
            ->assertDispatched('center-notifications-changed')
            ->assertDontSee('notification-count', false);

        expect(NotificationRecipient::query()->where('uuid', $theirs)->value('read_at'))->toBeNull()
            ->and(NotificationRecipient::query()->where('uuid', $mine)->value('read_at'))->not->toBeNull();
    });
});

it('chimes only for something newer than the session has seen, and marks everything read', function (): void {
    $center = $this->registerCenter('Bell Poll', 'owner@bell-poll.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        shellBellNotify((int) $owner->getKey());

        // What was unread before the bell first rendered is not news.
        $bell = Livewire::actingAs($owner)->test(NotificationBell::class)
            ->call('poll')
            ->assertNotDispatched('platform-notification');

        shellBellNotify((int) $owner->getKey());

        $bell->call('poll')
            ->assertDispatched('platform-notification', count: 2);

        // Seen now: the next poll stays quiet.
        $bell->call('poll')->assertNotDispatched('platform-notification');

        $bell->call('markAllRead')->assertDispatched('center-notifications-changed');

        expect(NotificationRecipient::query()->where('recipient_id', $owner->getKey())->whereNull('read_at')->count())->toBe(0);
    });
});

it('filters the inbox to unread and loads more a page at a time', function (): void {
    $center = $this->registerCenter('Bell Inbox', 'owner@bell-inbox.test');
    $owner = $this->ownerOf($center['tenant']);
    $slug = $center['registration']->requested_slug;

    $this->asCenter($center['tenant'], function () use ($owner, $slug): void {
        app()->setLocale('en');
        foreach (range(1, 22) as $i) {
            shellBellNotify((int) $owner->getKey(), ($i % 5) + 1);
        }
        $first = (string) NotificationRecipient::query()->where('recipient_id', $owner->getKey())->orderBy('id')->value('uuid');

        $inbox = Livewire::actingAs($owner)->test(NotificationInbox::class)
            ->assertSee(__('notifications_inbox.load_more'))
            ->call('markRead', $first)
            ->assertDispatched('center-notifications-changed')
            ->call('loadMore')
            ->assertSet('pages', 2)
            ->assertDontSee(__('notifications_inbox.load_more'))
            ->call('setFilter', 'unread')
            ->assertSet('filter', 'unread')
            ->assertSet('pages', 1);

        expect(substr_count($inbox->html(), 'data-unread="true"'))->toBe(20);

        // The page itself renders in the shell with no template logic leaking.
        $this->actingAs($owner);
        $html = $this->get("http://{$slug}.localhost:8000/manager/notifications?locale=en&filter=unread")->assertOk()->getContent();
        expect($html)->toContain('aria-pressed="true">Unread')
            ->and(preg_match('/\bnotifications_inbox\.[a-z_]+/', strip_tags($html)))->toBe(0);
    });
});

it('offers staff no switch for a notification only customers receive, and ignores a crafted one', function (): void {
    $center = $this->registerCenter('Bell Prefs', 'owner@bell-prefs.test');
    $owner = $this->ownerOf($center['tenant']);

    $this->asCenter($center['tenant'], function () use ($owner): void {
        app()->setLocale('en');

        // Reminders, expiry warnings and review invitations are addressed to
        // customers; a staff toggle for them would change nothing.
        expect(app(NotificationPreferences::class)->keysFor(RecipientKind::Staff))->toBe([])
            ->and(app(NotificationPreferences::class)->keysFor(RecipientKind::Customer))->not->toBe([]);

        Livewire::actingAs($owner)->test(NotificationInbox::class)
            ->assertDontSee(__('notifications_inbox.preferences'))
            ->assertDontSee(__('notifications_inbox.pref_appointment_reminders'))
            ->call('togglePreference', PreferenceKey::AppointmentReminders->value);

        expect(NotificationPreference::query()->count())->toBe(0);
    });
});

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});
