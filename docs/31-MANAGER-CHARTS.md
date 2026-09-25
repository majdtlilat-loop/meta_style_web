# 30 — Manager charts (component library)

Server-rendered charts for the Manager (Reports, Advanced Reports, overview) and
Super Admin. Blade components under `resources/views/components/chart/`, view
models and formatting in `app/View/Charts/`, styles and palette tokens in
`resources/css/platform/charts.css`, chrome strings in
`lang/{en,ar,ckb}/manager_charts.php`. No chart library, no script file: SVG /
HTML marks, the shared tooltip (`[data-tip]`, `resources/js/platform/theme.js`)
and a few lines of inline Alpine for arrow-key navigation.

## Rules every chart follows

- **Real data only.** Pass what the report returns. When every value is zero or
  missing the component prints the empty state (`ui.chart.no_data`, or `empty`).
- **Accessible.** A `<figure>` named by a caption; the marks are one
  `role="img"` with a generated summary; every bucket / slice / row / cell is a
  keyboard target (one tab stop per chart, arrows move, Home/End jump; mirrored
  in RTL) that shows the same tooltip as hover; a `<details>` data table lists
  **every value** (folded "Other" members included).
- **RTL.** Time and category axes run right-to-left in `ar` / `ckb`: flex/grid
  layers mirror by themselves and the SVG layers are flipped by CSS
  (`[dir="rtl"] svg.chart__marks`, donut, radial). Text is never inside a
  mirrored SVG. Labels are thinned (every Nth, fewer on a narrow card), never
  clipped.
- **Colour.** Tokens only (a test rejects a hex in `components/chart/*`). Text
  always wears text tokens, never a series colour. One y-axis only — never a
  dual axis; compare different units in separate charts.
- **Motion.** Nothing animates except the skeleton shimmer, which stops under
  `prefers-reduced-motion`.
- **Loading.** First load: `<x-chart.skeleton>` (a lazy placeholder or
  `wire:loading` before any figures exist). Refetch: keep the previous render
  and dim it — `wire:loading.class="is-refreshing"` — never swap to a skeleton.

## Value formats (`format` prop, `App\View\Charts\ValueFormat`)

| format | value you pass | prints |
|---|---|---|
| `number` (default) | any number | `1,284` · `12.5` |
| `compact` | any number | `12.9K` · `4.2M` |
| `money` (default when `currency` is set) | **integer minor units** + `currency` | `25,000 IQD` / `25,000 د.ع`, `12.50 $` |
| `percent` | percentage **points** (87.5 = 87.5 %) | `87.5%`; deltas in points: `−4 pts` |
| `duration` | minutes | `1 h 35 min` (translated units) |
| `seconds` | seconds | `3 min 20 s` |

Money goes through `Kernel\Money` (IQD 0 decimals, USD 2, JOD/KWD 3); an
unknown catalog code falls back to `PlatformCurrencies`. `format="money"` with
no `currency` uses the center's own currency (`Currency::default()`). Axis ticks
are compact major units without a symbol — put the currency in the card head
(`<span class="badge">IQD</span>`). Digits are Latin in every locale, as
everywhere else in the product.

## Semantic deltas (`App\View\Charts\Change`)

```php
$change = Change::between($current, $previous, higherIsBetter: false); // true | false | null
$change->tone;      // good | bad | neutral — the METRIC's judgement, never "green because up"
$change->direction; // up | down | flat | none (no previous)
$change->percent;   // signed %, NULL when previous is 0 or null (no invented percentage)
$change->label($format); // "+12.5%", "−4 pts" (percent metric), "+3" / "+25,000 IQD" from a zero base
$change->toDelta();      // App\View\Delta for the existing <x-ui.kpi :delta>
```

Declare direction per metric: bookings, sales, completion rate, repeat rate →
`true`; cancellation rate, no-shows, waits, refunds → `false`; mixes and pure
counts with no judgement → `null`.

## Components

Common props: `label` (required — the accessible name; also the table
heading), `format`, `currency`, `empty` (empty-state text), `height` (plot
height, CSS length, default `12rem`). Buckets / groups / rows / columns accept
strings or `['label' => string]`.

### `<x-chart.line>` — one series over time + previous period

| prop | type | notes |
|---|---|---|
| `buckets` | `list<string\|['label']>` | the time axis |
| `series` | `['label' => string, 'values' => list<int\|float\|null>]` | `null` breaks the line |
| `previous` | `?['label' => ?string, 'values' => list, 'labels' => ?list<string>]` | aligned **by position** (day 1 vs day 1); dashed + muted; `labels` name the previous buckets in the tooltip |
| `area` | bool | 10 % wash under the line |
| `totals` | ?bool | table total row; default on for number/money/compact |

```blade
<x-chart.line :label="__('manager_reports.bookings')" :buckets="$trend['buckets']"
    :series="['label' => __('manager_reports.bookings'), 'values' => $trend['values']]"
    :previous="['values' => $trend['previous'], 'labels' => $trend['previous_labels']]" area />
```

Crosshair tooltip per bucket: current, previous (with its date) and the change.
Negative values get a zero line (`Scale::range`). Use
`PeriodAlign::byPosition($values, $count)` / `byOffset($keys, $byKey, '1 year')`
/ `labels($previousBuckets, $count)` to shape `previous`.

### `<x-chart.donut>` — part-to-whole at a glance

| prop | type | notes |
|---|---|---|
| `items` | `list<['label', 'value', 'status'?, 'tone'?, 'color'?]>` | non-negative |
| `mode` | `categorical` (default) \| `status` | status: `status` code (or `tone`) → reserved status palette, ordered good → info → neutral → warning → critical |
| `keep` | int, default 7 | named slices; the rest fold into "Other" (members stay in the table) |
| `sort` | ?bool | categorical sorts largest first (false keeps your order); status keeps status order unless `true` |
| `total` / `center-label` | ?string | override the centre (default: compacted total, "Total") |
| `size` | CSS length, default `10rem` | |

Legend rows show value + share and are the keyboard targets; hovering a slice
or a row dims the others. 2px surface gaps between slices. Use for ≤ 7 parts;
for close values prefer `ranked`.

### `<x-chart.radial>` — one ratio (completion rate, repeat rate, quota)

Props: `value` (null → empty state; 0 is a real 0 %), `max` (default 100),
`format` (default `percent`), `target`, `previous` (same unit), `tone`
(`accent` | `good` | `warning` | `critical`), `display` (centre override),
`caption` (small line under the value), `size` (default `8rem`). The track is a
lighter step of the fill; target = tick, previous = hollow dot, both listed
below the ring and in the table.

### `<x-chart.stacked>` — parts per bucket over time

| prop | type | notes |
|---|---|---|
| `buckets` | `list<string\|['label']>` | |
| `series` | `list<['label', 'values' => list, 'status'?, 'tone'?, 'color'?]>` | non-negative |
| `mode` | `categorical` \| `status` | "bookings by status per day" → `status` |

Max 8 series; a 9th and beyond fold into "Other" (listed in a second table).
Legend always shown; one tooltip per bucket with every part and the total.

### `<x-chart.grouped>` — 2–4 entities side by side

Props: `groups` (periods **or** metric names — one shared unit), `series`
(`list<['label', 'values', 'color'?]>`). Past four entities, the first three stay
and the rest fold into "Other" — pass them in the order that matters.

### `<x-chart.multiline>` — 2–4 series over time (trend overlay)

Props: `buckets` (the time axis), `series` (`list<['label', 'values' =>
list<int|float|null>, 'color'?]>`, one shared unit), `totals` (table total row;
default on for number/money/compact). Same geometry as `line` (bucket centres,
`null` breaks a line, negative values get a zero line); one categorical slot
per series in order (`color` 1–8 keeps an entity's colour stable), a line-key
legend, and one crosshair tooltip per bucket listing EVERY series. Past four
series, three stay and the rest are summed into "Other" (muted) — pass them in
the order that matters. Use it for branch vs branch / employee vs employee
trends; for one series against its own previous period use `line`. Added for
Advanced Reports comparisons (backward compatible: a new component, no
existing prop changed).

```blade
<x-chart.multiline :label="__('manager_advanced.compare.trend')" :buckets="$buckets"
    :series="[['label' => 'Downtown', 'values' => [3, 5, 2]], ['label' => 'Mall', 'values' => [1, 4, 6]]]" />
```

### `<x-chart.ranked>` — ranked horizontal bars (supersedes `bars` for new work)

| prop | type | notes |
|---|---|---|
| `items` | `list<['label', 'value', 'previous'?, 'href'?, 'meta'?]>` | `href` makes the label a `wire:navigate` link |
| `limit` | int, default 7 | named rows; the rest fold into "Other" |
| `share` | bool | show % of total under the value (otherwise the change vs previous) |
| `sort` | bool, default true | largest first |
| `higher-is-better` | ?bool | tone of the change in the tooltip |
| `previous-label` | ?string | legend/table name of the previous tick |

One hue for every bar (Other muted); value at the bar's end; previous = a tick.

### `<x-chart.heatmap>` — rows × columns on the sequential ramp

Props: `rows`, `columns`, `values` (`list<list<int|float|null>>`, one list per
row), `row-header`, `column-header` (table headings). Five linear steps from 0
to the max; 0 / null is "none" (never the lightest step). Arrow keys move in 2D.
Render only when the period has data (an all-zero matrix shows the empty state).

```blade
<x-chart.heatmap :label="__('manager_reports.busy_hours')" :rows="$days" :columns="$hours"
    :values="$matrix" :row-header="__('manager_reports.day')" :column-header="__('manager_reports.hour')" />
```

### `<x-chart.kpi>` — a KPI with a semantic delta

Props: `label`, `current`, `previous`, `format`, `currency`,
`higher-is-better` (default `true`), `value` (display override), `comparison`
(e.g. `__('ui.range.vs_previous_period')` → "vs previous period: 1,234";
default "vs 1,234"), `hint`, `icon`, `href`, `trend` (sparkline values),
`help` (optional definition shown as an `.info-tip` beside the label; ignored
on a linked tile, which must not hold a second focus target), `difference`
(bool, default off: also prints the absolute difference, e.g. `+5,000 IQD`,
beside a percentage delta — never from a zero base, where the delta already
is the difference, nor for a percent metric, which moves in points). Same
`.kpi` look as `x-ui.kpi`; the delta pill is `data-tone="good|bad|neutral"` with
an arrow **and** screen-reader words ("better" / "worse"). `x-ui.kpi` is
unchanged; to keep using it, pass `Change::between(...)->toDelta()`.

### `<x-chart.skeleton>` — first-load placeholder

Props: `type` (`columns` | `line` | `bars` | `donut` | `heatmap` | `kpi`),
`height`, `label` (announced; default "Loading chart"). `role="status"`.

### Existing, unchanged API

`<x-chart.columns>` (1–2 series), `<x-chart.bars>` and `<x-chart.sparkline>`
keep their props; `columns` and `bars` gained an optional `format`.

## Palette (tokens in `charts.css`, light / dark)

| role | light (#fff8f5) | dark (#1a181b) |
|---|---|---|
| `--series-1` … `--series-8` | `#c05c6e #3f6fa8 #34871d #8760cb #c5521c #0a9a96 #a67110 #b44e9b` | `#d06a80 #5f8fd0 #449630 #936dd9 #d35e2c #0a9a96 #b37903 #c15aa7` |
| `--series-other` / `--series-previous` | `#b5a8a0` / `#a39690` | `#5c535d` / `#736874` |
| `--status-good/info/neutral/warning/critical` | `#2c965d #3080bc #968983 #e19b1b #b32228` | `#57bc80 #519fdd #7e716c #efa831 #de4e4b` |
| `--seq-0` (none) … `--seq-5` | `#f0e2d9 #e09ea6 #d27e8a #c05f70 #a3495a #803845` | `#2a262c #754a50 #9d5964 #c46a78 #e38291 #f6a3ae` |

Validated with the dataviz validator against the real card surfaces:
categorical — lightness band, chroma floor, adjacent CVD (worst ΔE 9.6 light /
12.2 dark), normal-vision floor (worst ΔE 19.4 / 20.0) and 3:1 contrast all
PASS; slots 1–3 also pass all-pairs. Status — every neighbour on the circle
good → info → neutral → warning → critical (critical ↔ good included) clears
normal-vision ΔE 15 and CVD ΔE 8 in both modes; warning is below 3:1 on the
light surface by design, so a status is always labelled. Sequential — ordinal
checks (monotone, ΔL ≥ 0.06, light end ≥ 2:1, one hue) PASS in both modes.
Slots are assigned in order and never cycled; pass `color` (1–8) on an item to
keep an entity's colour stable across filters. A status never wears a series
colour and a series never wears a status colour. Two statuses of the same tone
in one chart: the second gets a lighter step (`<tone>-2`).

## Known limits

- Donut wrap-around: the last named slice touches slot 1; the 2px gaps and the
  legend carry identity there (validated as a chain, like stacks).
- Stacked / grouped / ranked / donut take non-negative values; use the line (or
  multiline) for signed series (net movement).
- Multiline colours its strokes with `style="stroke: var(--mark)"` on each
  path (the `[data-series]` token), so it needs no stylesheet of its own.
- Tooltips on donut slices anchor above the whole ring (the shared tooltip
  positions on an HTML box); legend rows are the precise targets.
- Keyboard arrows need Alpine (always present on Livewire pages); without it
  the first target and the data table still carry everything.
