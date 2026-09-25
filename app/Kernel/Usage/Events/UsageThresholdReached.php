<?php

declare(strict_types=1);

namespace App\Kernel\Usage\Events;

use App\Kernel\Usage\UsageStatus;
use Carbon\CarbonImmutable;

/**
 * A center has crossed a usage threshold, for the first time this period.
 *
 * ## Why an event and not a notification
 *
 * Because the Kernel may not import a business module, and Notifications is
 * one. `Kernel\Usage` states the FACT; the Notifications module listens and
 * decides who is told and how — the same direction every other module uses, and
 * the reason nothing anywhere imports `Modules\Notifications` (ADR-067).
 *
 * It carries identifiers and numbers, never models, and it is past tense: the
 * threshold has been crossed and the row recording that is already committed.
 * A listener that fails loses a notification, never the usage.
 */
final readonly class UsageThresholdReached
{
    public function __construct(
        public string $resource,
        /** `ai` or `whatsapp` — so a listener can phrase one message per group. */
        public string $group,
        public int $threshold,
        public UsageStatus $status,
        public int $used,
        /** Null is unreachable here: an unlimited resource crosses nothing. */
        public ?int $allowance,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
    ) {}
}
