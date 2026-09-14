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
    'announcement' => 'الرقم :number، يرجى التوجه إلى :destination.',
    'announcement_short' => 'الرقم :number، يرجى التقدم.',

    'now_calling' => 'النداء الحالي',
    'recently_called' => 'نداءات سابقة',
    'waiting' => 'في الانتظار',
    'ticket' => 'الرقم',
    'destination' => 'التوجه إلى',
    'issued_at' => 'وقت الإصدار',
    'thank_you' => 'شكراً لانتظاركم.',
];
