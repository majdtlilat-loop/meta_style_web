<?php

declare(strict_types=1);

namespace App\View;

use App\Kernel\Localization\TranslatedText;
use Illuminate\Support\Facades\Storage;

/**
 * Rendering helpers for the corporate landing page.
 *
 * Content reaching these has already passed the CMS schema (plain text, safe
 * links, platform-uploaded media); Blade escapes everything on output.
 */
final class Landing
{
    public static function text(mixed $value, string $fallback = ''): string
    {
        if (! is_array($value)) {
            return $fallback;
        }
        $resolved = TranslatedText::fromArray(array_filter($value, 'is_string'))->get();

        return $resolved !== '' ? $resolved : $fallback;
    }

    /**
     * A CTA or link: a section anchor, a site path, or an http(s) URL.
     *
     * @param  array<string, mixed>  $link
     */
    public static function href(array $link): string
    {
        $target = (string) ($link['target'] ?? $link['url'] ?? '');
        if (($link['link_type'] ?? null) === 'section') {
            return '#'.ltrim($target, '#');
        }
        if (str_starts_with($target, '#')) {
            return $target;
        }
        if (str_starts_with($target, '/')) {
            return url($target);
        }

        return $target;
    }

    public static function media(?string $path): ?string
    {
        return is_string($path) && $path !== '' ? Storage::disk('public')->url($path) : null;
    }

    /** @param array<string, mixed> $cta */
    public static function showsCta(array $cta): bool
    {
        return (bool) ($cta['enabled'] ?? false) && self::text($cta['label'] ?? []) !== '' && (string) ($cta['url'] ?? $cta['target'] ?? '') !== '';
    }

    /** @param array<string, mixed> $cta */
    public static function ctaClass(array $cta): string
    {
        return match ($cta['style'] ?? 'primary') {
            'secondary' => 'button button--secondary',
            'link' => 'text-button',
            default => 'button',
        };
    }
}
