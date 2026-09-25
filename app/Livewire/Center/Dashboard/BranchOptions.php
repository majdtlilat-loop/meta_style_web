<?php

declare(strict_types=1);

namespace App\Livewire\Center\Dashboard;

use App\Kernel\Identity\Models\User;
use App\Kernel\Reporting\ReadTarget;
use App\Modules\Branches\Contracts\ReportBranchReader;

/**
 * The branches a viewer may pick on the overview and the report pages, and
 * the timezone their date range is expressed in.
 *
 * Read through the Branches reporting contract, so the list is exactly the
 * scope the report request factory will intersect with anyway. Memoised per
 * request: the range, the filter and the view all ask.
 */
final class BranchOptions
{
    /** @var array<int, list<array{id: int, uuid: string, name: string, timezone: string}>> */
    private array $memo = [];

    public function __construct(private readonly ReportBranchReader $branches) {}

    /**
     * @return list<array{id: int, uuid: string, name: string, timezone: string}>
     */
    public function for(User $viewer): array
    {
        $key = (int) $viewer->getKey();

        return $this->memo[$key] ??= $this->branches->accessible($viewer->branchScope(), [], ReadTarget::Primary);
    }

    /**
     * A uuid the viewer may actually choose, or null (all their branches).
     * Anything else — stale, foreign, malformed — falls back to "all",
     * which is still only the viewer's own scope.
     */
    public function valid(User $viewer, ?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        foreach ($this->for($viewer) as $branch) {
            if ($branch['uuid'] === $uuid) {
                return $uuid;
            }
        }

        return null;
    }

    /**
     * The timezone a range is resolved in: the chosen branch's, else the
     * first accessible branch's (the main one first), else the app's.
     */
    public function timezone(User $viewer, ?string $uuid = null): string
    {
        $branches = $this->for($viewer);

        foreach ($branches as $branch) {
            if ($uuid !== null && $branch['uuid'] === $uuid && $branch['timezone'] !== '') {
                return $branch['timezone'];
            }
        }

        $first = $branches[0]['timezone'] ?? '';

        return $first !== '' ? $first : (string) config('app.timezone', 'UTC');
    }
}
