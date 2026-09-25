<?php

declare(strict_types=1);

return [
    'title' => 'Announcements',
    'compose' => 'New announcement',
    'empty' => 'No announcements have been sent.',
    'find_center' => 'Find a center',
    'no_match' => 'No centers match.',
    'any_status' => 'Active or suspended',
    'any_plan' => 'Any plan',
    'reaches' => '{0} Reaches no center yet|{1} Reaches :count center|[2,*] Reaches :count centers',
    'review' => 'Review and send',
    'confirm_title' => 'Send this announcement?',
    'confirm_body' => '{1} It goes to the staff of :count center who handle Meta Style matters.|[2,*] It goes to the staff of :count centers who handle Meta Style matters.',
    'back_to_edit' => 'Back to edit',
    'send' => 'Send now',
    'sent' => '{1} Sent to :count center.|[2,*] Sent to :count centers.',
    'severity' => [
        'info' => 'Information',
        'important' => 'Important',
    ],
    'audience' => [
        'all' => 'All centers',
        'selected' => 'Selected centers',
        'filtered' => 'By filter',
    ],
    'fields' => [
        'title' => 'Title',
        'body' => 'Message',
        'severity' => 'Type',
        'audience' => 'Send to',
        'centers' => 'Centers',
        'sent' => 'Sent',
        'status' => 'Center status',
        'plan' => 'Plan',
    ],
    'errors' => [
        'english' => 'Write the title and message in English at least.',
        'severity' => 'Choose a type.',
        'no_centers' => 'No center matches this audience.',
        'text' => 'Use plain text within the length limits.',
    ],
];
