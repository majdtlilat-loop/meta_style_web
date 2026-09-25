<?php

declare(strict_types=1);

/*
 * Chart chrome shared by every x-chart component (docs/31-MANAGER-CHARTS.md).
 * The data labels themselves come from the page that draws the chart.
 */
return [
    'previous' => 'الفترة السابقة',
    'other' => 'أخرى',
    'folded' => '{1} يشمل عنصراً آخر|[2,*] يشمل :count عناصر أخرى',
    'change' => 'التغيّر',
    'share' => 'الحصة',
    'total' => 'الإجمالي',
    'value' => 'القيمة',
    'maximum' => 'من أصل',
    'target' => 'الهدف',
    'loading' => 'جارٍ تحميل المخطط',

    'units' => [
        'points' => ':value نقطة مئوية',
        'hours' => ':count ساعة',
        'minutes' => ':count دقيقة',
        'seconds' => ':count ثانية',
    ],

    'tone' => [
        'good' => 'أفضل',
        'bad' => 'أسوأ',
    ],

    'kpi' => [
        'versus' => 'مقابل :value',
        'versus_period' => ':period: :value',
    ],

    'summary' => [
        'trend' => ':label، من :from إلى :to. الأعلى :high (:high_at)، والأدنى :low (:low_at).',
        'total' => 'الإجمالي :total.',
        'previous_total' => 'الفترة السابقة :total.',
        'parts' => ':label: :count أجزاء، الإجمالي :total. الأكبر: :top، :share.',
        'progress' => ':label: :value من :max.',
        'single' => ':label: :value.',
        'target' => 'الهدف :value.',
        'previous' => 'السابق :value.',
        'stacked' => ':label: :count أجزاء من :from إلى :to، الإجمالي :total.',
        'grouped' => ':label: :series عبر :count مجموعات.',
        'ranked' => ':label: :count عناصر. الأعلى: :top، :value.',
        'matrix' => ':label. الأعلى :high عند :at.',
    ],
];
