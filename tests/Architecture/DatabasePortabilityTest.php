<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
|--------------------------------------------------------------------------
| Database portability
|--------------------------------------------------------------------------
|
| docs/DECISIONS.md ADR-033 · docs/03-DATABASE-MIGRATIONS.md §7.2.
|
| Development runs on MariaDB and CI runs on MySQL 8. That split is deliberate
| and it has one hazard: a migration that MariaDB accepts and MySQL 8 rejects
| (or the reverse) is green on a developer's machine and red only after push —
| or, far worse, green in CI and broken on a production engine nobody tested.
|
| These tests read the migration sources. They cannot prove a migration runs on
| MySQL 8 — only CI can do that — but they catch the specific constructs that
| differ, before the push.
|
*/

/**
 * @return array<string, string> repo-relative path => contents
 */
function migrationSources(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['control', 'tenant'] as $plane) {
        foreach (glob($root.'/database/migrations/'.$plane.'/*.php') ?: [] as $path) {
            $files['database/migrations/'.$plane.'/'.basename($path)] = (string) file_get_contents($path);
        }
    }

    return $files;
}

it('keeps migrations free of engine-specific functional and generated indexes', function (): void {
    // THE PHASE 4 RULE (ADR-033). MySQL 8 can index a JSON path through a
    // generated column; MariaDB 10.4 cannot do it the same way. Reaching for
    // one because CI happens to run MySQL 8 would quietly make MariaDB
    // unsupportable — and the electronic menu does not have the query volume
    // to need it. Start portable. Add a DB-specific index only when a real
    // query exists, EXPLAIN shows the problem, and the index measurably fixes
    // it; then record the evidence in DECISIONS.md and allow it here.
    $forbidden = [
        'storedAs' => 'stored generated column',
        'virtualAs' => 'virtual generated column',
        'GENERATED ALWAYS' => 'generated column',
        'JSON_VALUE' => 'JSON functional index',
        'JSON_EXTRACT' => 'JSON functional index',
        'JSON_UNQUOTE' => 'JSON functional index',
        '->fullText(' => 'full-text index',
    ];

    $violations = [];

    foreach (migrationSources() as $path => $contents) {
        foreach ($forbidden as $needle => $what) {
            foreach (explode("\n", $contents) as $index => $line) {
                $trimmed = ltrim($line);

                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
                    continue;
                }

                if (str_contains($line, $needle)) {
                    $violations[] = sprintf('%s:%d  %s (%s)', $path, $index + 1, trim($line), $what);
                }
            }
        }
    }

    expect($violations)->toBe([]);
});

it('never gives a JSON, TEXT or BLOB column a default', function (): void {
    // MariaDB accepts this; MySQL 8 rejects it outright. A migration written
    // locally would pass here and fail in CI — or on a production MySQL 8.
    $violations = [];

    foreach (migrationSources() as $path => $contents) {
        foreach (explode("\n", $contents) as $index => $line) {
            $isBigType = preg_match('/->(json|jsonb|text|mediumText|longText|binary)\(/', $line) === 1;

            if ($isBigType && str_contains($line, '->default(')) {
                $violations[] = sprintf('%s:%d  %s', $path, $index + 1, trim($line));
            }
        }
    }

    expect($violations)->toBe([]);
});

it('pins a collation both engines have', function (): void {
    // Read from the config file rather than the container: architecture tests
    // run without an application, and the file is the source of truth anyway.
    /** @var array{connections: array<string, array<string, mixed>>} $database */
    $database = require dirname(__DIR__, 2).'/config/database.php';

    foreach (['control', 'tenant_template', 'reporting_template'] as $connection) {
        $settings = $database['connections'][$connection];

        // MySQL 8 defaults to utf8mb4_0900_ai_ci, which does not exist in
        // MariaDB at all. Naming utf8mb4_unicode_ci explicitly is what keeps
        // the same schema sortable and comparable on both.
        expect($settings['collation'])->toBe('utf8mb4_unicode_ci')
            ->and($settings['charset'])->toBe('utf8mb4')
            // Strict mode on both, or MySQL 8 rejects what MariaDB truncated.
            ->and($settings['strict'])->toBeTrue();
    }

    // Reporting is the sole additional template and is bound lazily per tenant.
    expect(array_keys($database['connections']))->toBe(['control', 'tenant_template', 'reporting_template']);
});

it('keeps composite index widths inside the InnoDB key limit', function (): void {
    // 3072 bytes with DYNAMIC row format, on both engines. utf8mb4 costs four
    // bytes per character, so two unbounded string(255) columns in one index
    // is 2040 — fine — and three is not.
    $violations = [];

    foreach (migrationSources() as $path => $contents) {
        // string('x') with no explicit length is 255 chars = 1020 bytes.
        preg_match_all("/->(?:index|unique)\(\[(.*?)\]/", $contents, $matches);

        foreach ($matches[1] as $columns) {
            $count = substr_count($columns, ',') + 1;

            if ($count > 3) {
                $violations[] = sprintf('%s  index over %d columns: [%s]', $path, $count, $columns);
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps every generated index and foreign key name within the 64-character identifier limit', function (): void {
    // Laravel names an index `{table}_{columns}_{type}` unless it is given a
    // name. Past 64 characters both engines refuse it — AFTER `create table`
    // has already run, so the table exists, the migration is not recorded, and
    // every retry fails on "table already exists" instead of the real cause.
    $violations = [];
    $checked = 0;

    foreach (migrationSources() as $path => $contents) {
        preg_match_all("/Schema::(?:create|table)\(\s*'(\w+)'\s*,\s*function\b(.*?)\n        \}\);/s", $contents, $blocks, PREG_SET_ORDER);

        foreach ($blocks as [, $table, $body]) {
            $names = [];

            // ->index(['a', 'b']) / ->unique([...]) with no explicit name.
            preg_match_all("/->(index|unique)\(\[([^\]]*)\]\s*\)/", $body, $composite, PREG_SET_ORDER);

            foreach ($composite as [, $type, $columns]) {
                preg_match_all("/'(\w+)'/", $columns, $cols);
                $names[] = $table.'_'.implode('_', $cols[1]).'_'.$type;
            }

            // $table->foo('col')...->unique() / ->index() / ->constrained().
            preg_match_all("/\\\$table->\w+\('(\w+)'[^;]*?->(unique|index)\(\)/", $body, $inline, PREG_SET_ORDER);

            foreach ($inline as [, $column, $type]) {
                $names[] = $table.'_'.$column.'_'.$type;
            }

            preg_match_all("/->foreignId\('(\w+)'\)[^;]*->constrained\(/", $body, $foreign);

            foreach ($foreign[1] as $column) {
                $names[] = $table.'_'.$column.'_foreign';
            }

            foreach ($names as $name) {
                $checked++;

                if (strlen($name) > 64) {
                    $violations[] = sprintf('%s  %s (%d characters) — give it an explicit name', $path, $name, strlen($name));
                }
            }
        }
    }

    // The scan must find the names it checks, or it checks nothing.
    expect($checked)->toBeGreaterThan(100)
        ->and($violations)->toBe([]);
});

/*
|--------------------------------------------------------------------------
| CI is the MySQL 8 authority
|--------------------------------------------------------------------------
|
| Nothing above proves a migration runs on MySQL 8. Only CI does, so CI must
| actually be pointed at MySQL 8 and must actually run the whole gate.
|
*/

it('runs CI against MySQL 8 with the same credentials the app expects', function (): void {
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

    $job = $workflow['jobs']['check'];
    $service = $job['services']['mysql'];

    expect($service['image'])->toStartWith('mysql:8')
        ->and($service['ports'])->toContain('3306:3306')
        // The suite drops and recreates the control database and creates one
        // database per tenant, so the CI user needs to be able to.
        ->and($service['env']['MYSQL_ALLOW_EMPTY_PASSWORD'])->toBe('yes');

    // The database the test suite is configured to use must be the one the
    // service creates, or every run starts by failing to connect.
    expect($service['env']['MYSQL_DATABASE'])->toBe(env('DB_CONTROL_DATABASE'));
});

it('gates CI on composer check, the same command developers run', function (): void {
    /** @var array<string, mixed> $workflow */
    $workflow = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/ci.yml');

    $runs = array_values(array_filter(array_map(
        fn (array $step): ?string => $step['run'] ?? null,
        $workflow['jobs']['check']['steps'],
    )));

    $gate = array_values(array_filter($runs, fn (string $run): bool => str_contains($run, 'composer check')));

    // One gate with one definition. Splitting CI into its own sequence of
    // tools is how CI and local slowly stop agreeing.
    expect($gate)->toHaveCount(1);

    $step = array_values(array_filter(
        $workflow['jobs']['check']['steps'],
        fn (array $s): bool => isset($s['run']) && str_contains($s['run'], 'composer check'),
    ))[0];

    expect($step['env']['DB_HOST'])->toBe('127.0.0.1')
        ->and($step['env']['DB_PORT'])->toBe(3306);
});

it('defines composer check as lint, static analysis and the full suite', function (): void {
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    // If this ever drops a step, CI silently stops checking it.
    expect($composer['scripts']['check'])->toBe(['@lint', '@stan', '@test'])
        ->and($composer['scripts']['lint'][0])->toContain('pint --test')
        ->and($composer['scripts']['stan'][0])->toContain('phpstan')
        ->and(implode(' ', $composer['scripts']['test']))->toContain('pest');
});
