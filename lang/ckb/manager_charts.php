<?php

declare(strict_types=1);

/*
 * Chart chrome shared by every x-chart component (docs/31-MANAGER-CHARTS.md).
 * The data labels themselves come from the page that draws the chart.
 */
return [
    'previous' => 'ماوەی پێشوو',
    'other' => 'ئەوانی تر',
    'folded' => '{1} یەکێکی تریش لەخۆدەگرێت|[2,*] :count دانەی تریش لەخۆدەگرێت',
    'change' => 'گۆڕان',
    'share' => 'بەش',
    'total' => 'کۆی گشتی',
    'value' => 'بەها',
    'maximum' => 'لە کۆی',
    'target' => 'ئامانج',
    'loading' => 'هێڵکاری بار دەکرێت',

    'units' => [
        'points' => ':value خاڵ',
        'hours' => ':count کاتژمێر',
        'minutes' => ':count خولەک',
        'seconds' => ':count چرکە',
    ],

    'tone' => [
        'good' => 'باشتر',
        'bad' => 'خراپتر',
    ],

    'kpi' => [
        'versus' => 'بەراورد بە :value',
        'versus_period' => ':period: :value',
    ],

    'summary' => [
        'trend' => ':label، لە :from بۆ :to. بەرزترین :high (:high_at)، نزمترین :low (:low_at).',
        'total' => 'کۆی گشتی :total.',
        'previous_total' => 'ماوەی پێشوو :total.',
        'parts' => ':label: :count بەش، کۆی گشتی :total. گەورەترین: :top، :share.',
        'progress' => ':label: :value لە :max.',
        'single' => ':label: :value.',
        'target' => 'ئامانج :value.',
        'previous' => 'پێشوو :value.',
        'stacked' => ':label: :count بەش لە :from بۆ :to، کۆی گشتی :total.',
        'grouped' => ':label: :series لە :count گرووپدا.',
        'ranked' => ':label: :count دانە. بەرزترین: :top، :value.',
        'matrix' => ':label. بەرزترین :high لە :at.',
    ],
];
