<?php

declare(strict_types=1);

namespace App\Modules\Branches\Contracts;

/**
 * Branches as a center's public site shows them: publicly visible ones only,
 * as allow-listed arrays. A branch's phone, WhatsApp and email are business
 * contact details the center publishes — they leave here under `contact_*`
 * keys so no template ever reaches for a model's contact column.
 */
interface SiteBranchReader
{
    /**
     * Every branch uuid of this center, and whether it is publicly visible.
     *
     * @return array<string, bool>
     */
    public function references(): array;

    /**
     * Publicly visible branches for the site editor's pickers.
     *
     * @return list<array{uuid: string, name: string, is_main: bool}>
     */
    public function options(string $locale): array;

    /**
     * Publicly visible branches (all, or those named), main branch first,
     * with their weekly opening intervals. Two queries.
     *
     * @param  list<string>|null  $uuids
     * @return list<array{uuid: string, name: string, address: string|null, is_main: bool, contact_phone: string|null, contact_whatsapp: string|null, contact_email: string|null, map_url: string|null, latitude: float|null, longitude: float|null, hours: array<int, list<array{opens: string, closes: string}>>}>
     */
    public function branches(?array $uuids, string $locale): array;

    /**
     * The main branch's timezone, for showing when the site was saved or
     * published in the center's own local time.
     */
    public function mainTimezone(): string;
}
