/*
 * The Manager's screen preview geometry. docs/17-QUEUE.md §21.
 *
 *     node --test tests/Js/queue-preview-fit.test.mjs
 *
 * and from Pest through tests/Unit/Queue/QueueDisplayClientTest.php.
 *
 * The preview frames the real television page at a television's size and
 * scales it down, so the page's own small-window breakpoint (900px) is never
 * what a manager sees on an ordinary laptop.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { previewFit, PREVIEW_SCREENS } from '../../resources/js/manager/queue-preview-fit.js';

/** The display page stacks its panels at or below this width. */
const DISPLAY_BREAKPOINT = 900;

test('lays the page out at a television size, above the display breakpoint, whatever the window', () => {
    // A small laptop's dialog, a large monitor's, and a nearly closed window.
    for (const [width, height] of [[1200, 560], [2400, 1300], [320, 200]]) {
        const fit = previewFit('landscape', width, height);

        assert.equal(fit.width, 1600);
        assert.equal(fit.height, 900);
        assert.ok(fit.width > DISPLAY_BREAKPOINT, 'the framed page must see a large screen');
    }

    assert.deepEqual({ ...PREVIEW_SCREENS.portrait }, { width: 900, height: 1600 });
});

test('scales the television down to the room available, keeping its shape', () => {
    // Width-bound: 1200 / 1600.
    const wide = previewFit('landscape', 1200, 800);
    assert.equal(wide.scale, 0.75);
    assert.equal(wide.boxWidth, 1200);
    assert.equal(wide.boxHeight, 675);

    // Height-bound: 540 / 900.
    const short = previewFit('landscape', 1400, 540);
    assert.equal(short.scale, 0.6);
    assert.equal(short.boxWidth, 960);
    assert.equal(short.boxHeight, 540);

    // Portrait is height-bound on a landscape window: 800 / 1600.
    const tall = previewFit('portrait', 1400, 800);
    assert.equal(tall.width, 900);
    assert.equal(tall.height, 1600);
    assert.equal(tall.scale, 0.5);
    assert.equal(tall.boxWidth, 450);
    assert.equal(tall.boxHeight, 800);
});

test('never enlarges past the logical size, never collapses to nothing', () => {
    assert.equal(previewFit('landscape', 4000, 3000).scale, 1);
    assert.equal(previewFit('landscape', 0, 0).scale, 0.1);
    assert.equal(previewFit('landscape', -50, Number.NaN).scale, 0.1);
    // An unknown shape is a landscape screen.
    assert.equal(previewFit('square', 800, 450).width, 1600);
});
