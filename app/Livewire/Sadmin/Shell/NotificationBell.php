<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Shell;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Modules\PlatformOperations\Application\PlatformAlertFeed;
use App\Modules\PlatformOperations\Domain\Models\PlatformAlert;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The topbar bell.
 *
 * Near-real-time by polling (every 20 seconds, only while the tab is
 * visible): the stack has no socket server, and one light query does not
 * justify one. When the newest unread notification is newer than the last
 * one this browser saw, the component tells the page, which plays a short
 * chime unless the person muted it. Opening the bell never navigates; only
 * "View all" and a notification's own action do.
 */
final class NotificationBell extends Component
{
    /** Newest unread id already announced in this browser session. */
    public int $seen = 0;

    public function mount(PlatformAlertFeed $feed): void
    {
        $user = $this->user();
        $this->seen = $user instanceof PlatformUser ? $feed->latestUnreadId($user) : 0;
    }

    public function poll(PlatformAlertFeed $feed): void
    {
        $user = $this->user();
        if (! $user instanceof PlatformUser) {
            return;
        }
        $newest = $feed->latestUnreadId($user);
        if ($newest > $this->seen) {
            $this->dispatch('platform-notification', count: $feed->unreadCount($user));
        }
        $this->seen = max($this->seen, $newest);
    }

    public function markRead(int $alertId, PlatformAlertFeed $feed): void
    {
        $user = $this->user();
        if ($user instanceof PlatformUser) {
            $feed->markRead($user, $alertId);
        }
    }

    public function markAllRead(PlatformAlertFeed $feed): void
    {
        $user = $this->user();
        if ($user instanceof PlatformUser) {
            $feed->markAllRead($user);
        }
    }

    /**
     * Marks it read and follows its action, when it has one.
     *
     * The bell persists across page changes, so it renders its new count
     * first and only then navigates; a Livewire redirect would leave the
     * badge showing the old number until the next poll.
     */
    public function open(int $alertId, PlatformAlertFeed $feed): void
    {
        $user = $this->user();
        $alert = $user instanceof PlatformUser ? $feed->markRead($user, $alertId) : null;
        $path = $alert instanceof PlatformAlert ? $alert->action_url : null;

        if (is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            $this->js('Livewire.navigate('.json_encode($path, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).')');
        }
    }

    /** Another screen changed what is read: show the current count. */
    #[On('platform-alerts-changed')]
    public function refreshCount(): void {}

    public function render(PlatformAlertFeed $feed): mixed
    {
        $user = $this->user();

        return view('livewire.sadmin.shell.notification-bell', [
            'alerts' => $user instanceof PlatformUser ? $feed->latest($user) : collect(),
            'readIds' => $user instanceof PlatformUser ? $feed->readIds($user) : [],
            'unreadCount' => $user instanceof PlatformUser ? $feed->unreadCount($user) : 0,
        ]);
    }

    private function user(): ?PlatformUser
    {
        $user = auth('platform')->user();

        return $user instanceof PlatformUser ? $user : null;
    }
}
