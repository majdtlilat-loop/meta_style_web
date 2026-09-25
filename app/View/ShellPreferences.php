<?php

declare(strict_types=1);

namespace App\View;

/**
 * The theme and sidebar state a page is rendered with.
 *
 * The browser remembers both (localStorage, mirrored into two plain cookies by
 * resources/js/platform/theme.js). Rendering them on <html> from the cookie
 * means the server's HTML already carries the right theme — so Livewire's
 * navigate, which copies the new page's <html> attributes over the current
 * ones, can never flip a dark page back to light for a frame.
 *
 * Only the two allow-listed values are ever echoed; anything else is ignored.
 */
final class ShellPreferences
{
    public const THEME_COOKIE = 'metastyle-theme';

    public const SIDEBAR_COOKIE = 'metastyle-sidebar';

    public static function theme(): ?string
    {
        $value = request()->cookie(self::THEME_COOKIE);

        return in_array($value, ['light', 'dark'], true) ? $value : null;
    }

    public static function sidebar(): ?string
    {
        $value = request()->cookie(self::SIDEBAR_COOKIE);

        return in_array($value, ['collapsed', 'expanded'], true) ? $value : null;
    }

    /** Attributes for the <html> element, already escaped. */
    public static function htmlAttributes(bool $withSidebar = false): string
    {
        $attributes = '';
        $theme = self::theme();
        if ($theme !== null) {
            $attributes .= ' data-theme="'.$theme.'"';
        }
        $sidebar = $withSidebar ? self::sidebar() : null;
        if ($sidebar !== null) {
            $attributes .= ' data-sidebar="'.$sidebar.'"';
        }

        return $attributes;
    }
}
