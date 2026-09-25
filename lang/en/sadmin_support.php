<?php

declare(strict_types=1);

return [
    'title' => 'Support',
    'results' => '{0} No tickets|{1} 1 ticket|[2,*] :count tickets',
    'new' => 'New ticket',

    'filters' => [
        'label' => 'Filter tickets',
        'status' => 'Status',
        'active' => 'Needs work',
        'all' => 'All',
        'priority' => 'Priority',
        'all_priorities' => 'Any priority',
        'center' => 'Center',
        'all_centers' => 'All centers',
        'search' => 'Search',
        'search_placeholder' => 'Subject or reference',
        'clear' => 'Clear filters',
    ],

    'table' => [
        'ticket' => 'Ticket',
        'center' => 'Center',
        'status' => 'Status',
        'priority' => 'Priority',
        'activity' => 'Last activity',
        'opened_by' => 'Opened by :name',
    ],

    'empty' => [
        'title' => 'No tickets match these filters',
        'none_title' => 'No tickets need work',
        'description' => 'Change or clear the filters.',
    ],

    'create' => [
        'title' => 'Open a ticket for a center',
        'center' => 'Center',
        'choose_center' => 'Choose a center',
        'subject' => 'Subject',
        'priority' => 'Priority',
        'message' => 'Message',
        'submit' => 'Create ticket',
    ],

    'show' => [
        'back' => 'Support',
        'conversation' => 'Conversation',
        'no_messages' => 'No messages yet.',
        'reply' => 'Reply',
        'reply_placeholder' => 'Write a reply to the center…',
        'note_placeholder' => 'Write a note only the platform team sees…',
        'internal' => 'Internal note',
        'internal_help' => 'Hidden from the center.',
        'attachment' => 'Attach a file',
        'attachment_help' => 'PDF, PNG, JPG or TXT, up to 5 MB.',
        'send' => 'Send reply',
        'save_note' => 'Save note',
        'details' => 'Details',
        'reference' => 'Reference',
        'center' => 'Center',
        'opened' => 'Opened',
        'opened_by' => 'Opened by',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'manage' => 'Status and assignment',
        'status' => 'Status',
        'priority' => 'Priority',
        'assignee' => 'Assigned to',
        'unassigned' => 'Unassigned',
        'reason' => 'Reason for the change',
        'update' => 'Update ticket',
        'download' => 'Download :name',
    ],

    'author_types' => [
        'platform' => 'Platform',
        'center' => 'Center',
        'system' => 'System',
        'staff' => 'Center staff',
    ],

    'created' => 'Ticket created.',
    'reply_added' => 'Reply sent.',
    'note_added' => 'Note saved.',
    'state_updated' => 'Ticket updated.',
];
