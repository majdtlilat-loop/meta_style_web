<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Livewire\Component;
use Symfony\Component\Finder\Finder;
use Tests\Support\LivewireWireAliases;

/*
|--------------------------------------------------------------------------
| Every Livewire action is reachable from the browser
|--------------------------------------------------------------------------
|
| In the browser a `wire:*` expression is evaluated against Livewire's `$wire`
| proxy, and its alias table resolves `call`, `get`, `set`, `on`, `dispatch`
| … to Livewire's own functions BEFORE any component method. The queue board
| had an action named `call`: `wire:click="call('<uuid>')"` ran
| `$wire.$call('<uuid>')`, which asked the server for a METHOD named after the
| ticket uuid — a MethodNotFoundException on every Call and Recall press.
|
| Livewire's PHP test harness calls a method directly and never goes through
| that proxy, so feature tests cannot see this. These scans can.
|
*/

it('reads the reserved $wire names from the bundled Livewire', function (): void {
    // Pinned, so a Livewire upgrade that moves the table cannot empty the scans below.
    expect(LivewireWireAliases::names())->toContain('call', 'get', 'set', 'on', 'dispatch');
});

it('names no Livewire action after a reserved $wire alias', function (): void {
    $reserved = LivewireWireAliases::names();
    $offenders = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/app/Livewire')->name('*.php') as $file) {
        $class = 'App\\Livewire\\'.str_replace(['/', '.php'], ['\\', ''], Str::replace('\\', '/', $file->getRelativePathname()));

        if (! class_exists($class) || ! is_subclass_of($class, Component::class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        $properties = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || str_starts_with($property->getDeclaringClass()->getName(), 'Livewire\\')) {
                continue;
            }

            $properties[] = $property->getName();

            // $wire.<alias> is Livewire's own; the property is unreachable.
            if (in_array($property->getName(), $reserved, true)) {
                $offenders[] = $class.'::$'.$property->getName();
            }
        }

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            // Livewire's own public API (dispatch, js, …) is not an action of ours.
            if (str_starts_with($method->getDeclaringClass()->getName(), 'Livewire\\')) {
                continue;
            }

            if (in_array($method->getName(), $reserved, true)) {
                $offenders[] = $class.'::'.$method->getName().'()';
            }

            // On $wire a public property SHADOWS a method of the same name: the
            // queue screens' `rotate` toggle made `wire:click="rotate('<uuid>')"`
            // call a boolean instead of renewing the screen's link.
            if (in_array($method->getName(), $properties, true)) {
                $offenders[] = $class.'::'.$method->getName().'() is shadowed by $'.$method->getName();
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('gives every wire: event an action, and puts every confirmation where Livewire reads it', function (): void {
    $offenders = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/resources/views')->name('*.blade.php') as $file) {
        foreach (preg_split('/\R/', $file->getContents()) ?: [] as $index => $line) {
            // `wire:submit.prevent` with no action is evaluated as `$wire.` — a
            // syntax error — and Livewire then freezes the form until the next
            // request. `x-on:submit.prevent` is the way to just stop a submit.
            if (preg_match('/\bwire:(?:submit|click|change|keydown|keyup|blur|input)(?:\.[a-z.-]+)?(?=[\s>]|$)(?!\s*=)/', $line) === 1) {
                $offenders[] = 'no action: '.$file->getRelativePathname().':'.($index + 1);
            }

            // Livewire asks for confirmation only on the element that OWNS the
            // action (the <form> for wire:submit); on a submit button it is never read.
            if (preg_match('/<[^>]*\btype="submit"[^>]*\bwire:confirm\b|<[^>]*\bwire:confirm\b[^>]*\btype="submit"/', $line) === 1) {
                $offenders[] = 'confirm on a submit button: '.$file->getRelativePathname().':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});

it('binds no wire: action to a reserved $wire alias in any view', function (): void {
    $pattern = '/\bwire:[a-z.-]+\s*=\s*"\s*(?:'.implode('|', LivewireWireAliases::names()).')\s*\(/';
    $offenders = [];

    foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/resources/views')->name('*.blade.php') as $file) {
        foreach (preg_split('/\R/', $file->getContents()) ?: [] as $index => $line) {
            if (preg_match($pattern, $line) === 1) {
                $offenders[] = $file->getRelativePathname().':'.($index + 1);
            }
        }
    }

    expect($offenders)->toBe([]);
});
