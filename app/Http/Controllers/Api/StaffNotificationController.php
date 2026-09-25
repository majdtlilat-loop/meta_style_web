<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsAnInbox;
use App\Http\Controllers\Controller;
use App\Kernel\Identity\Models\User;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use Illuminate\Http\Request;

/**
 * A staff member's own inbox.
 *
 * The identity is the `sanctum` guard the route already authenticated with —
 * a STAFF user in `users`, never a customer account, whatever else this
 * process may have resolved earlier (docs/23-NOTIFICATIONS.md §4).
 */
final class StaffNotificationController extends Controller
{
    use ReadsAnInbox;

    protected function recipient(Request $request): Recipient
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        return new Recipient(RecipientKind::Staff, (int) $user->getKey());
    }
}
