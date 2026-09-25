<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Domain\Data;

use App\Modules\Conversations\Domain\Enums\DeliveryState;
use Carbon\CarbonImmutable;

/**
 * What the provider says happened to a message the center sent.
 *
 * Meta reports `sent`, `delivered`, `read` and `failed` by callback, each
 * naming the `wamid` it is about. This is how an `unknown` becomes a fact, and
 * how a `pending` that was never acknowledged is finally resolved
 * (docs/25-WHATSAPP.md §11).
 *
 * ## The billing fields are read, never invented
 *
 * Meta's status callback carries a `pricing` object — `billable`,
 * `pricing_model`, `category`. Those are recorded because the PROVIDER stated
 * them. Meta Style never derives a cost, never applies a rate card of its own,
 * and never guesses a category: an invented number on a usage screen is worse
 * than an empty one, because somebody will budget against it (§13).
 */
final readonly class InboundStatus
{
    public function __construct(
        public string $providerMessageId,
        public DeliveryState $state,
        public CarbonImmutable $at,

        /** Meta's conversation category, when the callback states one. */
        public ?string $category = null,

        /** Whether Meta says it charged for this. Null when not stated. */
        public ?bool $billable = null,

        public ?string $errorCode = null,
    ) {}
}
