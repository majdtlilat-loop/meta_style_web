<?php

declare(strict_types=1);

namespace App\Kernel\Localization;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Answers "what is this language" for the application.
 *
 * Phase 1 reads config/localization.php. Phase 2 moves the source of truth to
 * the control-plane `languages` table; this API is what the rest of the
 * application depends on, so that move is not a breaking change.
 *
 * See docs/07-LOCALIZATION.md §6.
 */
final class LanguageRegistry
{
    public function __construct(private readonly Config $config) {}

    public function supports(string $locale): bool
    {
        return is_array($this->config->get("localization.languages.{$locale}"));
    }

    /**
     * @return 'ltr'|'rtl'
     */
    public function direction(string $locale): string
    {
        $direction = $this->config->get("localization.languages.{$locale}.direction");

        return $direction === 'rtl' ? 'rtl' : 'ltr';
    }

    public function isRtl(string $locale): bool
    {
        return $this->direction($locale) === 'rtl';
    }

    public function nativeName(string $locale): string
    {
        $name = $this->config->get("localization.languages.{$locale}.name_native");

        return is_string($name) ? $name : $locale;
    }

    /**
     * @return list<string>
     */
    public function supported(): array
    {
        /** @var array<string, mixed> $languages */
        $languages = $this->config->get('localization.languages', []);

        return array_keys($languages);
    }
}
