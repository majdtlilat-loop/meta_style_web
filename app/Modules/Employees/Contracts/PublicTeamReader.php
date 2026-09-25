<?php

declare(strict_types=1);

namespace App\Modules\Employees\Contracts;

/**
 * Employees as a center's public site may name them.
 *
 * Only employees the owner SELECTED are ever read for the public page — the
 * whole team is never exposed automatically — and only their display name
 * leaves this module. No login, email, phone, branch, role or status: a job
 * title and photo, when shown, are the site's own content.
 */
interface PublicTeamReader
{
    /**
     * Every employee uuid of this center, and whether the employee is active.
     *
     * @return array<string, bool>
     */
    public function references(): array;

    /**
     * Active employees, for the site editor's picker.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function options(string $locale): array;

    /**
     * The ACTIVE employees among `$uuids`, keyed by uuid.
     *
     * @param  list<string>  $uuids
     * @return array<string, array{uuid: string, name: string}>
     */
    public function members(array $uuids, string $locale): array;
}
