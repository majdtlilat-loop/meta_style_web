<?php

declare(strict_types=1);

namespace App\Modules\Memberships\Contracts;

/**
 * Membership plans as a center's public site may list them: non-archived
 * plans only, name, price and term. Nothing about who holds one.
 */
interface SiteMembershipReader
{
    /**
     * Every plan uuid of this center, and whether it is still offered.
     *
     * @return array<string, bool>
     */
    public function references(): array;

    /**
     * Whether the center may sell memberships at all right now. A plan that
     * cannot be bought is not advertised.
     */
    public function offered(): bool;

    /**
     * @return list<array{uuid: string, name: string}>
     */
    public function options(string $locale): array;

    /**
     * Non-archived plans (all, or those named), in display order.
     *
     * @param  list<string>|null  $uuids
     * @return list<array{uuid: string, name: string, price: string, duration_days: int, benefits: int}>
     */
    public function plans(?array $uuids, string $locale): array;
}
