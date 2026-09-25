<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Points, memberships and packages — the strings a CUSTOMER sees
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md, docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§19, 28.
|
| The benefits section of the customer's own account page. Staff screens keep
| their labels inline, like the rest of the center area.
|
| Two words, never `benefits.php`: a dotless `__('Benefits')` is parsed as a
| translation GROUP, and on a case-insensitive filesystem it would find a
| single-word file and return the whole array. The translation-collision
| architecture test enforces this.
|
*/

return [
    'title' => 'Your benefits',
    'points' => 'Points',
    'points_available' => ':n points available',
    'points_expiring' => ':n points have expired',
    'tier' => 'Tier: :tier',
    'recent' => 'Recent activity',
    'kind_earn' => 'Earned',
    'kind_redeem' => 'Used',
    'kind_adjustment' => 'Adjusted',
    'kind_reversal' => 'Reversed',
    'kind_recovery' => 'Settled against a refund',
    'kind_expiry' => 'Expired',
    'memberships' => 'Memberships',
    'membership_active' => 'Valid until :date',
    'membership_upcoming' => 'Starts :date, valid until :until',
    'every_service' => 'every service',
    'percent_off' => ':percent% off :service',
    'amount_off' => ':amount off :service',
    'uses_left' => ':n left this term',
    'packages' => 'Packages',
    'package_until' => 'Valid until :date',
    'sessions_left' => ':name — :n of :m left',
    'none' => 'Nothing here yet.',
];
