<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application;

use App\Kernel\Contact\PhoneNumber;

/**
 * What every part of one public render shares: the language being rendered,
 * the center's primary language (the fallback), whether this is the draft
 * preview, the resolved media, and which section anchors actually appear.
 *
 * Text resolves requested → primary → any stored translation, so a section is
 * never blank because one language is missing (docs/07-LOCALIZATION.md §5.1).
 */
final class SiteRenderContext
{
    /** @var list<string> anchors of the sections this render shows */
    public array $anchors = [];

    /** Prefix for section links: empty on the home page itself. */
    public string $anchorBase = '';

    /**
     * @param  array<string, array{url: string, kind: string, width: int|null, height: int|null}>  $media
     * @param  array{booking: bool}  $features
     */
    public function __construct(
        public readonly string $locale,
        public readonly string $primary,
        public readonly bool $preview,
        public readonly array $media,
        public readonly array $features,
    ) {}

    public function text(mixed $localized): string
    {
        if (! is_array($localized)) {
            return '';
        }
        foreach ([$this->locale, $this->primary] as $candidate) {
            $value = $localized[$candidate] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        foreach ($localized as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @return array{url: string, alt: string}|null
     */
    public function image(mixed $uuid, mixed $alt = [], string $fallbackAlt = ''): ?array
    {
        $media = is_string($uuid) ? ($this->media[$uuid] ?? null) : null;
        if ($media === null || $media['kind'] !== 'image') {
            return null;
        }
        $text = $this->text($alt);

        return ['url' => $media['url'], 'alt' => $text !== '' ? $text : $fallbackAlt];
    }

    /**
     * @return array{url: string, poster: string|null}|null
     */
    public function video(mixed $uuid, mixed $poster = ''): ?array
    {
        $media = is_string($uuid) ? ($this->media[$uuid] ?? null) : null;
        if ($media === null || $media['kind'] !== 'video') {
            return null;
        }

        return ['url' => $media['url'], 'poster' => $this->image($poster)['url'] ?? null];
    }

    /**
     * A link's destination: an anchor on this page (only if that section is
     * shown), one of the center's own pages by key, or a stored https URL.
     */
    public function href(string $type, string $target): ?string
    {
        return match ($type) {
            'section' => in_array($target, $this->anchors, true) ? $this->anchorBase.'#'.$target : null,
            'page' => match ($target) {
                'home' => $this->homeUrl(),
                'list' => route('menu.public'),
                'booking' => $this->features['booking'] ? route('menu.book') : null,
                default => null,
            },
            'external' => str_starts_with($target, 'https://') ? $target : null,
            default => null,
        };
    }

    /**
     * @return array{label: string, href: string, style: string, new_tab: bool}|null
     */
    public function cta(mixed $cta): ?array
    {
        if (! is_array($cta) || ! ($cta['enabled'] ?? false)) {
            return null;
        }
        $label = $this->text($cta['label'] ?? []);
        $href = $this->href((string) ($cta['link_type'] ?? ''), (string) ($cta['target'] ?? ''));
        if ($label === '' || $href === null) {
            return null;
        }

        return [
            'label' => $label,
            'href' => $href,
            'style' => (string) ($cta['style'] ?? 'primary'),
            'new_tab' => ($cta['link_type'] ?? '') !== 'section' && (bool) ($cta['new_tab'] ?? false),
        ];
    }

    /**
     * @param  array<int|string, mixed>  $links
     * @return list<array{label: string, href: string, new_tab: bool, external: bool}>
     */
    public function links(array $links): array
    {
        $out = [];
        foreach ($links as $link) {
            if (! is_array($link) || ! ($link['enabled'] ?? true)) {
                continue;
            }
            $label = $this->text($link['label'] ?? []);
            $type = (string) ($link['link_type'] ?? '');
            $href = $this->href($type, (string) ($link['target'] ?? ''));
            if ($label !== '' && $href !== null) {
                $out[] = ['label' => $label, 'href' => $href, 'new_tab' => $type !== 'section' && (bool) ($link['new_tab'] ?? false), 'external' => $type === 'external'];
            }
        }

        return $out;
    }

    public function homeUrl(): string
    {
        return $this->preview
            ? route('center.appearance.site.preview', ['lang' => $this->locale])
            : route('center.public', $this->locale === $this->primary ? [] : ['locale' => $this->locale]);
    }

    /**
     * `tel:`, `https://wa.me/` and `mailto:` links built from validated
     * values; numbers from their canonical E.164 form.
     *
     * @return array{label: string, href: string}|null
     */
    public function contact(string $kind, ?string $value): ?array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if ($kind === 'email') {
            return filter_var($value, FILTER_VALIDATE_EMAIL) === false ? null : ['label' => $value, 'href' => 'mailto:'.$value];
        }
        // Stored numbers are E.164 (footer: SitePhones; branches: the phone
        // field). An older national-format branch number is read with the
        // platform's configured default country — the kernel's one parsing
        // rule — and a value that cannot be made canonical is not shown.
        $phone = PhoneNumber::parse($value);
        if (! $phone instanceof PhoneNumber) {
            return null;
        }
        $digits = mb_substr($phone->e164, 1);

        return $kind === 'whatsapp'
            ? ['label' => $phone->international(), 'href' => 'https://wa.me/'.$digits]
            : ['label' => $phone->international(), 'href' => 'tel:'.$phone->e164];
    }
}
