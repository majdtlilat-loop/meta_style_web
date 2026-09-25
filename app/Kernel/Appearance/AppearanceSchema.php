<?php

declare(strict_types=1);

namespace App\Kernel\Appearance;

use InvalidArgumentException;

/**
 * The code-owned description of one appearance document a center may edit —
 * the booking page, the cart page, printed documents.
 *
 * A closed list, like `config/menu.php`: colours are `#rrggbb`, choices come
 * from a fixed set, switches are booleans and texts are plain text with a
 * length cap. There is no field for markup, CSS or a URL, because every value
 * ends up on a page a guest opens or a paper a customer takes home (ADR-038).
 */
final readonly class AppearanceSchema
{
    /**
     * @param  array<string, string>  $colours  name => default `#rrggbb`
     * @param  array<string, array{choices: list<string>, default: string}>  $choices
     * @param  array<string, bool>  $flags  name => default
     * @param  array<string, int>  $texts  name => maximum length
     */
    public function __construct(
        public string $name,
        public array $colours = [],
        public array $choices = [],
        public array $flags = [],
        public array $texts = [],
    ) {}

    /**
     * @param  array<string, mixed>  $config  `colours`, `choices` (name => list, first is the default unless `defaults` says otherwise), `defaults`, `flags`, `texts`
     */
    public static function fromConfig(string $name, array $config): self
    {
        /** @var array<string, string> $colours */
        $colours = is_array($config['colours'] ?? null) ? $config['colours'] : [];
        /** @var array<string, list<string>> $choiceLists */
        $choiceLists = is_array($config['choices'] ?? null) ? $config['choices'] : [];
        /** @var array<string, string> $defaults */
        $defaults = is_array($config['defaults'] ?? null) ? $config['defaults'] : [];
        /** @var array<string, bool> $flags */
        $flags = is_array($config['flags'] ?? null) ? $config['flags'] : [];
        /** @var array<string, int> $texts */
        $texts = is_array($config['texts'] ?? null) ? $config['texts'] : [];

        $choices = [];

        foreach ($choiceLists as $key => $list) {
            if ($list === []) {
                throw new InvalidArgumentException("Appearance choice [{$key}] has no values.");
            }

            $default = $defaults[$key] ?? $list[0];
            $choices[$key] = ['choices' => $list, 'default' => in_array($default, $list, true) ? $default : $list[0]];
        }

        return new self($name, $colours, $choices, $flags, $texts);
    }

    public function has(string $key): bool
    {
        return isset($this->colours[$key]) || isset($this->choices[$key]) || isset($this->flags[$key]);
    }
}
