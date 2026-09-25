<?php

declare(strict_types=1);

namespace App\View;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Turns a stored state (`past_due`, `waiting_center`) into the viewer's
 * language. A state that has no translation yet degrades to a readable
 * heading rather than leaking the raw key onto the page.
 */
final class Label
{
    public static function for(string $group, ?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        // Center-domain states first (labels.php), then platform states.
        foreach (['labels.', 'platform_labels.'] as $file) {
            $key = $file.$group.'.'.$value;

            if (Lang::has($key)) {
                return (string) __($key);
            }
        }

        return Str::headline($value);
    }
}
