<?php

declare(strict_types=1);

namespace App\View\Manager;

/**
 * The one or two letters an avatar shows for a person's name ("Sara Karim" →
 * "SK"), script-agnostic (multibyte-safe, so Arabic and Kurdish names work).
 * A middle dot when there is no name at all, never an empty circle.
 */
final class PersonInitials
{
    public static function of(?string $name): string
    {
        $parts = preg_split('/\s+/u', trim((string) $name)) ?: [];
        $letters = '';
        foreach (array_slice(array_values(array_filter($parts, static fn (string $part): bool => $part !== '')), 0, 2) as $part) {
            $letters .= mb_substr($part, 0, 1);
        }

        return $letters !== '' ? $letters : '·';
    }
}
