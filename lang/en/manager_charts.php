<?php

declare(strict_types=1);

/*
 * Chart chrome shared by every x-chart component (docs/31-MANAGER-CHARTS.md).
 * The data labels themselves come from the page that draws the chart.
 */
return [
    'previous' => 'Previous period',
    'other' => 'Other',
    'folded' => '{1} Includes 1 more|[2,*] Includes :count more',
    'change' => 'Change',
    'share' => 'Share',
    'total' => 'Total',
    'value' => 'Value',
    'maximum' => 'Out of',
    'target' => 'Target',
    'loading' => 'Loading chart',

    'units' => [
        'points' => ':value pts',
        'hours' => ':count h',
        'minutes' => ':count min',
        'seconds' => ':count s',
    ],

    'tone' => [
        'good' => 'better',
        'bad' => 'worse',
    ],

    'kpi' => [
        'versus' => 'vs :value',
        'versus_period' => ':period: :value',
    ],

    'summary' => [
        'trend' => ':label, :from to :to. Highest :high (:high_at), lowest :low (:low_at).',
        'total' => 'Total :total.',
        'previous_total' => 'Previous period :total.',
        'parts' => ':label: :count parts, total :total. Largest: :top, :share.',
        'progress' => ':label: :value of :max.',
        'single' => ':label: :value.',
        'target' => 'Target :value.',
        'previous' => 'Previous :value.',
        'stacked' => ':label: :count parts from :from to :to, total :total.',
        'grouped' => ':label: :series across :count groups.',
        'ranked' => ':label: :count items. Highest: :top, :value.',
        'matrix' => ':label. Highest :high at :at.',
    ],
];
