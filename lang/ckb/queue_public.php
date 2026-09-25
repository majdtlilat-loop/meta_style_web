<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Queue — the strings a CUSTOMER sees
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md, docs/17-QUEUE.md §16.
|
| The television and the printed ticket. Staff-facing labels stay inline as
| `__('Waiting')` and friends, like the rest of the center screens.
|
| ## Why the file is not called `queue.php`
|
| A single-word group file collides with a bare `__('Word')` key. Laravel parses
| a dotless key as a GROUP, so `__('Queue')` — which the navigation uses — looks
| for `lang/en/Queue.php`; on a case-insensitive filesystem that FINDS
| `queue.php` and returns the whole array, which then reaches `htmlspecialchars`
| and fatals. It would have worked in CI on Linux and broken on every
| developer's machine.
|
| Two words, so nothing bare can ever match it.
|
*/

return [
    'announcement' => 'ژمارە :number، تکایە بڕۆ بۆ :destination.',
    'announcement_short' => 'ژمارە :number، تکایە وەرە پێشەوە.',

    'now_calling' => 'بانگکردنی ئێستا',
    'recently_called' => 'بانگکراوەکانی پێشوو',
    'waiting' => 'چاوەڕوان',
    'ticket' => 'ژمارە',
    'destination' => 'بڕۆ بۆ',
    'issued_at' => 'کاتی دەرچوون',
    'thank_you' => 'سوپاس بۆ چاوەڕوانیتان.',

    // The state beside a recent call, and the Manager preview's sample.
    'state_called' => 'بانگکرا',
    'state_serving' => 'لە خزمەتدایە',
    'sample_call' => 'نموونە',

    // Screen controls: this screen only, never the queue.
    'start' => 'دەستپێکردنی شاشە',
    'start_hint' => 'یەک دەستلێدان دەنگ و شاشەی پڕ دەکاتەوە.',
    'controls' => 'ئامرازەکانی شاشە',
    'fullscreen' => 'شاشەی پڕ',
    'exit_fullscreen' => 'دەرچوون لە شاشەی پڕ',
];
