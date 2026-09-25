<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance;

use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Modules\CenterSite\Domain\InvalidSiteContent;

/**
 * The site footer's public phone and WhatsApp numbers, edited with the ONE
 * phone field (country + national number) and stored as E.164.
 *
 * The builder holds them as `[country, number]` pairs beside the draft; on
 * save they are turned into one canonical number (PhoneNumber::fromParts), so
 * the public `tel:` and `wa.me` links always carry the right country code.
 */
final class SitePhones
{
    /** Builder key → footer field. */
    public const FIELDS = ['phone' => 'contact_phone', 'whatsapp' => 'contact_whatsapp'];

    /**
     * The stored numbers as the phone field edits them.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, array{number: string, country: string}>
     */
    public static function split(array $content): array
    {
        $phones = [];
        foreach (self::FIELDS as $key => $field) {
            $stored = trim((string) ($content['footer'][$field] ?? ''));
            $parsed = $stored === '' ? null : PhoneNumber::parse($stored);
            $country = $parsed?->country();
            $phones[$key] = $parsed instanceof PhoneNumber && $country !== null
                ? ['number' => $parsed->national(), 'country' => $country]
                : ['number' => $stored, 'country' => PhoneCountries::DEFAULT];
        }

        return $phones;
    }

    /**
     * The draft with the edited numbers written back as E.164.
     *
     * @param  array<string, mixed>  $content
     * @param  array<string, mixed>  $phones
     * @return array<string, mixed>
     *
     * @throws InvalidSiteContent when a number is not valid for its country
     */
    public static function merge(array $content, array $phones): array
    {
        foreach (self::FIELDS as $key => $field) {
            $pair = is_array($phones[$key] ?? null) ? $phones[$key] : [];
            $number = trim((string) ($pair['number'] ?? ''));
            if ($number === '') {
                $content['footer'][$field] = '';

                continue;
            }
            $phone = PhoneNumber::fromParts((string) ($pair['country'] ?? PhoneCountries::DEFAULT), $number);
            if (! $phone instanceof PhoneNumber) {
                throw new InvalidSiteContent('phone', 'footer.'.$field);
            }
            $content['footer'][$field] = $phone->e164;
        }

        return $content;
    }

    /** The phone field that shows a refusal at `$path`, if the path is one of ours. */
    public static function errorKey(string $path): ?string
    {
        $key = array_search(mb_substr($path, 7), self::FIELDS, true);

        return str_starts_with($path, 'footer.') && is_string($key) ? 'phones.'.$key.'.number' : null;
    }
}
