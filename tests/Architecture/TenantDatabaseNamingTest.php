<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| One producer of tenant database names, one writer of the stored name
|--------------------------------------------------------------------------
|
| ADR-024, amended by ADR-106. A center's database name is generated once, at
| provisioning, and stored on the control-plane tenant row. Everything after
| that reads the stored name: nothing re-derives it from the center's current
| name or slug, and nothing assigns it again — a rename must never become a
| database rename.
|
| Each rule asserts the EXACT set of places it finds, not merely "no
| violations": a pattern that silently matched nothing would otherwise pass
| for ever.
|
*/

const NAMING_PROVISIONER = 'app/Kernel/Tenancy/TenantProvisioningService.php';

/**
 * @return list<string> the app files, excluding comment lines, whose code matches
 */
function namingFilesMatching(string $pattern): array
{
    $files = [];

    foreach (appSourceFiles() as $path => $contents) {
        foreach (explode("\n", $contents) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match($pattern, $line) === 1) {
                $files[] = $path;
                break;
            }
        }
    }

    sort($files);

    return $files;
}

it('assigns a tenant\'s database name in exactly one place: provisioning', function (): void {
    $writers = namingFilesMatching('/[\'"]tenancy_db_name[\'"]\s*=>|->tenancy_db_name\s*=[^=]/');

    expect($writers)->toBe([NAMING_PROVISIONER]);
});

it('generates tenant database names only through provisioning', function (): void {
    $callers = namingFilesMatching('/TenantDatabaseName::generate\(/');

    expect($callers)->toBe([NAMING_PROVISIONER]);
});

it('never assembles a tenant database name by hand', function (): void {
    // The prefix constant lives in the generator; a name concatenated,
    // interpolated or formatted from "tenant_" or the configured prefix
    // anywhere else is a second, unvalidated naming scheme. So is stancl's
    // own internal setter for the same column.
    $pattern = '/[\'"]tenant_[\'"]\s*\.|"tenant_\{|sprintf\(\s*[\'"]tenant_|TenantDatabaseName::(prefix\(\)|DEFAULT_PREFIX)\s*\.|setInternal\(\s*[\'"]db_name/';

    // The pattern must catch every spelling it exists for, or an empty result
    // below would prove nothing.
    foreach ([
        '$name = \'tenant_\'.$slug;',
        '$name = "tenant_{$slug}_{$sequence}";',
        '$name = sprintf(\'tenant_%s_%06d\', $slug, $sequence);',
        '$name = TenantDatabaseName::prefix().$slug;',
        '$name = TenantDatabaseName::DEFAULT_PREFIX.$slug;',
        '$tenant->setInternal(\'db_name\', $name);',
    ] as $sample) {
        expect(preg_match($pattern, $sample))->toBe(1, $sample);
    }

    $builders = namingFilesMatching($pattern);

    foreach (['database', 'routes'] as $directory) {
        $root = dirname(__DIR__, 2).'/'.$directory;

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && preg_match($pattern, (string) file_get_contents($file->getPathname())) === 1) {
                $builders[] = $directory.'/'.substr($file->getPathname(), strlen($root) + 1);
            }
        }
    }

    expect($builders)->toBe([]);
});
