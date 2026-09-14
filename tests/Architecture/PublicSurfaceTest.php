<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The public surface — source rules
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-036.
|
| The runtime half of this boundary — which middleware the public routes carry —
| needs a booted application and lives in
| tests/Feature/Menu/PublicRouteBoundaryTest.php. These are the rules that can
| be checked by reading the source.
|
*/

/**
 * The resource source, with comments stripped.
 *
 * A scan that fires on the doc block explaining the rule is a scan nobody
 * keeps.
 */
function publicMenuResourceCode(): string
{
    $source = (string) file_get_contents(
        dirname(__DIR__, 2).'/app/Modules/Menu/Application/PublicMenuResource.php'
    );

    $code = [];

    foreach (explode("\n", $source) as $line) {
        $trimmed = ltrim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
            continue;
        }

        $code[] = $line;
    }

    return implode("\n", $code);
}

it('builds the public menu response from an allow-list, never a model dump', function (): void {
    // A deny-list is defeated the day somebody adds a `cost_price` column and
    // forgets this file. An allow-list is not.
    expect(publicMenuResourceCode())
        ->not->toContain('->toArray()')
        ->not->toContain('->attributesToArray()')
        ->not->toContain('->makeHidden(')
        ->not->toContain('->except(');
});

it('exposes no internal identifier from the public menu resource', function (): void {
    $violations = [];

    foreach (explode("\n", publicMenuResourceCode()) as $index => $line) {
        // `'id' =>` or `'..._id' =>` in the emitted shape would put an
        // auto-increment key on the wire (docs/08-AUDIT-SECURITY.md §19).
        if (preg_match("/'(id|[a-z_]+_id)'\s*=>/", $line) === 1) {
            $violations[] = ($index + 1).': '.trim($line);
        }
    }

    expect($violations)->toBe([]);
});
