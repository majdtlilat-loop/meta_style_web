<?php

declare(strict_types=1);

namespace App\Kernel\Usage;

/**
 * What the control plane says a center is allowed, and where that came from.
 *
 * `allowance` NULL means UNLIMITED. The `source` is kept because the three
 * origins behave differently when they change: an override is versioned and
 * synchronisable, a plan allowance moves when the center changes plan, and the
 * system default moves only with a deploy (docs/26-USAGE-QUOTAS.md §4).
 */
final readonly class ResolvedAllowance
{
    public function __construct(
        public ?int $allowance,
        /** `override`, `plan` or `default`. */
        public string $source,
        /**
         * The override's version, or null when the allowance did not come from
         * one. A counter records this so reconciliation can recognise a change
         * it has not applied yet (§8).
         */
        public ?int $version = null,
        /**
         * Whether a DECREASE from this source should take effect mid-period.
         *
         * False for everything except an explicitly flagged, audited override.
         * A center must not lose allowance they are part-way through using
         * because of an ordinary pricing change (§6).
         */
        public bool $enforceImmediately = false,
    ) {}

    public function isUnlimited(): bool
    {
        return $this->allowance === null;
    }

    /**
     * Would moving from `$current` to this allowance give the center MORE?
     *
     * Unlimited is more than any finite number; any finite number is less than
     * unlimited. Stated once, here, because every caller that gets this
     * comparison subtly wrong produces the same bug — a center quietly losing
     * allowance they have paid for (§6).
     */
    public function isIncreaseFrom(?int $current): bool
    {
        if ($current === null) {
            // Already unlimited. Nothing is an increase; a finite number is a
            // decrease.
            return false;
        }

        return $this->allowance === null || $this->allowance > $current;
    }
}
