<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Enums;

/**
 * How loudly an inbox shows something. Two levels, and nothing else.
 *
 * `important` exists because a one-star review a manager has not seen yet is
 * not the same as a membership renewal confirmation. It changes how the row is
 * presented and how long retention keeps it — it is NOT an escalation policy,
 * a paging rule or a delivery guarantee, and Phase 12 deliberately builds none
 * of those (docs/23-NOTIFICATIONS.md §9).
 */
enum NotificationSeverity: string
{
    case Normal = 'normal';
    case Important = 'important';
}
