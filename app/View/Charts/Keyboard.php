<?php

declare(strict_types=1);

namespace App\View\Charts;

/**
 * One tab stop per chart. The first data target is tabbable; the arrow keys
 * move between targets (mirrored in right-to-left layouts, rows of `data-cols`
 * on a matrix), Home / End jump to the ends. Focus shows the shared tooltip
 * (resources/js/platform/theme.js), so the keyboard reads what hover reads.
 *
 * Inline Alpine: the chart library adds no script file. Without Alpine the
 * first target is still reachable and every value is in the data table.
 */
final class Keyboard
{
    public const ALPINE = <<<'JS'
{ nav(e) {
    const items = [...this.$el.querySelectorAll('[data-chart-target]')];
    const i = items.indexOf(document.activeElement);
    if (i < 0) return;
    const cols = parseInt(this.$el.dataset.cols || '0', 10);
    const rtl = getComputedStyle(this.$el).direction === 'rtl';
    const map = { ArrowRight: rtl ? -1 : 1, ArrowLeft: rtl ? 1 : -1, ArrowDown: cols || 1, ArrowUp: -(cols || 1) };
    let n = i;
    if (e.key === 'Home') n = 0;
    else if (e.key === 'End') n = items.length - 1;
    else if (map[e.key] !== undefined) n = i + map[e.key];
    else return;
    e.preventDefault();
    n = Math.max(0, Math.min(items.length - 1, n));
    items[i].tabIndex = -1;
    items[n].tabIndex = 0;
    items[n].focus();
} }
JS;
}
