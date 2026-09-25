<?php

declare(strict_types=1);

namespace App\View;

use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * Audit actions are stable dotted keys (`platform.center.lifecycle.changed`).
 * A screen shows the translated sentence; an action that has none yet reads
 * as its last segments in plain words, never as the raw key.
 */
final class AuditAction
{
    public static function label(?string $action): string
    {
        if ($action === null || $action === '') {
            return '—';
        }

        $key = 'audit_actions.'.str_replace('.', '_', $action);

        if (Lang::has($key)) {
            return (string) __($key);
        }

        $segments = array_slice(explode('.', $action), -2);

        return Str::ucfirst(str_replace('_', ' ', implode(' ', $segments)));
    }

    /**
     * What it was done to. Records the platform itself owns (the landing
     * page) read as their translated name rather than an internal slug.
     */
    public static function target(?string $type, ?string $label): ?string
    {
        if (is_string($type) && str_ends_with($type, '\LandingPage')) {
            return (string) __('sadmin_cms.title');
        }

        return $label === '' ? null : $label;
    }

    /**
     * Who did it. A person's or command's label is shown as written; the
     * platform's own automated actors ("self-registration", "loyalty") read
     * as a translated name rather than an internal identifier.
     */
    public static function actor(?string $label, ?string $type = null): ?string
    {
        if ($label === null || $label === '' || ($type !== null && $type !== 'system')) {
            return $label === '' ? null : $label;
        }

        $key = 'sadmin_audit.system_actors.'.str_replace(['-', '.', ':'], '_', $label);

        return Lang::has($key) ? (string) __($key) : $label;
    }
}
