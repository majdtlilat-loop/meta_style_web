<?php

declare(strict_types=1);

namespace App\View\Composers;

use App\Kernel\Localization\LanguageRegistry;
use Illuminate\View\View;

/**
 * Hands the shared interface-language menu (`x-navigation.language-switcher`,
 * used by the Manager, Super Admin and the public platform pages) everything
 * it shows, so the template holds no PHP.
 *
 * The APPLICATION language (en / ar / ckb) — never a center's content
 * languages. Codes appear as EN / AR / KU (`ckb` stays internal), each
 * language in its own script and direction, and each link keeps the current
 * page and query. The component stays anonymous on purpose: turning it into a
 * class would leave every already-compiled layout pointing at the old one.
 */
final class LanguageSwitcherComposer
{
    public function __construct(private readonly LanguageRegistry $registry) {}

    public function compose(View $view): void
    {
        $locales = $view->getData()['locales'] ?? null;
        $active = app()->getLocale();
        $codes = is_array($locales) && $locales !== []
            ? array_values(array_filter($locales, 'is_string'))
            : $this->registry->supported();

        $view->with('current', [
            'code' => $active,
            'short' => $this->registry->shortLabel($active),
            'flag' => asset('icons/languages/'.$this->registry->icon($active).'.svg'),
        ]);

        $view->with('choices', array_map(fn (string $code): array => [
            'code' => $code,
            'short' => $this->registry->shortLabel($code),
            'native' => $this->registry->nativeName($code),
            'dir' => $this->registry->direction($code),
            'flag' => asset('icons/languages/'.$this->registry->icon($code).'.svg'),
            'href' => request()->fullUrlWithQuery(['locale' => $code]),
            'active' => $code === $active,
        ], $codes));
    }
}
