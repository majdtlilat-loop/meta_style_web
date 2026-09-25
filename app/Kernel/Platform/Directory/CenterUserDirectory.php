<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Directory;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Tenancy\Infrastructure\StanclTenantContext;
use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects every center's user accounts into the control plane, so the Super
 * Admin's Center Users directory can list and search across centers without
 * opening a tenant database per page view (the UsageProjector pattern).
 *
 * The tenant's `users` table stays the only truth: nothing reads this copy to
 * authorize, and every change made from the platform goes to the tenant first
 * and is then re-projected. Each center is projected on its own, inside its
 * own context, so one unreachable database never stops the others.
 *
 * Only staff accounts are read — never a customer, a credential, a token or a
 * note. A user removed from the center disappears from the copy.
 */
final class CenterUserDirectory
{
    /** Role keys in the order that decides a user's primary role. */
    private const ROLE_ORDER = ['owner', 'manager', 'host', 'cashier', 'employee'];

    public function __construct(private readonly StanclTenantContext $context) {}

    /**
     * @return int the number of accounts projected, or -1 when the center could not be read
     */
    public function refreshTenant(TenantModel $tenant): int
    {
        if (! $tenant->toValueObject()->isProvisioned()) {
            $this->forgetTenant((string) $tenant->getTenantKey());

            return 0;
        }

        try {
            $accounts = $this->context->runForModel($tenant, fn (): array => $this->read());
        } catch (Throwable $exception) {
            Log::warning('center user directory: tenant not projected', ['tenant' => $tenant->getTenantKey(), 'error' => $exception->getMessage()]);

            return -1;
        }

        $tenantId = (string) $tenant->getTenantKey();
        $now = now();
        DB::connection('control')->transaction(function () use ($accounts, $tenantId, $now): void {
            $seen = [];
            foreach ($accounts as $account) {
                $seen[] = $account['uuid'];
                /** @var CenterUserEntry $entry */
                $entry = CenterUserEntry::query()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'user_uuid' => $account['uuid']],
                    [
                        'name' => mb_substr($account['name'], 0, 190),
                        'email' => $account['email'],
                        'phone_e164' => $account['phone']?->e164,
                        'phone_country' => $account['phone']?->country(),
                        'phone_national' => $account['phone']?->national(),
                        'kind' => $account['kind'],
                        'role_key' => $account['role_key'],
                        'roles' => $account['roles'],
                        'is_owner' => $account['owner'],
                        'is_active' => $account['active'],
                        'all_branches' => $account['all_branches'],
                        'account_created_at' => $account['created_at'],
                        'last_login_at' => $account['last_login_at'],
                        'projected_at' => $now,
                    ],
                );
                CenterUserBranch::query()->where('entry_id', $entry->id)->delete();
                foreach ($account['branches'] as $branch) {
                    CenterUserBranch::query()->create(['entry_id' => $entry->id, 'tenant_id' => $tenantId, 'branch_id' => $branch['id'], 'branch_name' => $branch['name']]);
                }
            }
            // Accounts that no longer exist in the center leave the copy.
            CenterUserEntry::query()->where('tenant_id', $tenantId)->whereNotIn('user_uuid', $seen === [] ? [''] : $seen)->delete();
        });

        return count($accounts);
    }

    /**
     * @return array{centers: int, accounts: int, failed: int}
     */
    public function refreshAll(): array
    {
        $result = ['centers' => 0, 'accounts' => 0, 'failed' => 0];
        TenantModel::query()->eachById(function (TenantModel $tenant) use (&$result): void {
            $count = $this->refreshTenant($tenant);
            $result['centers']++;
            $count < 0 ? $result['failed']++ : $result['accounts'] += $count;
        }, 100);

        return $result;
    }

    public function forgetTenant(string $tenantId): void
    {
        CenterUserEntry::query()->where('tenant_id', $tenantId)->delete();
    }

    /**
     * Runs inside the tenant's context.
     *
     * @return list<array{uuid: string, name: string, email: string|null, phone: PhoneNumber|null, kind: string, role_key: string|null, roles: list<array{key: string, name: array<string, string>}>, owner: bool, active: bool, all_branches: bool, created_at: Carbon|null, last_login_at: Carbon|null, branches: list<array{id: int, name: array<string, string>|null}>}>
     */
    private function read(): array
    {
        /** @var list<User> $users */
        $users = User::query()->with('roles')->orderBy('id')->get()->all();
        $branches = [];
        foreach (DB::connection('tenant')->table('user_branches')->join('branches', 'branches.id', '=', 'user_branches.branch_id')
            ->get(['user_branches.user_id', 'branches.id as branch_id', 'branches.name']) as $row) {
            $name = json_decode((string) $row->name, true);
            $branches[(int) $row->user_id][] = ['id' => (int) $row->branch_id, 'name' => is_array($name) ? array_filter($name, 'is_string') : null];
        }

        return array_map(function (User $user) use ($branches): array {
            $roles = $user->roles->map(static fn (Role $role): array => ['key' => $role->key, 'name' => $role->name->all()])->values()->all();
            $keys = array_column($roles, 'key');
            $primary = $user->is_owner ? 'owner' : (collect(self::ROLE_ORDER)->first(fn (string $key): bool => in_array($key, $keys, true)) ?? ($keys[0] ?? null));

            return [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => PhoneNumber::parse($user->phone),
                'kind' => $user->is_owner ? 'owner' : (in_array('manager', $keys, true) ? 'manager' : 'employee'),
                'role_key' => $primary,
                'roles' => $roles,
                'owner' => (bool) $user->is_owner,
                'active' => (bool) $user->is_active,
                'all_branches' => (bool) $user->all_branches,
                'created_at' => $user->created_at instanceof Carbon ? $user->created_at : null,
                'last_login_at' => $user->last_login_at instanceof Carbon ? $user->last_login_at : null,
                'branches' => $user->all_branches ? [] : ($branches[(int) $user->getKey()] ?? []),
            ];
        }, $users);
    }
}
