<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\NotificationType;
use App\Modules\Notifications\Domain\Enums\PreferenceKey;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use App\Modules\Notifications\Domain\Models\NotificationPreference;

/**
 * "Does this person want this kind of notification?"
 *
 * ## Absent means on
 *
 * A row exists only where somebody turned something off, or back on again. A
 * customer who has never opened the settings has no rows at all and receives
 * everything, and adding a new preference key tomorrow needs no backfill
 * (docs/23-NOTIFICATIONS.md §8).
 *
 * ## A preference cannot hide a fact
 *
 * Only types whose `preference()` names a key are optional. A cancelled
 * appointment, an issued invoice and a one-star review have no key, so
 * {@see allows()} returns true for them whatever is stored — there is no
 * combination of settings that makes the inbox an unreliable record of what the
 * center did.
 */
final class NotificationPreferences
{
    public function allows(Recipient $recipient, NotificationType $type): bool
    {
        $key = $type->preference();

        if ($key === null) {
            // Operational or financial. Not a matter of taste.
            return true;
        }

        $stored = NotificationPreference::query()
            ->where('owner_kind', $recipient->kind->value)
            ->where('owner_id', $recipient->id)
            ->where('preference_key', $key->value)
            ->value('enabled');

        return $stored === null || (bool) $stored;
    }

    /**
     * Every switch and its current answer, for a settings screen.
     *
     * @return array<string, bool>
     */
    public function all(Recipient $recipient): array
    {
        $stored = NotificationPreference::query()
            ->where('owner_kind', $recipient->kind->value)
            ->where('owner_id', $recipient->id)
            ->pluck('enabled', 'preference_key');

        $answers = [];

        foreach (PreferenceKey::cases() as $key) {
            $value = $stored->get($key->value);

            $answers[$key->value] = $value === null || (bool) $value;
        }

        return $answers;
    }

    /**
     * The switches that change anything for this kind of recipient. Every
     * optional type today is addressed to customers, so staff have none: a
     * switch no notification of theirs reads would be a toggle that does
     * nothing.
     *
     * @return list<PreferenceKey>
     */
    public function keysFor(RecipientKind $kind): array
    {
        $keys = [];

        foreach (NotificationType::cases() as $type) {
            $key = $type->preference();

            if ($key !== null && $type->audience() === $kind && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Records one answer. Writing "on" keeps a row rather than deleting it, so
     * the difference between "chose to receive these" and "never looked" stays
     * visible to whoever has to explain a missing notification.
     */
    public function set(Recipient $recipient, PreferenceKey $key, bool $enabled): void
    {
        NotificationPreference::query()->updateOrCreate(
            [
                'owner_kind' => $recipient->kind->value,
                'owner_id' => $recipient->id,
                'preference_key' => $key->value,
            ],
            ['enabled' => $enabled],
        );
    }
}
