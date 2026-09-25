<?php

declare(strict_types=1);

/*
 * The report pages were rebuilt on the shared Manager design system: their
 * styles live in the area stylesheet, the report body is one partial both
 * pages include, the charts are the shared accessible chart components (each
 * with a data table), and every visible string is translated from
 * lang/{en,ar,ckb}/manager_reports.php. The contract is unchanged — the
 * literals it requires are read from where they now live.
 */

/** @return array{standard: string, standard_css: string, advanced: string, advanced_css: string, css: string} */
function reportSurfaceSources(): array
{
    $root = dirname(__DIR__, 2);
    $views = $root.'/resources/views/livewire/center';

    return [
        // Standard Reports: the page and the partials it draws its analytics with.
        'standard' => (string) file_get_contents($views.'/reports.blade.php').implode('', array_map(
            static fn (string $partial): string => (string) file_get_contents($partial),
            glob($views.'/reports/standard/*.blade.php') ?: [],
        )),
        'standard_css' => (string) file_get_contents($root.'/resources/css/manager/reports.css'),
        // Advanced Reports has its own partials (advanced-reports/*) and stylesheet.
        'advanced' => (string) file_get_contents($views.'/advanced-reports.blade.php').implode('', array_map(
            static fn (string $partial): string => (string) file_get_contents($partial),
            glob($views.'/advanced-reports/*.blade.php') ?: [],
        )),
        'advanced_css' => (string) file_get_contents($root.'/resources/css/manager/advanced-reports.css'),
        'css' => (string) file_get_contents($root.'/resources/css/manager/dashboard.css'),
    ];
}

it('keeps both report surfaces responsive, keyboard visible, printable and chart-accessible', function (): void {
    $sources = reportSurfaceSources();

    expect($sources['css'])->toMatch('/@media\(max-width:\d+rem\)/')
        ->toContain('@media(prefers-reduced-motion:reduce)')
        ->toContain('@media print')
        ->toContain(':focus-visible')
        // Printing hides the real Manager shell, not a selector that does not exist.
        ->toContain('.app-sidebar')
        ->toContain('.app-topbar');

    // Standard Reports: the analytics page draws every chart through the
    // shared library — never a hand-rolled SVG — and every one of those
    // components carries its own <details> data table.
    expect($sources['standard'])->toContain('<table>')
        ->toContain('onclick="window.print()"')
        ->toContain('<x-chart.kpi')
        ->toContain('<x-chart.line')
        ->toContain('<x-chart.stacked')
        ->toContain('<x-chart.donut')
        ->toContain('<x-chart.radial')
        ->toContain('<x-chart.ranked')
        ->toContain('<x-chart.grouped')
        ->toContain('<x-chart.columns')
        ->toContain('<x-chart.heatmap')
        ->toContain('<x-chart.skeleton')
        ->and(str_contains($sources['standard'], '<svg'))->toBeFalse('the Standard page draws charts only through the chart library');

    foreach (['line', 'stacked', 'donut', 'radial', 'ranked', 'grouped', 'columns', 'heatmap'] as $chart) {
        expect((string) file_get_contents(dirname(__DIR__, 2).'/resources/views/components/chart/'.$chart.'.blade.php'))->toContain('<details');
    }

    expect($sources['standard_css'])->toMatch('/@media\(max-width:\d+rem\)/')
        ->toContain('@media(prefers-reduced-motion:reduce)')
        ->toContain('@media print')
        ->toContain(':focus-visible')
        ->toContain('.app-sidebar')
        ->toContain('.app-topbar');

    // Advanced Reports: the same guarantees, on the richer chart library
    // (every component carries its own data table) — never a hand-rolled SVG.
    expect($sources['advanced'])->toContain('<details')
        ->toContain('<table>')
        ->toContain('onclick="window.print()"')
        ->toContain('<x-chart.line')
        ->toContain('<x-chart.multiline')
        ->toContain('<x-chart.stacked')
        ->toContain('<x-chart.grouped')
        ->toContain('<x-chart.donut')
        ->toContain('<x-chart.radial')
        ->toContain('<x-chart.ranked')
        ->toContain('<x-chart.heatmap')
        ->toContain('<x-chart.kpi')
        ->toContain('<x-chart.skeleton')
        ->and(str_contains($sources['advanced'], '<svg'))->toBeFalse('the Advanced page draws charts only through the chart library');

    expect($sources['advanced_css'])->toMatch('/@media\(max-width:\d+rem\)/')
        ->toContain('@media(prefers-reduced-motion:reduce)')
        ->toContain('@media print')
        ->toContain(':focus-visible')
        ->toContain('.app-sidebar')
        ->toContain('.app-topbar');
});

it('keeps contextual RAYAN in a focus-trapped labelled dialog with its allowance disclosed', function (): void {
    // The AI panel is its own component and view (AdvancedReports\AiInsights),
    // translated from lang/{en,ar,ckb}/manager_advanced.php.
    $view = (string) file_get_contents(dirname(__DIR__, 2).'/resources/views/livewire/center/advanced-reports/ai-insights.blade.php');
    $english = require dirname(__DIR__, 2).'/lang/en/manager_advanced.php';

    expect($view)->toContain('role="dialog"')
        ->toContain('aria-modal="true"')
        ->toContain('aria-labelledby="rayan-title"')
        ->toContain('aria-describedby="rayan-context-description"')
        ->toContain('x-trap.noscroll="true"')
        ->toContain('wire:keydown.escape.window="closeRayan"')
        ->toContain("__('manager_advanced.ai.allowance')")
        ->and($english['ai']['allowance'])->toBe('Uses Advanced Report AI allowance');
});
