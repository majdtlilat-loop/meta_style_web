<?php

declare(strict_types=1);

namespace App\Kernel\Appearance;

/**
 * One validated appearance document: its settings and its per-language texts.
 *
 * Two ways in. {@see fromInput()} is STRICT — an unknown key, a colour that is
 * not a colour, a choice that is not listed or a text that contains markup is
 * rejected with {@see AppearanceRejected}, because silently dropping it would
 * store something other than what the owner saw. {@see fromStored()} is
 * LENIENT — a row written by an older release keeps working, each bad value
 * falling back to its default, so a renderer never fails on a guest.
 *
 * Texts keep every supported language they were given, not only the enabled
 * ones: switching a language off hides its fields, it never deletes what was
 * written (docs/07-LOCALIZATION.md).
 */
final readonly class Appearance
{
    private const MARKUP = '/<\s*[a-z!\/?]/i';

    private const CONTROL = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u';

    /**
     * @param  array<string, string|bool>  $values
     * @param  array<string, array<string, string>>  $texts
     */
    private function __construct(
        public AppearanceSchema $schema,
        public array $values,
        public array $texts,
    ) {}

    public static function defaults(AppearanceSchema $schema): self
    {
        $values = $schema->colours;

        foreach ($schema->choices as $key => $choice) {
            $values[$key] = $choice['default'];
        }

        foreach ($schema->flags as $key => $default) {
            $values[$key] = $default;
        }

        return new self($schema, $values, []);
    }

    /**
     * @param  array{values?: mixed, texts?: mixed}|array<string, mixed>  $input
     * @param  list<string>  $locales  the languages a text may be stored in
     *
     * @throws AppearanceRejected
     */
    public static function fromInput(AppearanceSchema $schema, array $input, array $locales): self
    {
        $defaults = self::defaults($schema);
        $values = $defaults->values;

        foreach (is_array($input['values'] ?? null) ? $input['values'] : [] as $key => $value) {
            $key = (string) $key;
            $values[$key] = self::strictValue($schema, $key, $value);
        }

        $texts = [];

        foreach (is_array($input['texts'] ?? null) ? $input['texts'] : [] as $key => $perLocale) {
            $key = (string) $key;

            if (! isset($schema->texts[$key])) {
                throw new AppearanceRejected('texts.'.$key, 'unknown_setting');
            }

            foreach (is_array($perLocale) ? $perLocale : [] as $locale => $text) {
                $locale = (string) $locale;
                $field = 'texts.'.$key.'.'.$locale;

                if (! in_array($locale, $locales, true)) {
                    throw new AppearanceRejected($field, 'unknown_language');
                }

                if ($text === null) {
                    continue;
                }

                if (! is_string($text)) {
                    throw new AppearanceRejected($field, 'invalid_text');
                }

                $clean = self::clean($text);

                if (preg_match(self::MARKUP, $clean) === 1) {
                    throw new AppearanceRejected($field, 'markup');
                }

                if (mb_strlen($clean) > $schema->texts[$key]) {
                    throw new AppearanceRejected($field, 'too_long', ['max' => $schema->texts[$key]]);
                }

                if ($clean !== '') {
                    $texts[$key][$locale] = $clean;
                }
            }
        }

        return new self($schema, $values, $texts);
    }

    /**
     * @param  list<string>  $locales
     */
    public static function fromStored(AppearanceSchema $schema, mixed $raw, array $locales): self
    {
        $defaults = self::defaults($schema);

        if (! is_array($raw)) {
            return $defaults;
        }

        $values = $defaults->values;

        foreach (is_array($raw['values'] ?? null) ? $raw['values'] : [] as $key => $value) {
            try {
                $values[(string) $key] = self::strictValue($schema, (string) $key, $value);
            } catch (AppearanceRejected) {
                // An older or damaged row: that one value keeps its default.
            }
        }

        $texts = [];

        foreach (is_array($raw['texts'] ?? null) ? $raw['texts'] : [] as $key => $perLocale) {
            $key = (string) $key;

            if (! isset($schema->texts[$key]) || ! is_array($perLocale)) {
                continue;
            }

            foreach ($perLocale as $locale => $text) {
                if (! is_string($text) || ! in_array((string) $locale, $locales, true)) {
                    continue;
                }

                $clean = self::clean($text);

                if ($clean !== '' && preg_match(self::MARKUP, $clean) !== 1) {
                    $texts[$key][(string) $locale] = mb_substr($clean, 0, $schema->texts[$key]);
                }
            }
        }

        return new self($schema, $values, $texts);
    }

    public function colour(string $key): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) ? $value : ($this->schema->colours[$key] ?? '#000000');
    }

    public function choice(string $key): string
    {
        $value = $this->values[$key] ?? null;

        return is_string($value) ? $value : ($this->schema->choices[$key]['default'] ?? '');
    }

    public function flag(string $key): bool
    {
        return (bool) ($this->values[$key] ?? ($this->schema->flags[$key] ?? false));
    }

    /**
     * The text in `$locale`, else in the fallback language, else null — the
     * renderer then shows its own built-in, translated default copy.
     */
    public function text(string $key, string $locale, ?string $fallback = null): ?string
    {
        return $this->texts[$key][$locale]
            ?? ($fallback !== null ? ($this->texts[$key][$fallback] ?? null) : null);
    }

    /**
     * @return array<string, string>
     */
    public function texts(string $key): array
    {
        return $this->texts[$key] ?? [];
    }

    /**
     * @return array{values: array<string, string|bool>, texts: array<string, array<string, string>>}
     */
    public function toArray(): array
    {
        return ['values' => $this->values, 'texts' => $this->texts];
    }

    /**
     * Which settings and texts differ — what an audit entry records, rather
     * than whole paragraphs of copy.
     *
     * @return list<string>
     */
    public function changedKeys(self $other): array
    {
        $changed = [];

        foreach (array_unique(array_merge(array_keys($this->values), array_keys($other->values))) as $key) {
            if (($this->values[$key] ?? null) !== ($other->values[$key] ?? null)) {
                $changed[] = (string) $key;
            }
        }

        foreach (array_unique(array_merge(array_keys($this->texts), array_keys($other->texts))) as $key) {
            if (($this->texts[$key] ?? []) != ($other->texts[$key] ?? [])) {
                $changed[] = 'texts.'.$key;
            }
        }

        return $changed;
    }

    /**
     * @throws AppearanceRejected
     */
    private static function strictValue(AppearanceSchema $schema, string $key, mixed $value): string|bool
    {
        $field = 'values.'.$key;

        if (isset($schema->colours[$key])) {
            $colour = is_string($value) ? mb_strtolower(trim($value)) : null;

            if ($colour === null || preg_match('/^#[0-9a-f]{6}$/', $colour) !== 1) {
                throw new AppearanceRejected($field, 'invalid_colour');
            }

            return $colour;
        }

        if (isset($schema->choices[$key])) {
            if (! is_string($value) || ! in_array($value, $schema->choices[$key]['choices'], true)) {
                throw new AppearanceRejected($field, 'invalid_choice');
            }

            return $value;
        }

        if (isset($schema->flags[$key])) {
            $flag = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            if ($flag === null) {
                throw new AppearanceRejected($field, 'invalid_choice');
            }

            return $flag;
        }

        throw new AppearanceRejected($field, 'unknown_setting');
    }

    private static function clean(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim((string) preg_replace(self::CONTROL, '', $text));
    }
}
