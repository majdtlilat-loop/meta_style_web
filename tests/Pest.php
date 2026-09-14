<?php

declare(strict_types=1);

use Tests\Support\CreatesTenantDatabases;
use Tests\Support\RegistersCenters;
use Tests\Support\SeedsBooking;
use Tests\Support\SeedsCatalog;
use Tests\Support\SeedsQueue;
use Tests\Support\SeedsResources;
use Tests\Support\SeedsSales;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case bindings
|--------------------------------------------------------------------------
|
| Unit tests get no application container: they must stay pure. Everything
| else boots Laravel and talks to a real MySQL-compatible database
| (docs/DECISIONS.md ADR-016).
|
*/

uses(TestCase::class, RegistersCenters::class, SeedsCatalog::class, SeedsBooking::class, SeedsResources::class, SeedsQueue::class, SeedsSales::class)->in('Feature');
uses(TestCase::class, CreatesTenantDatabases::class, RegistersCenters::class, SeedsCatalog::class, SeedsBooking::class, SeedsResources::class, SeedsQueue::class, SeedsSales::class)->in('TenantIsolation');

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

/**
 * Reads every PHP file under app/, for the source-scan safeguards in
 * tests/Architecture (docs/11-TESTING-STRATEGY.md §5).
 *
 * @return array<string, string> repo-relative path => contents
 */
function appSourceFiles(): array
{
    $root = dirname(__DIR__);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root.'/app', FilesystemIterator::SKIP_DOTS)
    );

    $files = [];

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getRealPath();

        if ($path === false) {
            continue;
        }

        $relative = str_replace([$root.DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);

        $files[$relative] = (string) file_get_contents($path);
    }

    return $files;
}

/**
 * Reads every file that can contain a translation call: PHP under app/ and
 * routes/, and Blade under resources/views/.
 *
 * Separate from `appSourceFiles()` because the translation safeguards have to
 * see the VIEWS — `__('Queue')` in a nav partial is exactly the call that broke
 * on a case-insensitive filesystem (docs/11-TESTING-STRATEGY.md §5).
 *
 * @return array<string, string> repo-relative path => contents
 */
function translatableSourceFiles(): array
{
    $root = dirname(__DIR__);
    $files = [];

    foreach (['app', 'routes', 'resources/views'] as $directory) {
        $absolute = $root.'/'.$directory;

        if (! is_dir($absolute)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            // Blade files are `*.blade.php`, so one extension covers both.
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getRealPath();

            if ($path === false) {
                continue;
            }

            $relative = str_replace([$root.DIRECTORY_SEPARATOR, '\\'], ['', '/'], $path);

            $files[$relative] = (string) file_get_contents($path);
        }
    }

    return $files;
}

/**
 * Every translation GROUP that exists on disk: `lang/<locale>/<group>.php`.
 *
 * @return array<string, list<string>> lowercased group name => files defining it
 */
function translationGroups(): array
{
    $root = dirname(__DIR__);
    $groups = [];

    foreach (glob($root.'/lang/*/*.php') ?: [] as $file) {
        $name = strtolower(pathinfo($file, PATHINFO_FILENAME));

        $groups[$name][] = 'lang/'.basename(dirname($file)).'/'.basename($file);
    }

    return $groups;
}

/**
 * Finds lines matching $pattern, skipping files under any allow-listed path.
 *
 * @param  list<string>  $allowedPaths
 * @return list<string> "path:line  source" for each violation
 */
function scanAppSource(string $pattern, array $allowedPaths = []): array
{
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        foreach ($allowedPaths as $allowed) {
            if (str_starts_with($path, $allowed)) {
                continue 2;
            }
        }

        foreach (explode("\n", $contents) as $index => $line) {
            // Comments are documentation, not behaviour — a safeguard that
            // fires on the doc block explaining it is a safeguard nobody keeps.
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match($pattern, $line) === 1) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    return $violations;
}
