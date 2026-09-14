<?php

declare(strict_types=1);

namespace App\Kernel\Entitlements;

/**
 * A tenant's resolved capabilities at a point in time.
 *
 * `owned` survives suspension; `usable` does not. Keeping both means the
 * billing screen can say "your plan includes POS" while the POS itself refuses
 * to open.
 */
final readonly class EffectiveEntitlements
{
    /**
     * @param  list<string>  $owned
     */
    public function __construct(
        public array $owned,
        public TenantAccessLevel $accessLevel,
    ) {}

    public function owns(string $key): bool
    {
        return in_array($key, $this->owned, true);
    }

    public function enabled(string $key): bool
    {
        return $this->accessLevel->allowsUse() && $this->owns($key);
    }

    /**
     * @return list<string>
     */
    public function usable(): array
    {
        return $this->accessLevel->allowsUse() ? $this->owned : [];
    }
}
