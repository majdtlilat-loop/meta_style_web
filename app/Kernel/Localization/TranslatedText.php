<?php

declare(strict_types=1);

namespace App\Kernel\Localization;

use Stringable;

/**
 * A piece of text that exists in several languages.
 *
 * Immutable. Stored as a JSON column, never as `name_ar` / `name_en` / `name_ku`
 * columns — adding Turkish must not be a migration across every tenant database
 * (docs/07-LOCALIZATION.md §2).
 */
final readonly class TranslatedText implements Stringable
{
    /**
     * @param  array<string, string>  $translations
     */
    public function __construct(private array $translations = []) {}

    public static function make(string $locale, string $value): self
    {
        return new self([$locale => $value]);
    }

    /**
     * @param  array<string, string|null>  $translations
     */
    public static function fromArray(array $translations): self
    {
        $clean = [];

        foreach ($translations as $locale => $value) {
            if (is_string($value) && trim($value) !== '') {
                $clean[$locale] = $value;
            }
        }

        return new self($clean);
    }

    /**
     * Resolves the best available string.
     *
     * Falls through requested locale → current application locale → platform
     * fallback → any translation that exists. A translatable field must never
     * render empty when *some* translation exists: a blank service name in a
     * menu is worse than the wrong language (docs/07-LOCALIZATION.md §5.1).
     */
    public function get(?string $locale = null): string
    {
        foreach ($this->candidates($locale) as $candidate) {
            if (isset($this->translations[$candidate])) {
                return $this->translations[$candidate];
            }
        }

        foreach ($this->translations as $value) {
            return $value;
        }

        return '';
    }

    public function in(string $locale): ?string
    {
        return $this->translations[$locale] ?? null;
    }

    public function has(string $locale): bool
    {
        return isset($this->translations[$locale]);
    }

    /**
     * The full map, for admin editing surfaces.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->translations;
    }

    public function with(string $locale, string $value): self
    {
        return new self(array_merge($this->translations, [$locale => $value]));
    }

    public function isEmpty(): bool
    {
        return $this->translations === [];
    }

    public function __toString(): string
    {
        return $this->get();
    }

    /**
     * @return list<string>
     */
    private function candidates(?string $locale): array
    {
        $fallback = config('localization.fallback');

        return array_values(array_unique(array_filter([
            $locale,
            app()->getLocale(),
            is_string($fallback) ? $fallback : 'en',
        ])));
    }
}
