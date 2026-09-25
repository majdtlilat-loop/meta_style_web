<?php

declare(strict_types=1);

namespace App\Livewire\Center\Roles;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Authorization\SystemRole;

/**
 * The permission catalog, shaped for the role screens.
 *
 * Grouping, counting and the "you do not hold this" flag are computed here so
 * the template only loops (no business logic in Blade). The catalog comes from
 * code — {@see Permission} — so the editor can never offer a code the server
 * does not enforce.
 */
final class RoleCatalog
{
    /**
     * @return array<string, list<string>> group => codes, in catalog order
     */
    public static function codesByGroup(): array
    {
        $groups = [];

        foreach (Permission::cases() as $permission) {
            $groups[$permission->group()][] = $permission->value;
        }

        return $groups;
    }

    /**
     * The editor's groups.
     *
     * @param  list<string>  $selected
     * @param  list<string>  $held
     * @return list<array{key: string, label: string, chosen: int, total: int, changeable: int, all_on: bool, codes: list<array{code: string, label: string, checked: bool, held: bool}>}>
     */
    public static function groups(array $selected, array $held): array
    {
        $out = [];

        foreach (self::codesByGroup() as $group => $codes) {
            $rows = [];
            $changeable = 0;
            $changeableOn = 0;

            foreach ($codes as $code) {
                $isHeld = in_array($code, $held, true);
                $checked = in_array($code, $selected, true);
                $rows[] = ['code' => $code, 'label' => self::label($code), 'checked' => $checked, 'held' => $isHeld];

                if ($isHeld) {
                    $changeable++;
                    $changeableOn += $checked ? 1 : 0;
                }
            }

            $out[] = [
                'key' => $group,
                'label' => (string) __('permissions.groups.'.$group),
                'chosen' => count(array_intersect($codes, $selected)),
                'total' => count($codes),
                'changeable' => $changeable,
                'all_on' => $changeable > 0 && $changeableOn === $changeable,
                'codes' => $rows,
            ];
        }

        return $out;
    }

    /**
     * One role card.
     *
     * @return array{uuid: string, name: string, system: bool, owner: bool, members: int, permissions: int, groups: list<array{label: string, count: int, total: int}>}
     */
    public static function card(Role $role, int $members): array
    {
        $codes = $role->permissions->pluck('permission')->map(static fn (mixed $code): string => (string) $code)->all();
        $groups = [];

        foreach (self::codesByGroup() as $group => $groupCodes) {
            $count = count(array_intersect($groupCodes, $codes));

            if ($count > 0) {
                $groups[] = ['label' => (string) __('permissions.groups.'.$group), 'count' => $count, 'total' => count($groupCodes)];
            }
        }

        return [
            'uuid' => $role->uuid,
            'name' => $role->name->get(),
            'system' => $role->is_system,
            'owner' => $role->key === SystemRole::Owner->value,
            'members' => $members,
            'permissions' => count(array_filter($codes, static fn (string $code): bool => Permission::tryFromCode($code) !== null)),
            'groups' => $groups,
        ];
    }

    /**
     * Only the codes in `$group` the viewer holds — a group toggle never
     * reaches past what the viewer may change.
     *
     * @param  list<string>  $held
     * @return list<string>
     */
    public static function changeableIn(string $group, array $held): array
    {
        return array_values(array_intersect(self::codesByGroup()[$group] ?? [], $held));
    }

    public static function label(string $code): string
    {
        return (string) __('permissions.codes.'.str_replace('.', '_', $code));
    }
}
