<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Domain\Data;

use App\Modules\Loyalty\Domain\Models\LoyaltyRuleVersion;
use Carbon\CarbonInterface;

/**
 * What was true for earning at one instant: whether the center owned `loyalty`,
 * and the rule version effective then.
 *
 * An event earns only if BOTH were in place when it happened — never because
 * they are in place now (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §6).
 */
final readonly class EarningState
{
    public function __construct(
        public bool $ownsLoyalty,
        public ?LoyaltyRuleVersion $version,
    ) {}

    public function earnsOnSpend(): bool
    {
        return $this->ownsLoyalty && $this->version instanceof LoyaltyRuleVersion && $this->version->earnsOnSpend();
    }

    public function earnsOnVisits(): bool
    {
        return $this->ownsLoyalty && $this->version instanceof LoyaltyRuleVersion && $this->version->earnsOnVisits();
    }

    /**
     * When points earned at `$at` under this state expire, or null.
     */
    public function expiryFor(CarbonInterface $at): ?CarbonInterface
    {
        return $this->version?->expiryFor($at);
    }
}
