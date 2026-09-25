<?php

declare(strict_types=1);

namespace App\Modules\Employees\Application;

use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The staff list, as the viewer is allowed to see it.
 *
 * BRANCH SCOPE IN THE QUERY. A manager scoped to one branch receives only the
 * people who work at one of their branches — never the whole team to be hidden
 * client-side (docs/06 §5). Somebody assigned to no branch at all is visible
 * only to an unrestricted viewer.
 *
 * Search matches the name in any language (the JSON column is stored
 * unescaped, so Arabic and Kurdish match), an email, or — when the text reads
 * as a phone — the E.164 number exactly. Staff contact details are not masked:
 * whoever may see the staff list manages these people.
 */
final class EmployeeQuery
{
    public const LOGIN_STATES = ['none', 'pending', 'active', 'disabled'];

    /**
     * @param  array{search?: string|null, status?: string|null, branch?: string|null, role?: string|null, login?: string|null}  $filters
     * @return LengthAwarePaginator<int, Employee>
     */
    public function paginate(array $filters, User $viewer, int $perPage = 25): LengthAwarePaginator
    {
        $query = $this->visibleTo($viewer)
            ->with(['branches', 'user.roles'])
            // Active people first, then the newest.
            ->orderBy('status')
            ->orderByDesc('id');

        $this->applySearch($query, $filters['search'] ?? null);

        $status = EmployeeStatus::tryFrom((string) ($filters['status'] ?? ''));

        if ($status !== null) {
            $query->where('status', $status->value);
        }

        $branch = (string) ($filters['branch'] ?? '');

        if ($branch !== '') {
            $query->whereHas('branches', fn (Builder $q) => $q->where('branches.uuid', $branch));
        }

        $role = (string) ($filters['role'] ?? '');

        if ($role !== '') {
            $query->whereHas('user.roles', fn (Builder $q) => $q->where('roles.uuid', $role));
        }

        $this->applyLogin($query, (string) ($filters['login'] ?? ''));

        return $query->paginate(min($perPage, 100));
    }

    /**
     * One person by uuid — null when they do not exist OR are outside the
     * viewer's branches. The two answers are deliberately the same.
     */
    public function find(string $uuid, User $viewer): ?Employee
    {
        if ($uuid === '') {
            return null;
        }

        $employee = $this->visibleTo($viewer)
            ->with(['branches', 'user.roles'])
            ->where('uuid', $uuid)
            ->first();

        return $employee instanceof Employee ? $employee : null;
    }

    /**
     * @return Builder<Employee>
     */
    public function visibleTo(User $viewer): Builder
    {
        $query = Employee::query();
        $scope = $viewer->branchScope();

        if (! $scope->isUnrestricted()) {
            $ids = $scope->branchIds ?? [];

            $query->whereHas('branches', fn (Builder $q) => $q->whereIn('branches.id', $ids === [] ? [0] : $ids));
        }

        return $query;
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function applySearch(Builder $query, ?string $search): void
    {
        $search = trim((string) $search);

        if (mb_strlen($search) < 2) {
            return;
        }

        $like = '%'.addcslashes($search, '%_\\').'%';
        $digits = preg_replace('/\D+/', '', $search) ?? '';
        $phone = mb_strlen($digits) >= 7 ? PhoneNumber::parse($search) : null;

        $query->where(function (Builder $q) use ($like, $search, $phone): void {
            $q->where('name', 'like', $like);

            if (str_contains($search, '@')) {
                $q->orWhereHas('user', fn (Builder $u) => $u->where('email', 'like', $like));
            }

            if ($phone instanceof PhoneNumber) {
                $q->orWhereHas('user', fn (Builder $u) => $u->where('phone', $phone->e164));
            }
        });
    }

    /**
     * @param  Builder<Employee>  $query
     */
    private function applyLogin(Builder $query, string $login): void
    {
        match ($login) {
            'none' => $query->whereNull('user_id'),
            'pending' => $query->whereHas('user', fn (Builder $u) => $u->whereNull('password')->where('is_active', true)),
            'active' => $query->whereHas('user', fn (Builder $u) => $u->whereNotNull('password')->where('is_active', true)),
            'disabled' => $query->whereHas('user', fn (Builder $u) => $u->where('is_active', false)),
            default => null,
        };
    }
}
