<?php

declare(strict_types=1);

namespace App\Kernel\Authorization\Actions;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Localization\TranslatedText;
use Illuminate\Validation\ValidationException;

/**
 * The name rules shared by creating and renaming a role.
 *
 * A role's name is the only thing a manager sees when assigning it, so two
 * roles called "Reception" in the same language are two roles nobody can tell
 * apart. Compared per language, trimmed and case-insensitively.
 */
final class RoleNames
{
    private const MAX = 190;

    /**
     * @param  array<string, string|null>  $name
     *
     * @throws ValidationException
     */
    public static function validated(array $name, ?Role $ignore = null): TranslatedText
    {
        $clean = [];

        foreach ($name as $locale => $value) {
            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            if (mb_strlen($value) > self::MAX) {
                throw ValidationException::withMessages(["name.{$locale}" => __('permissions.errors.name_too_long', ['max' => self::MAX])]);
            }

            $clean[(string) $locale] = $value;
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['name' => __('permissions.errors.name_required')]);
        }

        $others = Role::query()
            ->when($ignore instanceof Role, fn ($query) => $query->whereKeyNot($ignore?->getKey()))
            ->get(['id', 'name']);

        foreach ($others as $other) {
            foreach ($clean as $locale => $value) {
                $existing = $other->name->in($locale);

                if ($existing !== null && mb_strtolower(trim($existing)) === mb_strtolower($value)) {
                    throw ValidationException::withMessages(["name.{$locale}" => __('permissions.errors.name_taken')]);
                }
            }
        }

        return TranslatedText::fromArray($clean);
    }
}
