<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog;

use Illuminate\Validation\ValidationException;

/**
 * Turns a catalog Action's refusal into words for the person using the page.
 *
 * The Actions speak English to the log and the API; the Manager speaks the
 * viewer's language. Refusals are matched by their FIELD KEY — a stable part
 * of each Action's contract — not by parsing the English sentence, so
 * rewording an Action's message cannot silently break a translation. The one
 * exception is the media kernel's single `file` key, which carries several
 * reasons; the gallery checks the image limit itself before it gets there.
 */
final class CatalogErrors
{
    /**
     * The first refusal of an exception, translated.
     */
    public static function first(ValidationException $e): string
    {
        foreach ($e->errors() as $key => $messages) {
            return self::message((string) $key, (string) ($messages[0] ?? ''));
        }

        return __('ui.errors.generic');
    }

    /**
     * @return array<string, string> the exception's field keys => translated messages
     */
    public static function all(ValidationException $e): array
    {
        $out = [];

        foreach ($e->errors() as $key => $messages) {
            $out[(string) $key] = self::message((string) $key, (string) ($messages[0] ?? ''));
        }

        return $out;
    }

    /**
     * The service drawer's form field an Action's refusal is about, or null
     * when it concerns the service as a whole (shown as a notice instead).
     */
    public static function editorField(string $key, string $primaryLocale): ?string
    {
        if (preg_match('/^variations\.(\d+)\.(name|duration_minutes|price_minor)$/', $key, $m) === 1) {
            return match ($m[2]) {
                'name' => "variations.{$m[1]}.name.{$primaryLocale}",
                'duration_minutes' => "variations.{$m[1]}.duration",
                default => "variations.{$m[1]}.price",
            };
        }

        return match ($key) {
            'name' => "serviceName.{$primaryLocale}",
            'duration_minutes' => 'duration',
            'price_minor' => 'price',
            'branch_ids' => 'branchUuids',
            'department' => 'departmentUuid',
            'category' => 'categoryUuid',
            'requirements' => 'requirements',
            default => null,
        };
    }

    public static function message(string $key, string $raw = ''): string
    {
        if (preg_match('/^variations\.\d+\.(name|duration_minutes|price_minor)$/', $key, $m) === 1) {
            return match ($m[1]) {
                'name' => __('manager_catalog.errors.variation_name'),
                'duration_minutes' => __('manager_catalog.errors.duration'),
                default => __('manager_catalog.errors.price_range'),
            };
        }

        return match ($key) {
            'name' => __('manager_catalog.errors.name'),
            'duration_minutes' => __('manager_catalog.errors.duration'),
            'price_minor' => __('manager_catalog.errors.price_range'),
            'branch_ids' => __('manager_catalog.errors.branches'),
            'department' => __('manager_catalog.errors.department'),
            'category' => __('manager_catalog.errors.category'),
            'service' => __('manager_catalog.errors.service_archived'),
            'requirements' => __('manager_catalog.errors.requirements'),
            'file' => str_contains($raw, 'image(s)')
                ? __('manager_catalog.errors.media_limit')
                : __('manager_catalog.errors.media_invalid'),
            default => __('ui.errors.generic'),
        };
    }
}
