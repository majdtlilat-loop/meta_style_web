<?php

declare(strict_types=1);

namespace Tests\Support;

use RuntimeException;

/**
 * The names Livewire's browser-side `$wire` proxy keeps for itself.
 *
 * A `wire:click` expression runs against `$wire`, whose alias table resolves
 * `call`, `get`, `set`, `on`, `dispatch` … to built-ins BEFORE any component
 * method. Read from the bundled Livewire, so an upgrade that adds a name is
 * covered without anybody remembering to update a list here.
 */
final class LivewireWireAliases
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2).'/vendor/livewire/livewire/dist/livewire.esm.js');

        if (preg_match('/var aliases = \{(.*?)\};/s', $script, $table) !== 1
            || preg_match_all('/"([A-Za-z]+)":\s*"\$[A-Za-z]+"/', $table[1], $names) < 1) {
            throw new RuntimeException('The $wire alias table was not found in the bundled Livewire.');
        }

        return array_values(array_unique($names[1]));
    }
}
