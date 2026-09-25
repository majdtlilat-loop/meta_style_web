<?php

declare(strict_types=1);

namespace App\Livewire\Center\Appearance\Support;

use App\Kernel\Appearance\AppearanceRejected;
use App\Modules\Menu\Domain\MenuPresentationRejected;

/**
 * Everything the menu editor shows, as translated arrays: the templates, the
 * option groups, the section settings. Built from `config/menu.php` so the
 * form can never offer a value the validator would refuse — and never shows a
 * raw key: every label comes from `manager_appearance`.
 */
final class MenuOptions
{
    /**
     * The option groups, in the order the editor shows them.
     */
    private const GROUPS = [
        'layout' => ['layout', 'card_style', 'image_ratio', 'price_style', 'density', 'corners'],
        'type' => ['font', 'type_scale', 'hero_style', 'gradient_angle', 'background'],
        'actions' => ['cta_style', 'language_switch'],
    ];

    /**
     * @return list<array{key: string, label: string, primary: string, accent: string, dark: bool}>
     */
    public static function templates(): array
    {
        /** @var array<string, array{theme: array<string, string>}> $templates */
        $templates = config('menu.templates', []);
        $rows = [];

        foreach ($templates as $key => $template) {
            $rows[] = [
                'key' => $key,
                'label' => __('manager_appearance.menu.templates.'.$key.'.label'),
                'primary' => (string) ($template['theme']['primary'] ?? '#111827'),
                'accent' => (string) ($template['theme']['accent'] ?? '#6366f1'),
                'dark' => ($template['theme']['background'] ?? 'light') === 'dark',
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, list<array{key: string, label: string, help: string|null, options: array<string, string>}>>
     */
    public static function groups(): array
    {
        /** @var array<string, list<string>> $options */
        $options = config('menu.theme.options', []);
        $groups = [];

        foreach (self::GROUPS as $group => $keys) {
            foreach ($keys as $key) {
                if (! isset($options[$key])) {
                    continue;
                }

                $groups[$group][] = [
                    'key' => $key,
                    'label' => __('manager_appearance.menu.options.'.$key.'.label'),
                    'help' => self::optional('manager_appearance.menu.options.'.$key.'.help'),
                    'options' => self::values('manager_appearance.menu.options.'.$key.'.values', $options[$key]),
                ];
            }
        }

        return $groups;
    }

    /**
     * @param  list<array{key: string, visible: bool, config: array<string, string|bool|int>}>  $sections
     * @return list<array{key: string, label: string, help: string, settings: list<array{key: string, label: string, type: string, options: array<string, string>, min: int, max: int}>}>
     */
    public static function sections(array $sections): array
    {
        /** @var array<string, array{config: list<string>}> $catalog */
        $catalog = config('menu.sections', []);
        /** @var array<string, mixed> $rules */
        $rules = config('menu.section_config', []);
        $rows = [];

        foreach ($sections as $section) {
            $settings = [];

            foreach ($catalog[$section['key']]['config'] ?? [] as $setting) {
                $rule = $rules[$setting] ?? null;
                [$min, $max] = is_string($rule) && str_starts_with($rule, 'int:')
                    ? array_map('intval', explode('..', substr($rule, 4)))
                    : [0, 0];

                $settings[] = [
                    'key' => $setting,
                    'label' => __('manager_appearance.menu.settings.'.$setting.'.label'),
                    'type' => $rule === 'bool' ? 'bool' : (is_array($rule) ? 'choice' : 'int'),
                    'options' => is_array($rule) ? self::values('manager_appearance.menu.settings.'.$setting.'.values', $rule) : [],
                    'min' => $min,
                    'max' => $max,
                ];
            }

            $rows[] = [
                'key' => $section['key'],
                'label' => __('manager_appearance.menu.sections.'.$section['key'].'.label'),
                'help' => __('manager_appearance.menu.sections.'.$section['key'].'.help'),
                'settings' => $settings,
            ];
        }

        return $rows;
    }

    /**
     * A rejected value, said in the viewer's language: which setting, and
     * why — never the English validator sentence with its internal keys.
     */
    public static function message(MenuPresentationRejected $e): string
    {
        $setting = (string) ($e->context['setting'] ?? '');
        $label = $setting === '' ? '' : self::settingLabel($setting);

        return __('manager_appearance.errors.'.self::reason($e->reason), [
            'setting' => $label,
            'min' => $e->context['min'] ?? '',
            'max' => $e->context['max'] ?? '',
        ]);
    }

    /**
     * The same for the booking, cart and print documents.
     */
    public static function appearanceMessage(AppearanceRejected $e): string
    {
        return __('manager_appearance.errors.'.self::reason($e->reason), [
            'setting' => '',
            'max' => $e->context['max'] ?? '',
            'min' => '',
        ]);
    }

    private static function reason(string $reason): string
    {
        return in_array($reason, ['invalid_colour', 'invalid_choice', 'out_of_range', 'too_long', 'markup', 'unknown_language'], true)
            ? $reason
            : 'invalid';
    }

    private static function settingLabel(string $setting): string
    {
        foreach (['manager_appearance.menu.options.'.$setting.'.label', 'manager_appearance.menu.settings.'.$setting.'.label', 'manager_appearance.menu.colours.'.$setting] as $key) {
            $label = self::optional($key);

            if ($label !== null) {
                return $label;
            }
        }

        return __('manager_appearance.errors.this_setting');
    }

    /**
     * @param  list<string>  $values
     * @return array<string, string>
     */
    private static function values(string $prefix, array $values): array
    {
        $labels = [];

        foreach ($values as $value) {
            $labels[$value] = __($prefix.'.'.$value);
        }

        return $labels;
    }

    private static function optional(string $key): ?string
    {
        $value = __($key);

        return is_string($value) && $value !== $key ? $value : null;
    }
}
