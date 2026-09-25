<?php

declare(strict_types=1);

namespace App\Http\Concerns;

use App\Kernel\Http\ApiResponse;
use App\Modules\Notifications\Application\Inbox;
use App\Modules\Notifications\Application\NotificationPreferences;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\PreferenceKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One person's own inbox. The behaviour is here; WHOSE inbox it is, is not.
 *
 * A TRAIT rather than a base controller: controllers in this codebase are
 * final, and an inheritance chain between two of them would be somewhere for a
 * third to appear later with half the behaviour overridden (`ArchitectureTest`).
 *
 * ## The recipient comes from one named guard
 *
 * Never from a body field, a query parameter or a uuid in the path — and never
 * from asking several guards in turn until one answers, which is how a staff
 * request ends up reading a customer's inbox on the second request of a test
 * (docs/11-TESTING-STRATEGY.md, docs/23-NOTIFICATIONS.md §16).
 *
 * So the recipient method is abstract, and each subclass names exactly one
 * guard. The shared behaviour stays shared; the identity cannot be confused.
 *
 * There is no endpoint anywhere that reads somebody else's inbox, for staff or
 * for anyone: a notification is addressed mail.
 *
 * No entitlement gate. Operational notifications are part of running a center,
 * not a feature sold separately (§2).
 */
trait ReadsAnInbox
{
    public function index(Request $request, Inbox $inbox): JsonResponse
    {
        $before = $request->query('before');

        return ApiResponse::data([
            'notifications' => $inbox->page($this->recipient($request), Inbox::PAGE, is_string($before) ? $before : null),
        ]);
    }

    public function unreadCount(Request $request, Inbox $inbox): JsonResponse
    {
        return ApiResponse::data(['unread' => $inbox->unreadCount($this->recipient($request))]);
    }

    public function markRead(Request $request, string $uuid, Inbox $inbox): JsonResponse
    {
        $me = $this->recipient($request);

        // Somebody else's notification is NOT FOUND, not forbidden: a 403 would
        // still confirm that it exists (docs/08-AUDIT-SECURITY.md).
        $inbox->markRead($me, $uuid);

        return ApiResponse::data(['unread' => $inbox->unreadCount($me)]);
    }

    public function markAllRead(Request $request, Inbox $inbox): JsonResponse
    {
        return ApiResponse::data(['marked' => $inbox->markAllRead($this->recipient($request)), 'unread' => 0]);
    }

    public function preferences(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        return ApiResponse::data(['preferences' => $preferences->all($this->recipient($request))]);
    }

    /**
     * Sets one switch. Only the keys that exist can be sent, and only the
     * notifications that name one can be suppressed — a preference can never
     * hide a cancellation, an invoice or a one-star review (§8).
     */
    public function updatePreference(Request $request, NotificationPreferences $preferences): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string', 'in:'.implode(',', array_column(PreferenceKey::cases(), 'value'))],
            'enabled' => ['required', 'boolean'],
        ]);

        $me = $this->recipient($request);

        $preferences->set($me, PreferenceKey::from((string) $validated['key']), (bool) $validated['enabled']);

        return ApiResponse::data(['preferences' => $preferences->all($me)]);
    }

    /**
     * Whose inbox this is. One guard, named by the subclass.
     */
    abstract protected function recipient(Request $request): Recipient;
}
