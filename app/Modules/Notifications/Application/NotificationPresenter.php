<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Kernel\Platform\Announcements\PlatformAnnouncementText;
use App\Modules\Notifications\Domain\Models\Notification;
use App\Modules\Notifications\Domain\Models\NotificationRecipient;

/**
 * What an inbox row looks like to the person reading it.
 *
 * ## An allow-list, named field by field
 *
 * Never `$model->toArray()` minus a deny-list: a deny-list is defeated by the
 * next column somebody adds, and this payload goes to customers
 * (docs/08-AUDIT-SECURITY.md). Seven fields, all of them listed below, and the
 * numeric `recipient_id`, the `branch_id` and every internal key stay where
 * they are (docs/23-NOTIFICATIONS.md §16).
 *
 * ## The sentence is built here, not stored
 *
 * `notifications.params` holds values; the words come from the translation
 * files at READ time. So a customer who switches to Kurdish sees their whole
 * inbox in Kurdish, including the notifications they received last month, and
 * no customer-authored text and no HTML is ever stored as a message (§7).
 */
final class NotificationPresenter
{
    /**
     * @return array{id: string, type: string, severity: string, message: string, source: array{type: string, id: string}, created_at: string, read_at: string|null}
     */
    public function one(NotificationRecipient $row, Notification $notification): array
    {
        return [
            'id' => $row->uuid,
            'type' => $notification->type->value,
            'severity' => $notification->severity->value,
            'message' => $this->message($notification),
            // The uuid of the thing it is about, so a screen can link to it.
            // Uuids are the identifiers this platform exposes; the numeric keys
            // are not (docs/08-AUDIT-SECURITY.md).
            'source' => ['type' => $notification->source_type, 'id' => $notification->source_uuid],
            'created_at' => $notification->created_at->toIso8601String(),
            'read_at' => $row->read_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<NotificationRecipient>  $rows
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $rows): array
    {
        $presented = [];

        foreach ($rows as $row) {
            $notification = $row->notification;

            if ($notification instanceof Notification) {
                $presented[] = $this->one($row, $notification);
            }
        }

        return $presented;
    }

    /**
     * The localized sentence.
     *
     * Parameters are cast to strings and passed as replacements; nothing is
     * concatenated into the key, so a value can never become part of the
     * lookup. Translations carry no markup, and every surface escapes what it
     * renders (§§7, 16).
     */
    private function message(Notification $notification): string
    {
        // Meta Style's own words, from the control plane, in the reader's
        // language. Plain text; every surface escapes it.
        if ($notification->type->isFromPlatform()) {
            return PlatformAnnouncementText::message($notification->source_uuid, app()->getLocale())
                ?? (string) __($notification->type->messageKey());
        }

        $replacements = [];

        foreach ($notification->params as $key => $value) {
            if (is_scalar($value)) {
                $replacements[$key] = (string) $value;
            }
        }

        return (string) __($notification->type->messageKey(), $replacements);
    }
}
