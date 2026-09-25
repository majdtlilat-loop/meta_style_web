<?php

declare(strict_types=1);

namespace App\Modules\Packages\Contracts;

/**
 * Service packages as a center's public site may list them: non-archived
 * definitions only, name, price, validity and session count.
 */
interface SitePackageReader
{
    /**
     * Every package uuid of this center, and whether it is still offered.
     *
     * @return array<string, bool>
     */
    public function references(): array;

    /** Whether the center may sell packages at all right now. */
    public function offered(): bool;

    /**
     * @return list<array{uuid: string, name: string}>
     */
    public function options(string $locale): array;

    /**
     * @param  list<string>|null  $uuids
     * @return list<array{uuid: string, name: string, price: string, validity_days: int, sessions: int}>
     */
    public function packages(?array $uuids, string $locale): array;
}
