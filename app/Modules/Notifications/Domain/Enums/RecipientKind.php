<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Enums;

/**
 * Who an inbox belongs to.
 *
 * Meta Style has two kinds of person and they are never the same person: a
 * STAFF user in `users`, and a CUSTOMER's login in `customer_accounts`. They
 * authenticate through different guards, live in different tables and have
 * unrelated id sequences.
 *
 * A recipient row therefore carries BOTH this discriminator and the id, and
 * every query filters on both. Storing the id alone would make customer 7's
 * inbox readable by staff user 7 the first time somebody forgot a `where`
 * (docs/23-NOTIFICATIONS.md §4).
 *
 * A guest customer — no account row — has no inbox, and none is invented for
 * them. They are reached by the capability links staff can hand them.
 */
enum RecipientKind: string
{
    case Staff = 'staff';
    case Customer = 'customer';
}
