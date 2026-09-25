<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Directory;

use App\Kernel\Tenancy\Infrastructure\TenantModel;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filters and sorts the Center Users directory — on the control-plane copy,
 * never by opening tenant databases.
 *
 * Phone search works on the NORMALIZED number, not on how it was typed:
 * `7501234567`, `0750 123 4567`, `+964 750 123 4567` and `9647501234567` all
 * find the same account, through prefix matches on `phone_national` and
 * `phone_e164`.
 */
final class CenterUserSearch
{
    public const SORTS = ['name', 'center', 'created', 'last_login'];

    public const KINDS = ['owner', 'manager', 'employee'];

    /**
     * @param  array{search?: string|null, center?: string|null, kind?: string|null, role?: string|null, branch?: int|null, status?: string|null, phone?: string|null, created?: string|null, sort?: string|null}  $filters
     * @return Builder<CenterUserEntry>
     */
    public function query(array $filters): Builder
    {
        $query = CenterUserEntry::query();

        $term = trim((string) ($filters['search'] ?? ''));
        if ($term !== '') {
            $this->search($query, $term);
        }
        if (($filters['center'] ?? '') !== '' && $filters['center'] !== null) {
            $query->where('tenant_id', $filters['center']);
        }
        if (in_array($filters['kind'] ?? null, self::KINDS, true)) {
            $query->where('kind', $filters['kind']);
        }
        if (is_string($filters['role'] ?? null) && $filters['role'] !== '') {
            $query->where('role_key', $filters['role']);
        }
        if (is_int($filters['branch'] ?? null) && ($filters['center'] ?? '') !== '') {
            $branch = $filters['branch'];
            $query->where(fn (Builder $inner) => $inner->where('all_branches', true)->orWhereHas('branches', fn (Builder $b) => $b->where('branch_id', $branch)));
        }
        match ($filters['status'] ?? null) {
            'active' => $query->where('is_active', true),
            'blocked' => $query->where('is_active', false),
            default => null,
        };
        match ($filters['phone'] ?? null) {
            'missing' => $query->whereNull('phone_e164'),
            'present' => $query->whereNotNull('phone_e164'),
            default => null,
        };
        $days = ['7d' => 7, '30d' => 30, '365d' => 365][$filters['created'] ?? ''] ?? null;
        if ($days !== null) {
            $query->where('account_created_at', '>=', now()->subDays($days));
        }

        return match ($filters['sort'] ?? 'name') {
            'center' => $query->orderBy(TenantModel::query()->select('name')->whereColumn('tenants.id', 'center_user_directory.tenant_id'))->orderBy('name'),
            'created' => $query->orderByDesc('account_created_at')->orderBy('name'),
            'last_login' => $query->orderByRaw('last_login_at is null')->orderByDesc('last_login_at')->orderBy('name'),
            default => $query->orderBy('name')->orderBy('id'),
        };
    }

    /** @param Builder<CenterUserEntry> $query */
    private function search(Builder $query, string $term): void
    {
        $compact = preg_replace('/[\s\-().]+/', '', $term) ?? '';
        $digits = preg_replace('/\D+/', '', $compact) ?? '';
        $looksLikePhone = $digits !== '' && preg_match('/^\+?\d+$/', $compact) === 1 && mb_strlen($digits) >= 4;

        if ($looksLikePhone) {
            $query->where(function (Builder $inner) use ($compact, $digits): void {
                if (str_starts_with($compact, '+') || str_starts_with($digits, '00')) {
                    $international = str_starts_with($digits, '00') ? mb_substr($digits, 2) : $digits;
                    $inner->where('phone_e164', 'like', '+'.$international.'%');

                    return;
                }
                // A national number, with or without its trunk 0, or the full
                // number typed without the plus.
                $inner->where('phone_national', 'like', $digits.'%')
                    ->orWhere('phone_e164', 'like', '+'.$digits.'%');
                if (str_starts_with($digits, '0') && mb_strlen($digits) > 4) {
                    $inner->orWhere('phone_national', 'like', mb_substr($digits, 1).'%');
                }
            });

            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(fn (Builder $inner) => $inner->where('name', 'like', $like)->orWhere('email', 'like', $like));
    }
}
