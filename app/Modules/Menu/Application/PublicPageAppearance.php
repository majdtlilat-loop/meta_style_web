<?php

declare(strict_types=1);

namespace App\Modules\Menu\Application;

use App\Kernel\Appearance\Appearance;
use App\Kernel\Appearance\AppearanceSchema;
use App\Kernel\Appearance\AppearanceStore;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Platform\Branding\Color;
use InvalidArgumentException;

/**
 * The appearance of the guest pages around the menu — booking and cart — and
 * the policy texts the booking page shows.
 *
 * Each is one document in the tenant `settings` table ({@see AppearanceStore}),
 * described by `config/menu.php` `pages.*` and validated by {@see Appearance}.
 * Writing goes through the Actions (permission, entitlement, audit); this
 * class reads, and turns a document into what a template prints: CSS custom
 * properties built only from validated colours, state classes from enum
 * values, and every text already resolved to the guest's language — the
 * center's own copy, else its primary language's, else the built-in default.
 */
final class PublicPageAppearance
{
    public const PAGES = ['booking', 'cart', 'policies'];

    public function __construct(
        private readonly AppearanceStore $store,
        private readonly LanguageRegistry $languages,
        private readonly TenantLocales $locales,
        private readonly PublicBrand $brand,
    ) {}

    public function schema(string $page): AppearanceSchema
    {
        if (! in_array($page, self::PAGES, true)) {
            throw new InvalidArgumentException("Unknown public page [{$page}].");
        }

        /** @var array<string, mixed> $config */
        $config = config("menu.pages.{$page}", []);

        return AppearanceSchema::fromConfig($page, $config);
    }

    public function get(string $page): Appearance
    {
        return Appearance::fromStored($this->schema($page), $this->store->read($this->key($page)), $this->languages->supported());
    }

    /**
     * Stores a document that has already been validated. Only the Actions call
     * this — they own the permission check and the audit entry.
     */
    public function put(string $page, Appearance $appearance): void
    {
        $this->store->write($this->key($page), $appearance->toArray());
    }

    /**
     * What the booking page prints.
     *
     * @return array{vars: array<string, string>, classes: list<string>, logo: string|null, show_prices: bool, show_duration: bool, numbered: bool, title: string, intro: string|null, cta_label: string, confirmation_message: string|null, policies: array{cancellation: string|null, terms: string|null}}
     */
    public function booking(string $locale, ?Appearance $override = null): array
    {
        $appearance = $override ?? $this->get('booking');
        $policies = $this->get('policies');
        [$vars, $logo] = $this->paint($appearance, $appearance->choice('header_style') === 'gradient');
        $primary = $this->locales->default();
        $showPolicies = $appearance->flag('show_policies');

        return [
            'vars' => $vars,
            'classes' => [
                'header-'.$appearance->choice('header_style'),
                'bg-'.$appearance->choice('background'),
                'steps-'.$appearance->choice('steps_style'),
                'cta-'.$appearance->choice('cta_style'),
            ],
            'logo' => $appearance->flag('show_logo') ? $logo : null,
            'show_prices' => $appearance->flag('show_prices'),
            'show_duration' => $appearance->flag('show_duration'),
            'numbered' => $appearance->choice('steps_style') === 'numbered',
            'title' => $appearance->text('title', $locale, $primary) ?? __('menu_public.booking.title'),
            'intro' => $appearance->text('intro', $locale, $primary),
            'cta_label' => $appearance->text('cta_label', $locale, $primary) ?? __('menu_public.booking.confirm'),
            'confirmation_message' => $appearance->text('confirmation_message', $locale, $primary),
            'policies' => [
                'cancellation' => $showPolicies ? $policies->text('cancellation', $locale, $primary) : null,
                'terms' => $showPolicies ? $policies->text('terms', $locale, $primary) : null,
            ],
        ];
    }

    /**
     * What the cart and checkout pages print.
     *
     * @return array{vars: array<string, string>, classes: list<string>, logo: string|null, summary: string, heading: string, intro: string|null, empty_title: string, empty_body: string, cta_label: string, checkout_heading: string, checkout_body: string}
     */
    public function cart(string $locale, ?Appearance $override = null): array
    {
        $appearance = $override ?? $this->get('cart');
        [$vars, $logo] = $this->paint($appearance, false);
        $primary = $this->locales->default();
        $text = static fn (string $key): ?string => $appearance->text($key, $locale, $primary);

        if ($appearance->choice('background') === 'tint') {
            $vars['--page-bg'] = Color::mix('#ffffff', $vars['--accent'], 0.07);
        }

        return [
            'vars' => $vars,
            'classes' => [
                'cart-layout-'.$appearance->choice('layout'),
                'cart-summary-'.$appearance->choice('summary'),
                'cart-bg-'.$appearance->choice('background'),
                'cta-'.$appearance->choice('cta_style'),
            ],
            'logo' => $appearance->flag('show_logo') ? $logo : null,
            'summary' => $appearance->choice('summary'),
            'heading' => $text('heading') ?? __('menu_public.cart.heading'),
            'intro' => $text('intro'),
            'empty_title' => $text('empty_title') ?? __('menu_public.cart.empty_title'),
            'empty_body' => $text('empty_body') ?? __('menu_public.cart.empty_body'),
            'cta_label' => $text('cta_label') ?? __('menu_public.cart.browse'),
            'checkout_heading' => $text('checkout_heading') ?? __('menu_public.cart.checkout_heading'),
            'checkout_body' => $text('checkout_body') ?? __('menu_public.cart.checkout_body'),
        ];
    }

    /**
     * The two colours actually used, and the logo: the brand's when the page
     * follows the brand and the brand has them, the page's own otherwise.
     *
     * @return array{0: array<string, string>, 1: string|null}
     */
    private function paint(Appearance $appearance, bool $gradient): array
    {
        $primary = $appearance->colour('primary');
        $accent = $appearance->colour('accent');
        $brand = $this->brand->resolve();

        if ($appearance->flag('inherit_brand') && is_array($brand)) {
            $primary = Color::normalize($brand['primary']) ?? $primary;
            $accent = Color::normalize($brand['accent']) ?? $accent;
        }

        $header = $gradient ? Color::mix($primary, $accent, 0.5) : $primary;

        return [[
            '--primary' => $primary,
            '--accent' => $accent,
            '--on-accent' => Color::readableOn($accent, '#ffffff', '#10131a'),
            '--on-primary' => Color::readableOn($header, '#ffffff', '#10131a'),
            '--header-bg' => $gradient ? sprintf('linear-gradient(135deg, %s, %s)', $primary, $accent) : $primary,
        ], $brand['logo_url'] ?? null];
    }

    private function key(string $page): string
    {
        return $page === 'policies' ? 'public_policies' : 'appearance_'.$page;
    }
}
