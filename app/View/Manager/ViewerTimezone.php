<?php

declare(strict_types=1);

namespace App\View\Manager;

use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;

/**
 * The timezone the Manager shows a person's own times in.
 *
 * Instants are stored in UTC; a center's day is its BRANCH's day. For things
 * that belong to a person rather than to one branch (their notifications, the
 * center's support tickets, plan dates) the first branch they can reach is
 * the honest choice — the same rule the overview uses for its date range.
 */
final class ViewerTimezone
{
    /** @var array<int, string> */
    private array $memo = [];

    public function for(User $user): string
    {
        $key = (int) $user->getKey();
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $query = Branch::query();
        $user->branchScope()->applyTo($query, 'id');
        $branch = $query->orderBy('sort_order')->orderBy('id')->first();

        return $this->memo[$key] = $branch instanceof Branch && (string) $branch->timezone !== ''
            ? $branch->timezone
            : (string) config('app.timezone', 'UTC');
    }
}
