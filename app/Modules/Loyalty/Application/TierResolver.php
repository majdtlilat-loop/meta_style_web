<?php

declare(strict_types=1);

namespace App\Modules\Loyalty\Application;

use App\Modules\Loyalty\Domain\Models\LoyaltyTier;

/**
 * A customer's tier, DERIVED: the highest active tier whose threshold their
 * lifetime points meet. Nothing stores it, so nothing can drift from it, and
 * nobody sets it by hand (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §9).
 */
final class TierResolver
{
    /** @var list<LoyaltyTier>|null */
    private ?array $tiers = null;

    public function tierFor(int $lifetimePoints): ?LoyaltyTier
    {
        $reached = null;

        foreach ($this->tiers() as $tier) {
            if ($tier->threshold_points <= $lifetimePoints) {
                $reached = $tier;
            }
        }

        return $reached;
    }

    /**
     * Active tiers, lowest threshold first.
     *
     * @return list<LoyaltyTier>
     */
    public function tiers(): array
    {
        if ($this->tiers === null) {
            /** @var list<LoyaltyTier> $tiers */
            $tiers = LoyaltyTier::query()->active()->orderBy('threshold_points')->orderBy('id')->limit(50)->get()->all();
            $this->tiers = $tiers;
        }

        return $this->tiers;
    }
}
