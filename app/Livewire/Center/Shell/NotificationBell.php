<?php

declare(strict_types=1);

namespace App\Livewire\Center\Shell;

use App\Kernel\Identity\Models\User;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Notifications\Domain\Exceptions\NotificationsFailed;
use App\View\Manager\NotificationRows;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Manager topbar bell: the signed-in staff member's own notifications.
 *
 * Their own and nobody else's — the recipient is built from the session's
 * user, never from anything the page sends (docs/23-NOTIFICATIONS.md §16), so
 * marking someone else's row read is simply "not found" in {@see Inbox}.
 *
 * Near-real-time by polling (every 20 seconds, only while the tab is visible).
 * When the newest unread row is newer than the last one this session
 * announced, the bell tells the page, which plays the shared chime unless the
 * person muted it (resources/js/platform/theme.js). The high-water mark lives
 * in the session, never in the page. Opening the bell never navigates; only
 * "View all" does.
 */
final class NotificationBell extends Component
{
    public const PREVIEW = 6;

    private const SEEN = 'manager_bell.seen';

    public function mount(Inbox $inbox): void
    {
        $me = $this->me();
        if ($me !== null && ! session()->has(self::SEEN)) {
            // Whatever was unread before this sign-in is not news.
            session()->put(self::SEEN, $inbox->latestUnreadId($me));
        }
    }

    public function poll(Inbox $inbox): void
    {
        $me = $this->me();
        if ($me === null) {
            return;
        }

        $newest = $inbox->latestUnreadId($me);
        $seen = (int) session(self::SEEN, 0);
        if ($newest > $seen) {
            $this->dispatch('platform-notification', count: $inbox->unreadCount($me));
            session()->put(self::SEEN, $newest);
        }
    }

    public function markRead(string $uuid, Inbox $inbox): void
    {
        $me = $this->me();
        if ($me === null) {
            return;
        }

        try {
            $inbox->markRead($me, $uuid);
        } catch (NotificationsFailed) {
            // Somebody else's, or none at all: the same "not found" either way.
            return;
        }

        $this->dispatch('center-notifications-changed');
    }

    public function markAllRead(Inbox $inbox): void
    {
        $me = $this->me();
        if ($me === null) {
            return;
        }

        $inbox->markAllRead($me);
        $this->dispatch('center-notifications-changed');
    }

    /** Another screen changed what is read: re-render with the current count. */
    #[On('center-notifications-changed')]
    public function refreshCount(): void {}

    public function render(Inbox $inbox, NotificationRows $rows): View
    {
        $me = $this->me();

        return view('livewire.center.shell.notification-bell', [
            'items' => $me === null ? [] : $rows->present($inbox->page($me, self::PREVIEW), $this->user()),
            'unread' => $me === null ? 0 : $inbox->unreadCount($me),
            'viewAll' => Route::has('center.notifications') ? route('center.notifications') : null,
        ]);
    }

    private function user(): ?User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : null;
    }

    private function me(): ?Recipient
    {
        $user = $this->user();

        return $user === null ? null : new Recipient(RecipientKind::Staff, (int) $user->getKey());
    }
}
