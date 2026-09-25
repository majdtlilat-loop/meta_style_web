<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain\Data;

use App\Modules\Notifications\Domain\Enums\RecipientKind;

/**
 * One inbox: which KIND of person, and which one of them.
 *
 * The pair is the identity. Staff user 7 and customer account 7 are different
 * people in different tables, so neither half means anything on its own
 * (docs/23-NOTIFICATIONS.md §4).
 */
final readonly class Recipient
{
    public function __construct(
        public RecipientKind $kind,
        public int $id,
    ) {}

    public function key(): string
    {
        return $this->kind->value.':'.$this->id;
    }
}
