/*
 * The screen preview's geometry. docs/17-QUEUE.md §21.
 *
 * The preview frames the REAL television page. A television is large, so the
 * frame is laid out at a fixed logical screen — 1600×900 landscape, 900×1600
 * portrait — and scaled down with a CSS transform to fit the dialog. The
 * display's own media queries (it stacks its panels below 900px) therefore
 * always see a large screen, whatever the size of the Manager window.
 *
 * Pure, no DOM: pinned by tests/Js/queue-preview-fit.test.mjs.
 */

export const PREVIEW_SCREENS = Object.freeze({
    landscape: Object.freeze({ width: 1600, height: 900 }),
    portrait: Object.freeze({ width: 900, height: 1600 }),
});

/** Never drawn smaller than this, however small the window. */
const MIN_SCALE = 0.1;

/**
 * The logical screen for `shape`, and the scale that fits it into the room
 * available — never enlarged past its own size.
 *
 * @returns {{ width: number, height: number, scale: number, boxWidth: number, boxHeight: number }}
 */
export function previewFit(shape, availableWidth, availableHeight) {
    const screen = PREVIEW_SCREENS[shape] || PREVIEW_SCREENS.landscape;
    const width = Number(availableWidth) > 0 ? Number(availableWidth) : 0;
    const height = Number(availableHeight) > 0 ? Number(availableHeight) : 0;
    const scale = Math.max(MIN_SCALE, Math.min(1, width / screen.width, height / screen.height));

    return {
        width: screen.width,
        height: screen.height,
        scale,
        boxWidth: Math.floor(screen.width * scale),
        boxHeight: Math.floor(screen.height * scale),
    };
}
