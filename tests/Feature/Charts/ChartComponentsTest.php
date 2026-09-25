<?php

declare(strict_types=1);

use App\Kernel\Localization\LanguageRegistry;
use Illuminate\Support\Facades\Blade;

/*
 * Every chart component renders in English, Arabic and Kurdish (the two
 * right-to-left locales included), carries an accessible name and a data
 * table with EVERY value, and falls back to the empty state when there is
 * nothing to draw (docs/31-MANAGER-CHARTS.md).
 */

/** The <details> data table of a rendered chart. */
function chartTable(string $html): string
{
    $start = strpos($html, '<details class="chart__data">');
    expect($start)->not->toBeFalse('the chart has no data table');

    return substr($html, (int) $start, (int) strpos($html, '</details>', (int) $start) - (int) $start);
}

/** @param list<string> $values */
function expectTableHas(string $html, array $values): void
{
    $table = chartTable($html);

    foreach ($values as $value) {
        expect($table)->toContain(e($value));
    }
}

dataset('chart locales', ['en', 'ar', 'ckb']);

it('renders a line chart with its previous period and every value in the table', function (string $locale): void {
    app()->setLocale($locale);

    $html = Blade::render('<x-chart.line label="Bookings" :buckets="$buckets" :series="$series" :previous="$previous" area />', [
        'buckets' => [['label' => '1 Sep'], ['label' => '2 Sep'], ['label' => '3 Sep'], ['label' => '4 Sep']],
        'series' => ['label' => 'Bookings', 'values' => [3, 17, null, 8]],
        'previous' => ['values' => [2, 5, 11, 0], 'labels' => ['1 Aug', '2 Aug', '3 Aug', '4 Aug']],
    ]);

    expect($html)->toContain('class="chart chart--kit chart--line"')
        ->toContain('role="img"')
        ->toContain('chart__line chart__line--previous')
        ->toContain('class="chart__area"')
        ->toContain('data-chart-target tabindex="0"')
        ->toContain(e(__('manager_charts.previous')))
        ->toContain(e(__('ui.chart.show_data')));
    expectTableHas($html, ['1 Sep', '2 Sep', '3 Sep', '4 Sep', '3', '17', '—', '8', '2', '5', '11', '0', '1 Aug', '+50%', '+240%', '28', '18']);
    expect(app(LanguageRegistry::class)->direction($locale))->toBe($locale === 'en' ? 'ltr' : 'rtl');
})->with('chart locales');

it('renders a donut with Other folded, the total in the centre and every member in the table', function (string $locale): void {
    app()->setLocale($locale);
    $items = array_map(static fn (int $i): array => ['label' => "Method {$i}", 'value' => $i * 1000], range(1, 9));

    $html = Blade::render('<x-chart.donut label="Payments" :items="$items" currency="IQD" />', ['items' => $items]);

    expect($html)->toContain('class="chart chart--kit chart--donut"')
        ->toContain('data-series="other"')
        ->toContain('data-series="7"')
        ->not->toContain('data-series="8"')
        ->toContain('role="img"')
        ->toContain(e(__('manager_charts.other')));

    $symbol = $locale === 'en' ? 'IQD' : 'د.ع';
    expectTableHas($html, [
        ...array_map(static fn (int $i): string => "Method {$i}", range(1, 9)),
        ...array_map(static fn (int $i): string => number_format($i * 1000).' '.$symbol, range(1, 9)),
        '45,000 '.$symbol,
        '20%',
    ]);
})->with('chart locales');

it('wears the reserved status palette in status mode, one colour per status', function (): void {
    app()->setLocale('en');

    $html = Blade::render('<x-chart.donut label="Bookings by status" mode="status" :items="$items" />', ['items' => [
        ['label' => 'Cancelled', 'status' => 'cancelled', 'value' => 3],
        ['label' => 'Completed', 'status' => 'completed', 'value' => 10],
        ['label' => 'No-show', 'status' => 'no_show', 'value' => 1],
        ['label' => 'Voided', 'status' => 'void', 'value' => 1],
    ]]);

    expect($html)->toContain('data-series="good"')
        ->toContain('data-series="warning"')
        ->toContain('data-series="critical"')
        ->toContain('data-series="critical-2"')
        ->not->toContain('data-series="1"');
    // Status order, not value order: good → warning → critical.
    expect(strpos($html, 'Completed'))->toBeLessThan((int) strpos($html, 'No-show'))
        ->and(strpos($html, 'No-show'))->toBeLessThan((int) strpos($html, 'Cancelled'));
});

it('renders a radial meter with target and previous', function (string $locale): void {
    app()->setLocale($locale);

    $html = Blade::render('<x-chart.radial label="Completion rate" :value="72.5" :target="80" :previous="65" />');

    expect($html)->toContain('class="chart chart--kit chart--radial"')
        ->toContain('stroke-dasharray="72.5 100"')
        ->toContain('class="radial__target"')
        ->toContain('class="radial__previous"')
        ->toContain(e(__('manager_charts.target')));
    expectTableHas($html, ['72.5%', '80%', '65%']);
})->with('chart locales');

it('renders stacked columns in status mode with every part and total in the table', function (string $locale): void {
    app()->setLocale($locale);

    $html = Blade::render('<x-chart.stacked label="Bookings by status" mode="status" :buckets="$buckets" :series="$series" />', [
        'buckets' => ['Mon', 'Tue', 'Wed'],
        'series' => [
            ['label' => 'Cancelled', 'status' => 'cancelled', 'values' => [1, 0, 2]],
            ['label' => 'Completed', 'status' => 'completed', 'values' => [7, 9, 13]],
        ],
    ]);

    expect($html)->toContain('class="chart chart--kit chart--stacked"')
        ->toContain('class="chart__seg" data-series="good"')
        ->toContain('class="chart__seg" data-series="critical"')
        ->toContain('flex-grow: 13');
    expectTableHas($html, ['Mon', 'Tue', 'Wed', 'Completed', 'Cancelled', '7', '9', '13', '1', '0', '2', '8', '15', '29', '3', '32']);
})->with('chart locales');

it('folds a ninth stacked series into Other and still lists it', function (): void {
    app()->setLocale('en');
    $series = array_map(static fn (int $i): array => ['label' => "Category {$i}", 'values' => [$i, $i + 100]], range(1, 9));

    $html = Blade::render('<x-chart.stacked label="Sales by category" :buckets="$buckets" :series="$series" />', ['buckets' => ['W1', 'W2'], 'series' => $series]);

    expect($html)->toContain('data-series="8"')
        ->toContain('data-series="other"');
    expectTableHas($html, ['Category 9', '9', '109']);
});

it('renders grouped columns for side-by-side entities', function (string $locale): void {
    app()->setLocale($locale);

    $html = Blade::render('<x-chart.grouped label="Branches" :groups="$groups" :series="$series" currency="USD" />', [
        'groups' => ['Week 1', 'Week 2'],
        'series' => [['label' => 'Downtown', 'values' => [125000, 98000]], ['label' => 'Mall', 'values' => [0, 43050]]],
    ]);

    expect($html)->toContain('class="chart chart--kit chart--grouped"')
        ->toContain('class="chart__bar" data-series="1"')
        ->toContain('class="chart__bar" data-series="2"')
        ->toContain('data-empty');
    expectTableHas($html, ['Week 1', 'Week 2', 'Downtown', 'Mall', '1,250.00 $', '980.00 $', '0.00 $', '430.50 $']);
})->with('chart locales');

it('renders ranked bars with previous ticks, Other and every folded member', function (string $locale): void {
    app()->setLocale($locale);

    $html = Blade::render('<x-chart.ranked label="Services" :items="$items" :limit="3" share />', ['items' => [
        ['label' => 'Haircut', 'value' => 40, 'previous' => 32, 'href' => '/manager/catalog'],
        ['label' => 'Colour', 'value' => 25, 'previous' => 30],
        ['label' => 'Nails', 'value' => 15, 'previous' => 15],
        ['label' => 'Spa', 'value' => 12, 'previous' => 4],
        ['label' => 'Wax', 'value' => 8, 'previous' => 0],
    ]]);

    expect($html)->toContain('class="chart chart--kit chart--ranked"')
        ->toContain('class="ranked__prev"')
        ->toContain('data-series="other"')
        ->toContain('href="/manager/catalog"');
    expectTableHas($html, ['Haircut', 'Colour', 'Nails', 'Spa', 'Wax', '40', '25', '15', '12', '8', '32', '30', '4', '20', '100', '40%']);
})->with('chart locales');

it('renders a heatmap on the sequential ramp with every cell in the table', function (string $locale): void {
    app()->setLocale($locale);

    $html = Blade::render('<x-chart.heatmap label="Busy hours" :rows="$rows" :columns="$columns" :values="$values" row-header="Day" column-header="Hour" />', [
        'rows' => ['Sat', 'Sun'],
        'columns' => ['09:00', '10:00', '11:00'],
        'values' => [[0, 4, 11], [2, null, 7]],
    ]);

    expect($html)->toContain('class="chart chart--kit chart--heatmap"')
        ->toContain('data-cols="3"')
        ->toContain('class="heat__cell" data-step="5"')
        ->toContain('class="heat__cell" data-step="0"');
    expectTableHas($html, ['Sat', 'Sun', '09:00', '10:00', '11:00', '0', '4', '11', '2', '7']);
})->with('chart locales');

it('shows the empty state when every value is zero or missing', function (string $locale): void {
    app()->setLocale($locale);
    $empty = e(__('ui.chart.no_data'));

    $renders = [
        Blade::render('<x-chart.line label="A" :buckets="[\'a\', \'b\']" :series="[\'values\' => [0, null]]" :previous="[\'values\' => [0, 0]]" />'),
        Blade::render('<x-chart.donut label="B" :items="[[\'label\' => \'x\', \'value\' => 0]]" />'),
        Blade::render('<x-chart.radial label="C" :value="null" />'),
        Blade::render('<x-chart.stacked label="D" :buckets="[\'a\']" :series="[[\'label\' => \'x\', \'values\' => [0]]]" />'),
        Blade::render('<x-chart.grouped label="E" :groups="[\'a\']" :series="[[\'label\' => \'x\', \'values\' => [0]], [\'label\' => \'y\', \'values\' => [null]]]" />'),
        Blade::render('<x-chart.ranked label="F" :items="[]" />'),
        Blade::render('<x-chart.heatmap label="G" :rows="[\'r\']" :columns="[\'c\']" :values="[[0]]" />'),
        Blade::render('<x-chart.line label="H" :buckets="[]" :series="[]" />'),
    ];

    foreach ($renders as $html) {
        expect($html)->toContain('class="chart__empty"')
            ->toContain($empty)
            ->not->toContain('<figure');
    }
})->with('chart locales');

it('keeps a zero percent radial as data, not as the empty state', function (): void {
    app()->setLocale('en');

    expect(Blade::render('<x-chart.radial label="Repeat rate" :value="0" />'))
        ->toContain('chart--radial')
        ->not->toContain('stroke-dasharray');
});

it('renders a KPI with a semantic delta and screen-reader words for the tone', function (string $locale): void {
    app()->setLocale($locale);

    $html = Blade::render('<x-chart.kpi label="Cancellation rate" :current="8" :previous="12" format="percent" :higher-is-better="false" icon="calendar" />');

    expect($html)->toContain('data-tone="good"')
        ->toContain('8%')
        ->toContain(e(__('manager_charts.tone.good')))
        ->toContain(e(__('manager_charts.kpi.versus', ['value' => '12%'])));
})->with('chart locales');

it('renders a chart-shaped skeleton that announces loading', function (string $type): void {
    app()->setLocale('en');

    expect(Blade::render('<x-chart.skeleton type="'.$type.'" />'))
        ->toContain('class="chart-skeleton chart-skeleton--'.$type.'"')
        ->toContain('role="status"')
        ->toContain(__('manager_charts.loading'));
})->with(['columns', 'line', 'bars', 'donut', 'heatmap', 'kpi']);

it('keeps the existing columns and bars API working, with an optional format', function (): void {
    app()->setLocale('en');

    $money = Blade::render('<x-chart.columns label="Sales" :buckets="[[\'label\' => \'Mon\']]" :series="[[\'label\' => \'Sales\', \'values\' => [25000]]]" currency="IQD" />');
    $percent = Blade::render('<x-chart.columns label="Rate" :buckets="[[\'label\' => \'Mon\']]" :series="[[\'label\' => \'Rate\', \'values\' => [87.5]]]" format="percent" />');
    $bars = Blade::render('<x-chart.bars label="Top" :items="[[\'label\' => \'Cut\', \'value\' => 1250]]" currency="USD" />');

    $sparkline = Blade::render('<x-chart.sparkline :values="[3, 5, 4, 8]" />');

    expect($money)->toContain('25,000 IQD')
        ->and($percent)->toContain('87.5%')
        ->and($bars)->toContain('12.50 $')
        ->and($sparkline)->toContain('class="sparkline"')
        ->toContain('class="sparkline__dot" d="M100 4h0"')
        ->and(Blade::render('<x-chart.sparkline :values="[3]" />'))->not->toContain('<svg');
});

it('draws every chart colour from tokens, never a hex in a component', function (): void {
    foreach (glob(dirname(__DIR__, 3).'/resources/views/components/chart/*.blade.php') ?: [] as $file) {
        expect(preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) file_get_contents($file)))->toBe(0, basename($file).' carries a hex colour');
    }
});

it('defines every palette token for light and dark and mirrors the time axis in RTL', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 3).'/resources/css/platform/charts.css');
    [$light, $rest] = explode('[data-theme="dark"] {', $css, 2);
    $dark = substr($rest, 0, (int) strpos($rest, '}'));

    $tokens = ['--series-1', '--series-2', '--series-3', '--series-4', '--series-5', '--series-6', '--series-7', '--series-8', '--series-other', '--series-previous',
        '--status-good', '--status-info', '--status-neutral', '--status-warning', '--status-critical', '--seq-0', '--seq-1', '--seq-2', '--seq-3', '--seq-4', '--seq-5'];

    foreach ($tokens as $token) {
        expect($light)->toContain($token.':')
            ->and($dark)->toContain($token.':');
    }

    // The two original, validated slots stay as they were.
    expect($light)->toContain('--series-1: #c05c6e;')
        ->toContain('--series-2: #3f6fa8;')
        ->and($dark)->toContain('--series-1: #d06a80;')
        ->toContain('--series-2: #5f8fd0;')
        ->and($css)->toContain('[dir="rtl"] svg.chart__marks { transform: scaleX(-1); }')
        ->toContain('[dir="rtl"] .donut__layer svg { transform: scaleX(-1); }')
        ->toContain('@media (prefers-reduced-motion: reduce)');
});

it('keeps the chart translation group in exact EN / AR / KU parity', function (): void {
    $flatten = static function (array $lines, string $prefix = '') use (&$flatten): array {
        $keys = [];
        foreach ($lines as $key => $line) {
            $keys = [...$keys, ...(is_array($line) ? $flatten($line, $prefix.$key.'.') : [$prefix.$key])];
            if (! is_array($line)) {
                expect(trim((string) $line))->not->toBe('');
            }
        }

        return $keys;
    };
    $root = dirname(__DIR__, 3).'/lang/';
    $en = $flatten(require $root.'en/manager_charts.php');

    foreach (['ar', 'ckb'] as $locale) {
        expect($flatten(require $root.$locale.'/manager_charts.php'))->toEqualCanonicalizing($en);
    }
});
