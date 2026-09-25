<?php

declare(strict_types=1);

namespace App\Modules\SaasBilling\Domain;

use DomainException;

/**
 * The allow-listed shape of the SaaS billing document template.
 *
 * Structured settings only: plain text in the three platform languages,
 * validated contact fields, and presentation choices from fixed lists. No
 * HTML, CSS or script can reach an invoice or a statement through it, and a
 * colour is a named swatch, never a free value.
 *
 * Historical safety: the ISSUER block (who is billing — name, address, tax
 * number, contacts) is snapshotted onto each invoice when it is issued. Layout,
 * colours and the header/footer texts are presentation and follow the current
 * template when a document is rendered again. Financial values always come
 * from the invoice row and never from this template.
 *
 * The logo is NOT part of the template: documents use the one platform logo
 * from Settings → Branding (PlatformBranding), so there is a single place to
 * change it. The template only decides its size, alignment and visibility.
 */
final class InvoiceTemplate
{
    public const LOCALES = ['en', 'ar', 'ckb'];

    public const LAYOUTS = ['classic', 'modern', 'compact'];

    /** Named accents only; the renderer maps them to colours. */
    public const ACCENTS = [
        'rose' => '#a8616d',
        'chocolate' => '#6b4226',
        'charcoal' => '#2e2119',
        'navy' => '#1f4e79',
        'emerald' => '#1e7a50',
    ];

    public const LOGO_SIZES = ['small' => 36, 'medium' => 52, 'large' => 72];

    public const LOGO_ALIGNS = ['start', 'center', 'end'];

    public const PAPERS = ['A4', 'Letter'];

    /** Parts of the document an administrator may hide. */
    public const TOGGLES = ['logo', 'center_contact', 'period', 'cycle', 'reference', 'notes', 'payments', 'payment_instructions', 'tax_number'];

    private const TEXT_LIMITS = ['header' => 200, 'footer' => 500, 'payment_instructions' => 1000, 'notes' => 500];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $empty = ['en' => '', 'ar' => '', 'ckb' => ''];

        return [
            'company' => [
                'name' => ['en' => 'Meta Style', 'ar' => 'Meta Style', 'ckb' => 'Meta Style'],
                'address' => $empty,
                'phone' => '',
                'email' => '',
                'website' => '',
                'tax_number' => '',
            ],
            'texts' => [
                'header' => $empty,
                'footer' => $empty,
                'payment_instructions' => $empty,
                'notes' => $empty,
            ],
            'show' => array_fill_keys(self::TOGGLES, true),
            'logo_size' => 'medium',
            'logo_align' => 'start',
            'layout' => 'classic',
            'accent' => 'rose',
            'paper' => 'A4',
        ];
    }

    /**
     * Stored or submitted values brought to the current shape, validated.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function normalize(array $input): array
    {
        $defaults = self::defaults();
        $company = is_array($input['company'] ?? null) ? $input['company'] : [];
        $texts = is_array($input['texts'] ?? null) ? $input['texts'] : [];
        $show = is_array($input['show'] ?? null) ? $input['show'] : [];

        $clean = [
            'company' => [
                'name' => $this->localized($company['name'] ?? $defaults['company']['name'], 120, true),
                'address' => $this->localized($company['address'] ?? [], 300),
                'phone' => $this->plain($company['phone'] ?? '', 48),
                'email' => $this->email($company['email'] ?? ''),
                'website' => $this->website($company['website'] ?? ''),
                'tax_number' => $this->plain($company['tax_number'] ?? '', 64),
            ],
            'texts' => [],
            'show' => [],
            'logo_size' => $this->choice($input['logo_size'] ?? 'medium', array_keys(self::LOGO_SIZES)),
            'logo_align' => $this->choice($input['logo_align'] ?? 'start', self::LOGO_ALIGNS),
            'layout' => $this->choice($input['layout'] ?? 'classic', self::LAYOUTS),
            'accent' => $this->choice($input['accent'] ?? 'rose', array_keys(self::ACCENTS)),
            'paper' => $this->choice($input['paper'] ?? 'A4', self::PAPERS),
        ];
        foreach (self::TEXT_LIMITS as $key => $limit) {
            $clean['texts'][$key] = $this->localized($texts[$key] ?? [], $limit);
        }
        foreach (self::TOGGLES as $toggle) {
            $clean['show'][$toggle] = (bool) ($show[$toggle] ?? true);
        }

        return $clean;
    }

    /**
     * Stored values over the defaults, WITHOUT validation — for reading what
     * was saved earlier (it was validated when it was saved).
     *
     * @param  array<string, mixed>  $stored
     * @return array<string, mixed>
     */
    public static function hydrate(array $stored): array
    {
        return array_replace_recursive(self::defaults(), $stored);
    }

    /** @return array<string, string> */
    private function localized(mixed $value, int $max, bool $englishRequired = false): array
    {
        $value = is_array($value) ? $value : [];
        $clean = [];
        foreach (self::LOCALES as $locale) {
            $clean[$locale] = $this->plain($value[$locale] ?? '', $max);
        }
        if ($englishRequired && $clean['en'] === '') {
            throw new DomainException(__('platform_settings.invoice.errors.name_required'));
        }

        return $clean;
    }

    private function plain(mixed $value, int $max): string
    {
        $value = trim((string) $value);
        if ($value !== strip_tags($value) || preg_match('/[<>]/', $value) === 1) {
            throw new DomainException(__('platform_settings.invoice.errors.text_only'));
        }
        if (mb_strlen($value) > $max) {
            throw new DomainException(__('platform_settings.invoice.errors.too_long'));
        }

        return $value;
    }

    private function email(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException(__('platform_settings.invoice.errors.email'));
        }

        return $value;
    }

    private function website(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false || ! in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new DomainException(__('platform_settings.invoice.errors.website'));
        }

        return $value;
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, array $allowed): string
    {
        $value = (string) $value;
        if (! in_array($value, $allowed, true)) {
            throw new DomainException(__('platform_settings.invoice.errors.choice'));
        }

        return $value;
    }
}
