<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Customer privacy — source rules
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-040 · docs/13-ROADMAP.md Phase 5 §§8, 15, 22.
|
| Two promises that would be easy to break by accident, and impossible to
| notice once broken.
|
*/

/**
 * Application source with comments stripped.
 *
 * A scan that fires on the doc block explaining the rule is a scan nobody keeps.
 *
 * @return array<string, string>
 */
function customerSource(): array
{
    $files = [];

    foreach (appSourceFiles() as $path => $contents) {
        $code = [];

        foreach (explode("\n", $contents) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $code[] = $line;
        }

        $files[$path] = implode("\n", $code);
    }

    return $files;
}

/**
 * Every Blade template, keyed by a forward-slash relative path.
 *
 * Built by walking the tree rather than by globbing: PHP's `glob()` has no
 * recursive wildcard, and on Windows the separators it returns depend on the
 * pattern — both of which quietly shrink what a scan actually covers.
 *
 * @return array<string, string>
 */
function bladeTemplates(): array
{
    // Normalised through realpath and forward slashes BEFORE anything is
    // stripped: on Windows `getRealPath()` returns backslashes, so comparing
    // against a forward-slash root strips nothing and every key comes out
    // absolute.
    $root = str_replace(DIRECTORY_SEPARATOR, '/', (string) realpath(dirname(__DIR__, 2).'/resources/views'));

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $templates = [];

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $path = $file->getRealPath();

        if ($path === false) {
            continue;
        }

        $relative = str_replace($root, '', str_replace(DIRECTORY_SEPARATOR, '/', $path));

        $templates[ltrim($relative, '/')] = (string) file_get_contents($path);
    }

    return $templates;
}

it('scans templates that actually exist', function (): void {
    // Guards the scan below: a glob that matched nothing would pass silently.
    expect(bladeTemplates())->not->toBeEmpty()
        ->and(array_keys(bladeTemplates()))->toContain('livewire/customer/account.blade.php');
});

it('never marks a phone number verified, because nothing can verify one yet', function (): void {
    // Reading the column, casting it, and explicitly nulling it are all fine.
    // Assigning it a real VALUE is what must not exist: with no SMS or WhatsApp
    // provider, such a write is a claim the rest of the system would then trust
    // (ADR-040).
    $permitted = ['null', "'datetime'"];

    $violations = [];

    foreach (customerSource() as $path => $contents) {
        foreach (explode("\n", $contents) as $index => $line) {
            if (! str_contains($line, 'phone_verified_at')) {
                continue;
            }

            $assigned = null;

            if (preg_match("/'phone_verified_at'\s*=>\s*([^,\]\)]+)/", $line, $matches) === 1) {
                $assigned = trim($matches[1]);
            } elseif (preg_match('/->phone_verified_at\s*=\s*([^;]+)/', $line, $matches) === 1) {
                $assigned = trim($matches[1]);
            }

            if ($assigned !== null && ! in_array($assigned, $permitted, true)) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps contact masking in one place, never in a template', function (): void {
    /*
     * A template that reaches for the raw column has decided masking does not
     * apply to it. `CustomerPresenter` is the only thing allowed to make that
     * call (docs/06-AUTH-ROLES-PERMISSIONS.md §6).
     *
     * Three deliberate exceptions:
     *   customer/account  the customer's OWN details — masking protects a
     *                     person from staff who do not need their details, not
     *                     from the person themselves
     *   menu/, branches   a branch's phone and email are business contact
     *                     details a center publishes, not personal data
     */
    $exempt = ['livewire/customer/account', 'menu/', 'livewire/center/branches'];

    $violations = [];

    foreach (bladeTemplates() as $path => $contents) {
        foreach ($exempt as $prefix) {
            if (str_contains($path, $prefix)) {
                continue 2;
            }
        }

        foreach (['->phone', '->email', 'phone_display'] as $needle) {
            if (str_contains($contents, $needle)) {
                $violations[] = $path.' uses '.$needle;
            }
        }
    }

    expect($violations)->toBe([]);
});
