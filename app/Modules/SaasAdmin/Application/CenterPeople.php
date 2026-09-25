<?php

declare(strict_types=1);

namespace App\Modules\SaasAdmin\Application;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The people who can sign in to one center, as the platform may see them.
 *
 * A control-plane view for support and account lifecycle: who they are, what
 * role they hold, whether their account works, which branches they may use.
 * It reads the tenant's `users` table inside that tenant's own context and
 * returns plain values — never a credential, a token or anything from the
 * center's customers.
 */
final class CenterPeople
{
    public function __construct(private readonly StanclTenantContext $context) {}

    /**
     * @return list<array{uuid: string, name: string, email: string|null, phone: string|null, active: bool, owner: bool, roles: list<string>, branches: list<string>, all_branches: bool, created_at: Carbon|null, last_login_at: Carbon|null}>
     */
    public function list(TenantModel $tenant): array
    {
        if (! $tenant->toValueObject()->isProvisioned()) {
            return [];
        }

        return $this->context->runForModel($tenant, static function (): array {
            /** @var list<User> $users */
            $users = User::query()->with('roles')->orderByDesc('is_owner')->orderBy('name')->limit(500)->get()->all();

            $branches = DB::connection('tenant')->table('user_branches')
                ->join('branches', 'branches.id', '=', 'user_branches.branch_id')
                ->whereIn('user_branches.user_id', array_map(static fn (User $user): int => (int) $user->getKey(), $users))
                ->get(['user_branches.user_id', 'branches.name']);

            $branchNames = [];
            foreach ($branches as $row) {
                $name = json_decode((string) $row->name, true);
                $label = is_array($name) ? (string) ($name[app()->getLocale()] ?? $name['en'] ?? reset($name) ?: '') : (string) $row->name;
                $branchNames[(int) $row->user_id][] = $label;
            }

            return array_map(static fn (User $user): array => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone !== null ? (PhoneNumber::parse($user->phone)?->international() ?? $user->phone) : null,
                'active' => $user->is_active,
                'owner' => $user->is_owner,
                'roles' => $user->roles->map(static fn (Role $role): string => $role->name->get())->values()->all(),
                'branches' => $branchNames[(int) $user->getKey()] ?? [],
                'all_branches' => $user->all_branches,
                'created_at' => $user->created_at instanceof Carbon ? $user->created_at : null,
                'last_login_at' => $user->last_login_at instanceof Carbon ? $user->last_login_at : null,
            ], $users);
        });
    }

    /** @return array{total: int, active: int, owners: int} */
    public function summary(TenantModel $tenant): array
    {
        $people = $this->list($tenant);

        return [
            'total' => count($people),
            'active' => count(array_filter($people, static fn (array $person): bool => $person['active'])),
            'owners' => count(array_filter($people, static fn (array $person): bool => $person['owner'])),
        ];
    }
}
