<?php

declare(strict_types=1);

namespace App\Modules\Menu\Domain;

/**
 * A validated menu appearance: a template, a theme, and an ordered section list.
 *
 * THIS IS THE SECURITY BOUNDARY of the "customizable menu". Every value that
 * reaches a Blade template passes through here first and must match the code-
 * owned catalog in `config/menu.php`: a known template key, a known section
 * key, a colour matching a hex pattern, a font from an approved list.
 *
 * There is no field anywhere that accepts markup — no HTML block, no custom
 * CSS, no script. That is not a scope decision, it is the point: a
 * center-authored `<script>` on a guest-accessible page is stored XSS against
 * that center's own customers, and "we sanitise it" is a promise no one keeps
 * across a whole product. Unrestricted CSS is barely better — it can reposition
 * an element over a link and restyle the page into something else entirely.
 *
 * Anything unrecognised is REJECTED, not dropped. Silently discarding a value
 * would let a stored draft quietly differ from what the owner thought they
 * configured. Rejections are {@see MenuPresentationRejected}, which keeps the
 * English message the API answers with and adds a reason code the Manager
 * translates.
 */
final readonly class MenuPresentation
{
    /**
     * @param  array<string, string|bool|int>  $theme
     * @param  list<array{key: string, visible: bool, config: array<string, string|bool|int>}>  $sections
     */
    private function __construct(
        public string $templateKey,
        public array $theme,
        public array $sections,
    ) {}

    /**
     * The starting point for a newly provisioned center: a complete, working
     * menu it can publish immediately rather than an empty page to assemble.
     */
    public static function default(): self
    {
        $template = self::defaultTemplateKey();

        /** @var array<mixed> $sections */
        $sections = config('menu.default_sections', []);

        // Through the validator like everything else, so a new center gets the
        // template's preset and every section's defaults filled in.
        return self::fromArray([
            'template_key' => $template,
            'theme' => self::preset($template),
            'sections' => $sections,
        ]);
    }

    /**
     * What choosing a template in the editor applies: its theme with its
     * preset on top. Only ever reaches a live page through a publish.
     *
     * @return array<string, string|bool|int>
     */
    public static function preset(string $template): array
    {
        /** @var array<string, string|bool|int> $theme */
        $theme = config("menu.templates.{$template}.theme", []);
        /** @var array<string, string|bool|int> $preset */
        $preset = config("menu.templates.{$template}.preset", []);

        return array_merge($theme, $preset);
    }

    /**
     * Builds from untrusted input — a form post, an API body, a stored row.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws MenuPresentationRejected
     */
    public static function fromArray(array $input): self
    {
        $template = self::validateTemplate($input['template_key'] ?? null);

        /** @var array<string, mixed> $themeInput */
        $themeInput = is_array($input['theme'] ?? null) ? $input['theme'] : [];

        /** @var array<mixed> $sectionsInput */
        $sectionsInput = is_array($input['sections'] ?? null) ? $input['sections'] : [];

        return new self(
            $template,
            self::validateTheme($themeInput, $template),
            self::validateSections($sectionsInput),
        );
    }

    /**
     * @return array{template_key: string, theme: array<string, string|bool|int>, sections: list<array{key: string, visible: bool, config: array<string, string|bool|int>}>}
     */
    public function toArray(): array
    {
        return [
            'template_key' => $this->templateKey,
            'theme' => $this->theme,
            'sections' => $this->sections,
        ];
    }

    /**
     * Sections a customer actually sees, in order.
     *
     * @return list<array{key: string, visible: bool, config: array<string, string|bool|int>}>
     */
    public function visibleSections(): array
    {
        return array_values(array_filter($this->sections, static fn (array $s): bool => $s['visible']));
    }

    public function hasSection(string $key): bool
    {
        foreach ($this->visibleSections() as $section) {
            if ($section['key'] === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string|bool|int>
     */
    public function sectionConfig(string $key): array
    {
        foreach ($this->sections as $section) {
            if ($section['key'] === $key) {
                return $section['config'];
            }
        }

        return [];
    }

    public function themeValue(string $key, string $fallback = ''): string
    {
        return isset($this->theme[$key]) ? (string) $this->theme[$key] : $fallback;
    }

    /**
     * Whether two presentations would render the same page — the editor's
     * "unpublished changes" signal.
     */
    public function sameAs(self $other): bool
    {
        return $this->toArray() == $other->toArray();
    }

    // -----------------------------------------------------------------
    // Validation
    // -----------------------------------------------------------------

    private static function validateTemplate(mixed $key): string
    {
        /** @var array<string, mixed> $templates */
        $templates = config('menu.templates', []);

        if (! is_string($key) || ! array_key_exists($key, $templates)) {
            throw new MenuPresentationRejected(sprintf(
                'Unknown menu template [%s]. Available: %s.',
                is_scalar($key) ? (string) $key : gettype($key),
                implode(', ', array_keys($templates)),
            ), 'unknown_template');
        }

        return $key;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, string|bool|int>
     */
    private static function validateTheme(array $input, string $template): array
    {
        /** @var array<string, string|bool|int> $theme */
        $theme = config("menu.templates.{$template}.theme", []);

        /** @var list<string> $colorKeys */
        $colorKeys = config('menu.theme.colors', []);
        $pattern = config('menu.theme.color_pattern');
        /** @var array<string, list<string>> $options */
        $options = config('menu.theme.options', []);

        foreach ($input as $key => $value) {
            $key = (string) $key;

            if (in_array($key, $colorKeys, true)) {
                if (! is_string($value) || ! is_string($pattern) || preg_match($pattern, $value) !== 1) {
                    throw new MenuPresentationRejected("[{$key}] must be a six-digit hex colour such as #1a2b3c.", 'invalid_colour', ['setting' => $key]);
                }

                $theme[$key] = mb_strtolower($value);

                continue;
            }

            if (! array_key_exists($key, $options)) {
                // Rejected rather than ignored: an unknown key means the caller
                // believes it configured something, and silently dropping it
                // makes the stored draft differ from what they saw.
                throw new MenuPresentationRejected("[{$key}] is not a theme option.", 'unknown_option', ['setting' => $key]);
            }

            if (! is_string($value) || ! in_array($value, $options[$key], true)) {
                throw new MenuPresentationRejected(sprintf(
                    '[%s] must be one of: %s.',
                    $key,
                    implode(', ', $options[$key]),
                ), 'invalid_choice', ['setting' => $key]);
            }

            $theme[$key] = $value;
        }

        return $theme;
    }

    /**
     * @param  array<mixed>  $input
     * @return list<array{key: string, visible: bool, config: array<string, string|bool|int>}>
     */
    private static function validateSections(array $input): array
    {
        /** @var array<string, array{config: list<string>}> $catalog */
        $catalog = config('menu.sections', []);

        $sections = [];
        $seen = [];

        foreach ($input as $section) {
            if (! is_array($section) || ! isset($section['key']) || ! is_string($section['key'])) {
                throw new MenuPresentationRejected('Each section needs a key.', 'missing_key');
            }

            $key = $section['key'];

            if (! array_key_exists($key, $catalog)) {
                // Covers the placeholder problem too: `offers` is absent from
                // the catalog because that module does not exist, so a section
                // promising it cannot be configured.
                throw new MenuPresentationRejected(sprintf(
                    'Unknown menu section [%s]. Available: %s.',
                    $key,
                    implode(', ', array_keys($catalog)),
                ), 'unknown_section', ['section' => $key]);
            }

            if (in_array($key, $seen, true)) {
                throw new MenuPresentationRejected("Section [{$key}] appears more than once.", 'duplicate_section', ['section' => $key]);
            }

            $seen[] = $key;

            /** @var array<string, mixed> $configInput */
            $configInput = is_array($section['config'] ?? null) ? $section['config'] : [];

            $sections[] = [
                'key' => $key,
                'visible' => (bool) ($section['visible'] ?? false),
                'config' => self::validateSectionConfig($key, $configInput, $catalog[$key]['config']),
            ];
        }

        if ($sections === []) {
            throw new MenuPresentationRejected('A menu needs at least one section.', 'no_sections');
        }

        return $sections;
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $allowed
     * @return array<string, string|bool|int>
     */
    private static function validateSectionConfig(string $section, array $input, array $allowed): array
    {
        /** @var array<string, mixed> $rules */
        $rules = config('menu.section_config', []);

        $config = [];

        foreach ($input as $key => $value) {
            $key = (string) $key;

            if (! in_array($key, $allowed, true)) {
                throw new MenuPresentationRejected("[{$key}] is not a setting of the [{$section}] section.", 'unknown_setting', ['setting' => $key, 'section' => $section]);
            }

            $rule = $rules[$key] ?? null;

            $config[$key] = match (true) {
                $rule === 'bool' => (bool) $value,
                is_string($rule) && str_starts_with($rule, 'int:') => self::validateInt($key, $value, $rule),
                is_array($rule) => self::validateChoice($key, $value, $rule),
                default => throw new MenuPresentationRejected("[{$key}] has no validation rule.", 'no_rule', ['setting' => $key]),
            };
        }

        // A setting the stored section does not carry means what the renderer
        // did before that setting existed — filled here, so every reader sees
        // one complete, validated shape.
        /** @var array<string, string|bool|int> $defaults */
        $defaults = config("menu.section_defaults.{$section}", []);

        foreach ($defaults as $key => $value) {
            if (in_array($key, $allowed, true) && ! array_key_exists($key, $config)) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    private static function validateInt(string $key, mixed $value, string $rule): int
    {
        [$min, $max] = array_map('intval', explode('..', mb_substr($rule, 4)));

        if (! is_numeric($value) || (int) $value < $min || (int) $value > $max) {
            throw new MenuPresentationRejected("[{$key}] must be a whole number between {$min} and {$max}.", 'out_of_range', ['setting' => $key, 'min' => $min, 'max' => $max]);
        }

        return (int) $value;
    }

    /**
     * @param  list<string>  $choices
     */
    private static function validateChoice(string $key, mixed $value, array $choices): string
    {
        if (! is_string($value) || ! in_array($value, $choices, true)) {
            throw new MenuPresentationRejected(sprintf(
                '[%s] must be one of: %s.',
                $key,
                implode(', ', $choices),
            ), 'invalid_choice', ['setting' => $key]);
        }

        return $value;
    }

    private static function defaultTemplateKey(): string
    {
        $key = config('menu.default_template');

        return is_string($key) ? $key : 'minimal';
    }
}
