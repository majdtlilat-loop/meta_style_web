<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ReadsAnInbox;
use App\Http\Controllers\Controller;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\RecipientKind;
use Illuminate\Http\Request;

/**
 * A signed-in customer's own inbox.
 *
 * The identity is the `customer-api` guard, which resolves against the
 * `customers` provider — so a STAFF token cannot reach these routes and a
 * customer token cannot reach the staff ones (Phase 5 §27).
 *
 * A guest has no account and therefore no inbox; nothing here invents one
 * (docs/23-NOTIFICATIONS.md §4).
 */
final class CustomerNotificationController extends Controller
{
    use ReadsAnInbox;

    protected function recipient(Request $request): Recipient
    {
        /** @var CustomerAccount $account */
        $account = $request->user('customer-api');

        return new Recipient(RecipientKind::Customer, (int) $account->getKey());
    }
}
