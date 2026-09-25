<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The usage dashboard
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md, docs/26-USAGE-QUOTAS.md §14.
|
| Named after the SURFACE. A dotless `__('Usage')` would be parsed as a
| translation GROUP and, on a case-insensitive filesystem, return this whole
| array -- the collision an architecture test refuses.
|
| Nothing here states a price. A center is shown what THEY used against what
| THEY bought; Meta Style's own costs and margins are not theirs to read.
|
*/

return [
    'title' => 'بەکارهۊنان',
    'not_allowed' => 'ناتوانی بەکارهۊنان ببینیت.',
    'none' => 'هێشتا هیچ بەکارهێنراوە لەم ماوەیەدا.',
    'group_ai' => 'یاریدەدەر',
    'group_advanced_reports' => 'شیکردنەوەی ڕاپۆرتی پێشکەوتوو',
    'group_whatsapp' => 'واتساپ',
    'resource' => 'سەرچاوە',
    'used' => 'بەکارهاتوو',
    'allowance' => 'بەش',
    'remaining' => 'ماوە',
    'status' => 'دۆخ',
    'resets' => 'نوێکردنەوە',
    'unlimited' => 'بێسنوور',
    'metered_only' => 'پێوراوە، سنووردار نییە',
    'status_normal' => 'ئاسایی',
    'status_warning' => 'نزیک دەبێتەوە',
    'status_high' => 'نزیکە تەواو بێت',
    'status_exhausted' => 'تەواو بوو',
    'resource_ai_runs' => 'وەڵامەکانی یاریدەدەر',
    'resource_advanced_report_ai_runs' => 'شیکردنەوەکانی ڕایان بۆ ڕاپۆرت',
    'resource_ai_input_tokens' => 'وشەی خوێندراوە',
    'resource_ai_output_tokens' => 'وشەی نووسراو',
    'resource_ai_tool_calls' => 'گەڕانەکان',
    'resource_ai_failed_runs' => 'وەڵامی سەرنەکەوتوو',
    'resource_wa_inbound' => 'نامەی هاتوو',
    'resource_wa_outbound' => 'نامەی نێردراو',
    'resource_wa_template' => 'قالبی نێردراو',
    'resource_wa_failed' => 'نامەی نەگەیشتوو',
];
