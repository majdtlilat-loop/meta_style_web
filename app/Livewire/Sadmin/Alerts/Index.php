<?php

declare(strict_types=1);

namespace App\Livewire\Sadmin\Alerts;

use App\Kernel\Platform\Identity\Models\PlatformUser;
use App\Modules\PlatformOperations\Application\PlatformAlertFeed;
use App\Modules\PlatformOperations\Domain\Models\PlatformAlert;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Every platform notification the signed-in person may see — by permission,
 * so a support-only agent sees support events and nothing else. The topbar
 * bell shows the latest few; this is where "View all" leads. Read state is
 * per person.
 */
#[Layout('layouts.superadmin.app')]
final class Index extends Component
{
    #[Url(except: 'all')]
    public string $show = 'all';

    public function setShow(string $show): void
    {
        $this->show = $show === 'unread' ? 'unread' : 'all';
    }

    public function markRead(int $alertId, PlatformAlertFeed $feed): void
    {
        $feed->markRead($this->user(), $alertId);
        $this->dispatch('platform-alerts-changed');
    }

    public function markAllRead(PlatformAlertFeed $feed): void
    {
        $feed->markAllRead($this->user());
        $this->dispatch('platform-alerts-changed');
    }

    /** Marks it read, lets the bell update, then follows the alert's action. */
    public function open(int $alertId, PlatformAlertFeed $feed): void
    {
        $alert = $feed->markRead($this->user(), $alertId);
        $this->dispatch('platform-alerts-changed');
        $path = $alert instanceof PlatformAlert ? $alert->action_url : null;

        if (is_string($path) && str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            $this->js('Livewire.navigate('.json_encode($path, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP).')');
        }
    }

    public function render(PlatformAlertFeed $feed): mixed
    {
        $user = $this->user();
        $readIds = $feed->readIds($user);
        $alerts = $feed->query($user)->latest('id')->limit(200)->get();
        $unread = $alerts->reject(fn (PlatformAlert $alert): bool => in_array((int) $alert->id, $readIds, true));

        return view('livewire.sadmin.alerts.index', [
            'alerts' => $this->show === 'unread' ? $unread->values() : $alerts,
            'readIds' => $readIds,
            'unreadCount' => $unread->count(),
            'total' => $alerts->count(),
        ]);
    }

    private function user(): PlatformUser
    {
        $user = auth('platform')->user();
        abort_unless($user instanceof PlatformUser, 403);

        return $user;
    }
}
