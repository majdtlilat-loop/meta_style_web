<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The review page a customer opens from their link
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md, docs/22-REVIEWS.md §21.
|
| Two words, never `review.php`: a dotless `__('Review')` is parsed as a
| translation GROUP, and on a case-insensitive filesystem it would find a
| single-word file and return the whole array. The translation-collision
| architecture test enforces this.
|
| Nothing here addresses the customer by name — the page is reached by a link,
| and a link can be forwarded.
|
*/

return [
    'title' => 'How did we do?',
    'intro' => 'You visited :branch on :date. Your feedback is read by the team.',
    'overall' => 'Overall',
    'overall_hint' => 'How was the visit as a whole?',
    'services' => 'The services you received',
    'service_rating' => 'This service',
    'employee_rating' => 'Performed by :name',
    'optional' => 'Optional',
    'comment' => 'Anything you would like to add?',
    'comment_placeholder' => 'Your comment (optional)',
    'submit' => 'Send my feedback',
    'stars' => ':n out of 5',
    'clear' => 'No rating',
    'done_title' => 'Thank you',
    'done_body' => 'Your feedback has been sent to the team. There is nothing else to do.',
    'done_spent' => 'This link has already been used. Thank you for your feedback.',
];
