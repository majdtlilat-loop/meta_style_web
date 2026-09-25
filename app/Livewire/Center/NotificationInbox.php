<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Identity\Models\User;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Application\NotificationPreferences;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\PreferenceKey;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Notifications\Domain\Exceptions\NotificationsFailed;
use App\View\Manager\NotificationRows;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The signed-in staff member's own notifications.
 *
 * Their own, and nobody else's: the recipient is built from the session's user
 * and never from anything on the page, so there is no parameter to change and
 * no inbox to reach but this one (docs/23-NOTIFICATIONS.md §16).
 *
 * No permission and no entitlement. Everybody who can sign in has an inbox, and
 * operational notifications are part of running a center rather than a feature
 * sold separately (§2).
 *
 * "Load more" walks the same indexed cursor the API uses, one page per step,
 * and stops at {@see self::MAX_PAGES}: an inbox is for reading, not a report.
 */
#[Layout('components.layouts.app')]
final class NotificationInbox extends Component
{
    public const MAX_PAGES = 10;

    public string $error = '';

    /** `all` or `unread`. */
    #[Url(except: 'all')]
    public string $filter = 'all';

    public int $pages = 1;

    public function setFilter(string $filter): void
    {
        $this->filter = $filter === 'unread' ? 'unread' : 'all';
        $this->pages = 1;
    }

    public function loadMore(): void
    {
        $this->pages = min(self::MAX_PAGES, $this->pages + 1);
    }

    public function markRead(string $uuid, Inbox $inbox): void
    {
        $this->error = '';

        try {
            $inbox->markRead($this->me(), $uuid);
        } catch (NotificationsFailed) {
            // Somebody else's, or none at all: the same "not found", in the reader's language.
            $this->error = __('notifications_inbox.not_found');

            return;
        }

        $this->dispatch('center-notifications-changed');
    }

    public function markAllRead(Inbox $inbox): void
    {
        $this->error = '';

        $inbox->markAllRead($this->me());
        $this->dispatch('center-notifications-changed');
    }

    public function togglePreference(string $key, NotificationPreferences $preferences): void
    {
        $this->error = '';

        $preference = PreferenceKey::tryFrom($key);
        $me = $this->me();

        if ($preference === null || ! in_array($preference, $preferences->keysFor($me->kind), true)) {
            return;
        }

        // Absent means on, so the first toggle turns it off (§8).
        $preferences->set($me, $preference, ! ($preferences->all($me)[$key] ?? true));
    }

    /** The bell changed what is read: show it. */
    #[On('center-notifications-changed')]
    public function refreshList(): void {}

    public function render(Inbox $inbox, NotificationPreferences $preferences, NotificationRows $rows): View
    {
        $me = $this->me();
        $this->filter = $this->filter === 'unread' ? 'unread' : 'all';
        $this->pages = max(1, min(self::MAX_PAGES, $this->pages));

        $loaded = [];
        $before = null;
        $hasMore = false;
        for ($page = 0; $page < $this->pages; $page++) {
            $chunk = $this->filter === 'unread'
                ? $inbox->unreadPage($me, Inbox::PAGE, $before)
                : $inbox->page($me, Inbox::PAGE, $before);
            $loaded = [...$loaded, ...$chunk];
            $hasMore = count($chunk) === Inbox::PAGE;
            if (! $hasMore) {
                break;
            }
            $before = (string) $chunk[count($chunk) - 1]['id'];
        }

        $keys = $preferences->keysFor($me->kind);
        $current = $preferences->all($me);

        return view('livewire.center.notification-inbox', [
            'notifications' => $rows->present($loaded, $this->user()),
            'unread' => $inbox->unreadCount($me),
            'canLoadMore' => $hasMore && $this->pages < self::MAX_PAGES,
            'preferences' => array_map(static fn (PreferenceKey $key): array => [
                'key' => $key->value,
                'label' => $key->label(),
                'on' => (bool) ($current[$key->value] ?? true),
            ], $keys),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function me(): Recipient
    {
        return new Recipient(RecipientKind::Staff, (int) $this->user()->getKey());
    }
}
