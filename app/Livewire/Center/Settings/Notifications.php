<?php

declare(strict_types=1);

namespace App\Livewire\Center\Settings;

use App\Kernel\Identity\Models\User;
use App\Modules\Notifications\Application\NotificationPreferences;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\PreferenceKey;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Route;
use Livewire\Component;

/**
 * Settings → Notifications: the signed-in person's OWN in-app preferences.
 *
 * Exactly the switches the Notifications module allows to be turned off
 * ({@see PreferenceKey}); operational and financial notices cannot be, and are
 * not offered. The recipient is always the session's user — there is no
 * parameter that could reach someone else's preferences (docs/23 §§8, 16).
 * Absent means on.
 */
final class Notifications extends Component
{
    public string $notice = '';

    public function toggle(string $key, NotificationPreferences $preferences): void
    {
        $preference = PreferenceKey::tryFrom($key);

        if ($preference === null) {
            return;
        }

        $me = $this->me();
        $preferences->set($me, $preference, ! ($preferences->all($me)[$key] ?? true));
        $this->notice = __('manager_settings.notifications.saved');
    }

    public function render(NotificationPreferences $preferences): View
    {
        $current = $preferences->all($this->me());

        return view('livewire.center.settings.notifications', [
            'rows' => array_map(static fn (PreferenceKey $key): array => [
                'key' => $key->value,
                'label' => $key->label(),
                'on' => (bool) ($current[$key->value] ?? true),
            ], PreferenceKey::cases()),
            'inboxUrl' => Route::has('center.notifications') ? route('center.notifications') : null,
        ]);
    }

    private function me(): Recipient
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return new Recipient(RecipientKind::Staff, (int) $user->getKey());
    }
}
