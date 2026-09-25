<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Data;

use App\Modules\Notifications\Domain\Enums\NotificationType;

/**
 * One notification somebody is asking for, with the people it is for.
 *
 * ## The source is the idempotency key
 *
 * `sourceType` + `sourceUuid` name the domain fact this is ABOUT — the
 * appointment, the invoice, the review. Together with the type they identify
 * the notification uniquely in the database, so the same fact heard twice, a
 * reconciliation pass and a scheduler that overlaps itself all produce one
 * inbox row (docs/23-NOTIFICATIONS.md §12).
 *
 * ## Params are values, never models
 *
 * `params` carries the small, allow-listed values the message needs — a time, a
 * name the customer already knows, a count. Never an Eloquent model, never
 * rendered text, never HTML, never an internal id, and never anything the
 * recipient is not already entitled to see (§7).
 *
 * @phpstan-type NotificationParams array<string, string|int|float|bool|null>
 */
final readonly class NotificationRequest
{
    /**
     * @param  array<string, string|int|float|bool|null>  $params
     * @param  list<Recipient>  $recipients
     */
    public function __construct(
        public NotificationType $type,
        public string $sourceType,
        public string $sourceUuid,
        public array $params = [],
        public array $recipients = [],
        public ?int $branchId = null,
    ) {}
}
