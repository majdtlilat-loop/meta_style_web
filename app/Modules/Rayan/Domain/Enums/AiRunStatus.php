<?php

declare(strict_types=1);

namespace App\Modules\Rayan\Domain\Enums;

/**
 * How one attempt to produce an answer ended.
 *
 * FOUR outcomes, and they are kept apart because they mean different things
 * commercially and operationally (docs/27-RAYAN.md §7):
 *
 *   completed  the model answered. Counted, billed, ordinary.
 *   refused    the model declined, or every tool it tried was refused. The run
 *              HAPPENED and cost money, so it is counted — it simply produced
 *              no answer for the customer.
 *   failed     the provider or the loop broke. Also counted, and separately
 *              metered as `ai_failed_runs`, because a center whose failure rate
 *              is climbing needs to see that before their customers do.
 *   exhausted  the commercial allowance was spent. The one status that means
 *              NOTHING was sent to the provider, and therefore the one that is
 *              not a provider cost.
 */
enum AiRunStatus: string
{
    case Completed = 'completed';
    case Refused = 'refused';
    case Failed = 'failed';
    case Exhausted = 'exhausted';

    /**
     * Did this run actually reach the provider and cost something?
     *
     * `exhausted` did not: the quota check stopped it before the request was
     * ever assembled, so metering it as a provider cost would overstate what a
     * center used at the exact moment they had used too much.
     */
    public function reachedProvider(): bool
    {
        return $this !== self::Exhausted;
    }

    public function isFailure(): bool
    {
        return $this === self::Failed;
    }
}
