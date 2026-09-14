<?php

declare(strict_types=1);

namespace App\Kernel\Localization\Casts;

use App\Kernel\Localization\TranslatedText;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a JSON column to a {@see TranslatedText}.
 *
 * Accepts a plain string on write for convenience — it is stored under the
 * current locale — so a caller that has only one language does not have to
 * build a map by hand.
 *
 * NULL IS PRESERVED IN BOTH DIRECTIONS. Phase 4 introduced genuinely optional
 * translatable columns — a service's long description, a branch's address — and
 * "absent" is different from "present but empty in every language". Coercing
 * null into an empty TranslatedText would make a missing description
 * indistinguishable from one somebody deliberately cleared, and would emit
 * `""` to the public menu where a client expects `null`.
 *
 * @implements CastsAttributes<TranslatedText|null, TranslatedText|array<string, string|null>|string|null>
 */
final class Translatable implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?TranslatedText
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return new TranslatedText;
        }

        $decoded = is_array($value) ? $value : json_decode((string) $value, true);

        if (! is_array($decoded)) {
            // A legacy or hand-written plain string: treat it as the fallback
            // locale rather than losing it.
            return TranslatedText::make((string) config('localization.fallback', 'en'), (string) $value);
        }

        /** @var array<string, string|null> $decoded */
        return TranslatedText::fromArray($decoded);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null) {
            return [$key => null];
        }

        // A plain string is stored under the current locale, so a caller with
        // one language does not have to build a map by hand.
        $text = match (true) {
            $value instanceof TranslatedText => $value,
            is_array($value) => TranslatedText::fromArray($value),
            default => TranslatedText::make(app()->getLocale(), $value),
        };

        return [$key => json_encode($text->all(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }
}
