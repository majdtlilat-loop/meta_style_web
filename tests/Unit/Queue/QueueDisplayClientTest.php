<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| The waiting-room screen's client contract
|--------------------------------------------------------------------------
|
| docs/17-QUEUE.md §§9, 13, 16, 21.
|
| The television's client is plain JavaScript inlined into the display page
| (resources/js/queue-display/display-client.js). Its core — language rotation,
| the new-call ledger, the view model, the media carousel, the wiring between
| them and the promotional media element — is tested with Node's built-in
| runner (tests/Js), no package needed, as is the Manager preview's geometry
| (resources/js/manager/queue-preview-fit.js). This runs those files as part of
| the PHP suite, so the rules they pin travel with every gate: a new call is
| acted on once, by its key, never by the language; a sound-only screen chimes;
| a language switch re-labels and never re-announces, advances or resets the
| media; a pinned preview never rotates; promotional video is muted; one timer
| per loop.
|
*/

it('keeps the display client contract', function (): void {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('Node.js is not installed here; run `node --test tests/Js/queue-display-client.test.mjs tests/Js/queue-preview-fit.test.mjs`.');
    }

    $root = dirname(__DIR__, 3);
    $process = new Process([$node, '--test', '--test-reporter=tap', 'tests/Js/queue-display-client.test.mjs', 'tests/Js/queue-preview-fit.test.mjs'], $root);
    $process->setTimeout(120);
    $process->run();

    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(0, $output.$process->getErrorOutput())
        ->and($output)->toContain('# fail 0')
        // Muted is proven at runtime, on the element the page really builds
        // (`createMediaElement`), not by reading the source for a phrase.
        ->and($output)->toMatch('/^ok \d+ - builds every promotional video muted/m')
        ->and($output)->toMatch('/^ok \d+ - a screen with sound on and voice off chimes once per new call key/m')
        ->and($output)->toMatch('/^ok \d+ - a preview pinned to one language shows only that language and never rotates/m');
});

it('inlines the client without any way to close its script tag early', function (): void {
    $source = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/queue-display/display-client.js');

    expect(stripos($source, '</script'))->toBeFalse();
});
