<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Data\EarningMoment;
use App\Modules\Loyalty\Domain\Data\EarningState;
use App\Modules\Loyalty\Domain\Enums\PointsSource;
use App\Modules\Loyalty\Domain\Models\LoyaltyEarningObservation;
use App\Modules\Loyalty\Domain\Models\LoyaltyRuleVersion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * "What was true for earning when this happened?"
 *
 * Reconciliation repairs a missing earning LONG after the event, by which time
 * the rules may have changed and the center may have gained or lost `loyalty`.
 * The repair must still produce the result the customer should have had, so
 * eligibility and the rule come from the event's own moment — never from now
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 *
 * Three steps:
 *
 *   capture()   inside the event's own transaction, READ-ONLY: the entitlement
 *               and the effective rule version at that instant. Never throws —
 *               a loyalty read must not be able to fail a payment.
 *   record()    after that transaction commits: one observation row for that
 *               event, which survives a failed earning and answers for it
 *               whenever it is finally repaired.
 *   at()        what was true when the event happened.
 *
 * ## The rule is never evidence
 *
 * `at()` takes the RULE and its expiry from `loyalty_rule_versions` — the
 * append-only history of the program itself — keyed by the event's own instant.
 * Observations are evidence for the one thing the versions cannot answer:
 * whether the center owned `loyalty` then, which lives in the control plane and
 * keeps no history there. So a repair still reproduces the event-time result
 * when an observation was never written, or when every one of them is gone: a
 * rule version in force at that instant is the durable record that earning was
 * running, and losing the evidence must not take a customer's points away.
 */
final class EarningRules
{
    public function __construct(private readonly LoyaltyAccess $access) {}

    /**
     * Read what is true now, from inside the caller's transaction. Never throws.
     */
    public function capture(?CarbonImmutable $now = null): EarningMoment
    {
        $at = ($now ?? CarbonImmutable::now())->utc();

        try {
            $owns = $this->access->enabled();
        } catch (Throwable) {
            $owns = null;
        }

        try {
            $version = LoyaltyRuleVersion::effectiveAt($at);
        } catch (Throwable) {
            $version = null;
        }

        return new EarningMoment($at, $owns, $version === null ? null : (int) $version->getKey());
    }

    /**
     * Records what was captured, for one event or as a free-standing point on
     * the timeline. One row per event: a duplicate event keeps the first.
     */
    public function record(EarningMoment $moment, ?PointsSource $source = null, ?string $sourceUuid = null): void
    {
        $owns = $moment->ownsLoyalty ?? $this->access->enabled();
        $versionId = $moment->versionId ?? LoyaltyRuleVersion::effectiveAt($moment->at)?->getKey();

        LoyaltyEarningObservation::query()->insertOrIgnore([[
            'observed_at' => $moment->at,
            'owns_loyalty' => $owns,
            'loyalty_rule_version_id' => $versionId,
            'source_type' => $source?->value,
            'source_uuid' => $sourceUuid,
            'created_at' => CarbonImmutable::now()->utc(),
        ]]);
    }

    /**
     * What was true when `$when` happened.
     *
     * The rule comes from the versions, always. The entitlement comes from the
     * event's own observation when it has one, otherwise from the nearest
     * earlier point on the timeline — and, when no observation survives at all,
     * from the fact that the center had a rule in force then.
     */
    public function at(CarbonInterface $when, ?PointsSource $source = null, ?string $sourceUuid = null): EarningState
    {
        $version = LoyaltyRuleVersion::effectiveAt($when);

        if (! $version instanceof LoyaltyRuleVersion) {
            // The center had not set its rules by then: nothing to earn under.
            return new EarningState(false, null);
        }

        if ($source !== null && $sourceUuid !== null) {
            /** @var LoyaltyEarningObservation|null $observed */
            $observed = LoyaltyEarningObservation::query()
                ->where('source_type', $source->value)
                ->where('source_uuid', $sourceUuid)
                ->first();

            if ($observed instanceof LoyaltyEarningObservation) {
                // Its own observation: what Loyalty actually saw at that
                // instant, including the version it read there.
                return new EarningState($observed->owns_loyalty, $observed->version ?? $version);
            }
        }

        // No observation of its own — a process that died between the commit
        // and the callback. The timeline answers for the entitlement instead,
        // and the rule still comes from the event's own moment.
        /** @var LoyaltyEarningObservation|null $timeline */
        $timeline = LoyaltyEarningObservation::query()
            ->where('observed_at', '<=', $when)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();

        if ($timeline instanceof LoyaltyEarningObservation) {
            return new EarningState($timeline->owns_loyalty, $version);
        }

        // Nothing observed at or before it either: the durable record is the
        // rule version that was in force, and it says earning was running.
        return new EarningState(true, $version);
    }
}
