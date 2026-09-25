<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Application;

use App\Modules\Customers\Domain\Models\CustomerAccount;
use App\Modules\Notifications\Domain\Data\Recipient;
use App\Modules\Notifications\Domain\Enums\RecipientKind;

/**
 * The inbox a customer actually has — if they have one at all.
 *
 * ## A guest gets nothing, and that is the correct answer
 *
 * A customer is not an account (ADR-041). Most of a center's customers have a
 * record and no login, and inventing a persistent inbox for them would be a
 * pile of unread rows nobody will ever open, attached to a person who cannot
 * sign in to read them (docs/23-NOTIFICATIONS.md §4).
 *
 * So this returns an empty list for a guest, every caller treats that as
 * ordinary, and the things a guest genuinely needs — their invoice, their
 * review link — reach them as capability URLs the desk can hand over.
 *
 * A deactivated account is excluded for the same reason: it cannot be signed
 * in to.
 */
final class CustomerInboxes
{
    /**
     * @return list<Recipient>
     */
    public function forCustomer(?int $customerId): array
    {
        if ($customerId === null || $customerId < 1) {
            return [];
        }

        $ids = CustomerAccount::query()
            ->where('customer_id', $customerId)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id');

        $recipients = [];

        foreach ($ids as $id) {
            $recipients[] = new Recipient(RecipientKind::Customer, (int) $id);
        }

        return $recipients;
    }
}
