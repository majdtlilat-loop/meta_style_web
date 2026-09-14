<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Targeted safeguards
|--------------------------------------------------------------------------
|
| docs/11-TESTING-STRATEGY.md §5.
|
| Two mistakes this architecture is specifically designed to prevent cannot be
| expressed as a type-level rule, because both are string literals:
|
|   1. Comparing a plan name instead of asking for an entitlement.
|   2. Treating a client-supplied tenant id as an authorization boundary.
|
| Both are scanned here rather than by a shell grep, so they run with the
| suite, report file and line, and can carry a reviewed allow-list. They are
| the exception, not the pattern — prefer a real architecture test whenever
| one can express the rule.
|
*/

it('never branches on a plan name in business code', function (): void {
    // Plans get renamed, split, merged and grandfathered. Code must ask what a
    // tenant CAN DO, not what they bought (docs/05-ENTITLEMENTS.md §1).
    $plans = 'free|trial|starter|basic|standard|pro|professional|business|premium|enterprise';

    $violations = scanAppSource(
        '/(?:'
        // $x->plan === 'pro'   /   $plan == "business"
        .'\$\w+(?:->|\[)[\'"]?\w*plan\w*[\'"]?\]?\s*(?:===?|!==?)\s*[\'"](?:'.$plans.')[\'"]'
        .'|'
        // 'pro' === $tenant->plan
        .'[\'"](?:'.$plans.')[\'"]\s*(?:===?|!==?)\s*\$\w+(?:->|\[)[\'"]?\w*plan\w*'
        .'|'
        // in_array($tenant->plan, [...])
        .'in_array\s*\(\s*\$\w+(?:->|\[)[\'"]?\w*plan\w*'
        .')/i',
        allowedPaths: [
            // The only modules allowed to know plan names are the ones that
            // sell them and resolve them into entitlements.
            'app/Modules/SaaS/',
            'app/Kernel/Entitlements/',
        ],
    );

    expect($violations)->toBe([], implode("\n", array_merge(
        ['Plan-name branching found. Use Entitlements::allows()/limit()/quota() instead:'],
        $violations,
    )));
});

it('never reads an internal tenant identifier from client input', function (): void {
    // A tenant is resolved from the host or a token binding. The one exception
    // is the opaque, revocable PUBLIC key presented at login, which names the
    // center to authenticate against and authorises nothing on its own
    // (docs/02-TENANCY.md §2.2, source 3).
    //
    // What is banned here is the INTERNAL identity — the row id and the
    // sequence. Neither is revocable, and the sequence leaks how many centers
    // exist and the order they signed up.
    $violations = scanAppSource(
        '/(?:'
        .'\$request->(?:input|get|query|post|json)\s*\(\s*[\'"](?:tenant_id|tenant_uuid|tenant|center_id|tenant_sequence)[\'"]'
        .'|'
        .'request\s*\(\s*[\'"](?:tenant_id|tenant_uuid|tenant|center_id|tenant_sequence)[\'"]'
        .'|'
        .'\$request->(?:tenant_id|center_id|tenant_sequence)\b'
        .'|'
        .'->header\s*\(\s*[\'"]X-Tenant'
        .')/i',
        allowedPaths: [
            // The resolver is where tenant identification legitimately lives,
            // and even there the host and token binding are the sources.
            'app/Kernel/Tenancy/',
        ],
    );

    expect($violations)->toBe([], implode("\n", array_merge(
        ['Client-supplied internal tenant identifier found. Resolve the tenant from the host, '
            .'the token binding, or the opaque public key:'],
        $violations,
    )));
});

it('never exposes the internal tenant sequence outside the tenancy layer', function (): void {
    // Returning the sequence in an API payload would leak signup volume and
    // hand clients an identifier they could start sending back. The public key
    // is what leaves the building.
    $violations = scanAppSource(
        '/[\'"](?:sequence|tenant_sequence)[\'"]\s*=>/i',
        allowedPaths: [
            'app/Kernel/Tenancy/',
            'app/Modules/Onboarding/',
            // Phase 8 introduced a SECOND, unrelated sequence: 1, 2, 3 per
            // queue ticket, the order of one customer's own history. It is
            // per-ticket, starts at 1, and leaks nothing about the platform —
            // but it is spelled the same way, so a word-level scan cannot tell
            // the two apart. The test below is what keeps this from being a
            // hole: it proves the queue never reads the tenancy one.
            'app/Modules/Queue/',
        ],
    );

    expect($violations)->toBe([], implode("\n", array_merge(
        ['Internal tenant sequence referenced outside the tenancy layer:'],
        $violations,
    )));
});

it('keeps the queue sequence away from the tenancy one', function (): void {
    // The other half of the allow-list above. Inside `app/Modules/Queue/`,
    // `sequence` may only ever mean the ticket's own history counter — so the
    // module must not be able to reach the record that holds the OTHER one.
    // `TenantModel` is where the tenancy sequence lives; `UsesTenantConnection`
    // is the sanctioned door and stays open, because using the center's own
    // database is not the same as knowing which center it is.
    $violations = [];

    foreach (appSourceFiles() as $path => $contents) {
        if (! str_starts_with($path, 'app/Modules/Queue/')) {
            continue;
        }

        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            if (preg_match('/tenant_sequence|TenantModel/i', $line) === 1) {
                $violations[] = $path.':'.($index + 1).'  '.trim($line);
            }
        }
    }

    expect($violations)->toBe([], implode("\n", array_merge(
        ['The queue module reached into the control-plane tenant record:'],
        $violations,
    )));
});

it('never names a translation group after a dotless translation key', function (): void {
    /*
     * A REAL defect, found on Windows and invisible on Linux CI.
     *
     * Laravel's `parseKey()` reads a key with no dot as a GROUP, not a line:
     * `__('Queue')` asks for the whole of `lang/<locale>/Queue.php`. On a
     * case-sensitive filesystem that file does not exist, the key comes back
     * unchanged, and the nav link reads "Queue" — correct by accident. On
     * Windows and macOS the same lookup finds `lang/en/queue.php`, returns the
     * entire ARRAY, and the page fatals inside `htmlspecialchars()`.
     *
     * So the bug depends on the developer's filesystem, which is the worst
     * possible property: green in CI, broken on half the team's machines. Phase
     * 8 hit it and renamed the group to `queue_public.php`; this is what stops
     * the next phase from naming a file `pos.php` next to a `__('POS')`.
     *
     * The rule is narrow ON PURPOSE. Dotless translation literals are fine and
     * common — `__('Save')`, `__('Queue')` — and translation groups are fine.
     * Only the COLLISION is banned, compared case-insensitively because that is
     * the comparison the filesystem makes.
     */
    $groups = translationGroups();

    if ($groups === []) {
        return;
    }

    $violations = [];

    foreach (translatableSourceFiles() as $path => $contents) {
        foreach (explode("\n", $contents) as $index => $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            // __('X') · trans('X') · @lang('X') · trans_choice('X', …)
            if (preg_match_all('/(?:__|@lang|trans|trans_choice)\(\s*([\'"])(.*?)\1/', $line, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $key = $match[2];

                // A dotted key names a line inside a group, which is the normal
                // case and cannot collide. `::` is a package namespace.
                if ($key === '' || str_contains($key, '.') || str_contains($key, '::')) {
                    continue;
                }

                $collides = strtolower($key);

                if (! isset($groups[$collides])) {
                    continue;
                }

                $violations[] = sprintf(
                    '%s:%d  __(%s) collides with the translation group %s',
                    $path,
                    $index + 1,
                    var_export($key, true),
                    implode(', ', $groups[$collides]),
                );
            }
        }
    }

    expect($violations)->toBe([], implode("\n", array_merge(
        [
            'A dotless translation literal names an existing translation group. On a '
                .'case-insensitive filesystem Laravel returns the whole array instead of the '
                .'string, and the page fatals. Rename the GROUP after its surface '
                .'(`queue.php` -> `queue_public.php`), not the literal:',
        ],
        $violations,
    )));
});

it('proves the translation collision rule can actually fail', function (): void {
    // A safeguard whose scanner silently sees nothing reports green forever.
    // This asserts both halves are live: real groups on disk, and real
    // translation calls in the source it scans.
    $groups = translationGroups();

    expect($groups)->not->toBeEmpty()
        ->and($groups)->toHaveKey('queue_public');

    $calls = 0;

    foreach (translatableSourceFiles() as $contents) {
        $calls += preg_match_all('/(?:__|@lang|trans)\(\s*[\'"]/', $contents);
    }

    expect($calls)->toBeGreaterThan(0);

    // And the comparison itself is case-insensitive, which is the whole point:
    // `queue.php` would have collided with `__('Queue')`.
    expect(strtolower('Queue'))->toBe('queue')
        ->and(isset($groups['queue']))->toBeFalse();
});

/**
 * Line numbers of every negated `toContain` / `toContainEqual` called with more
 * than one top-level argument.
 *
 * Tokens, not a regex: `->not->toContain(json_encode($a, FLAGS))` has a comma
 * and ONE argument.
 *
 * @return list<int>
 */
function negatedMultiNeedleContains(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $lines = [];
    $count = count($tokens);

    for ($i = 0; $i + 4 < $count; $i++) {
        $isNot = is_array($tokens[$i]) && $tokens[$i][0] === T_STRING && $tokens[$i][1] === 'not';
        $arrow = is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_OBJECT_OPERATOR;
        $method = is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING && in_array($tokens[$i + 2][1], ['toContain', 'toContainEqual'], true);

        if (! $isNot || ! $arrow || ! $method || $tokens[$i + 3] !== '(') {
            continue;
        }

        $depth = 0;

        for ($j = $i + 3; $j < $count; $j++) {
            $token = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if (in_array($token, ['(', '[', '{'], true) || (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                $depth--;

                if ($depth === 0) {
                    break;
                }
            } elseif ($token === ',' && $depth === 1) {
                $lines[] = $tokens[$i][2];

                break;
            }
        }
    }

    return $lines;
}

it('never negates a multi-needle toContain, which passes when any one needle is missing', function (): void {
    /*
     * `expect($x)->not->toContain('a', 'b')` runs `toContain('a', 'b')` and
     * passes if it FAILS — which it does as soon as either needle is absent. So
     * it asserts almost nothing, and `not->toContain($secret, 'message')` asserts
     * nothing at all: the message is a second needle that is never present.
     * Several tests passed vacuously that way until Phase 9 (ADR-057). One
     * needle per negation, or `expect(in_array(...))->toBeFalse($message)`.
     */
    $root = dirname(__DIR__);
    $violations = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        foreach (negatedMultiNeedleContains((string) file_get_contents($file->getPathname())) as $line) {
            $violations[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1)).':'.$line;
        }
    }

    expect($violations)->toBe([]);
});

it('proves the multi-needle negation rule can actually fail', function (): void {
    $bad = <<<'PHP'
        <?php
        expect($x)->not->toContain('a', 'b');
        expect($y)->and($z)->not->toContain($secret, "message {$table}");
        PHP;

    $fine = <<<'PHP'
        <?php
        expect($x)->not->toContain('a')->not->toContain('b');
        expect(json_encode($a, JSON_THROW_ON_ERROR))->not->toContain('token');
        expect($x)->not->toContain(implode(',', [$a, $b]));
        expect($x)->toContain('a', 'b');
        PHP;

    expect(negatedMultiNeedleContains($bad))->toBe([2, 3])
        ->and(negatedMultiNeedleContains($fine))->toBe([]);
});

it('scans a source tree that actually contains files', function (): void {
    // A safeguard that silently scans nothing is worse than no safeguard: it
    // reports green forever. This proves the scanner sees real code.
    $files = appSourceFiles();

    expect($files)->not->toBeEmpty()
        ->and(array_keys($files))->toContain('app/Kernel/Tenancy/TenantConnectionGuard.php');

    expect(scanAppSource('/class\s+TenantConnectionGuard/'))->not->toBeEmpty();
});
