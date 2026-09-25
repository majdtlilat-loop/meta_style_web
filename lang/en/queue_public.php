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
    'announcement' => 'Ticket :number, please proceed to :destination.',
    'announcement_short' => 'Ticket :number, please come forward.',

    'now_calling' => 'Now calling',
    'recently_called' => 'Recently called',
    'waiting' => 'Waiting',
    'ticket' => 'Ticket',
    'destination' => 'Go to',
    'issued_at' => 'Issued',
    'thank_you' => 'Thank you for waiting.',

    // The state beside a recent call, and the Manager preview's sample.
    'state_called' => 'Called',
    'state_serving' => 'In service',
    'sample_call' => 'Sample',

    // Screen controls: this screen only, never the queue.
    'start' => 'Start screen',
    'start_hint' => 'One touch turns on the sound and full screen.',
    'controls' => 'Screen controls',
    'fullscreen' => 'Full screen',
    'exit_fullscreen' => 'Exit full screen',
];
