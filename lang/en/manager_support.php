<?php

declare(strict_types=1);

return [
    'title' => 'Meta Style support',
    'results' => '{0} No tickets|{1} 1 ticket|[2,*] :count tickets',
    'actions' => [
        'new' => 'New request',
        'reopen' => 'Reopen',
        'close' => 'Close ticket',
        'send' => 'Send reply',
    ],
    'filters' => [
        'label' => 'Ticket status',
        'active' => 'Active',
        'waiting_center' => 'Awaiting you',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'all' => 'All',
    ],
    'status' => [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'waiting_center' => 'Awaiting your reply',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],
    'table' => [
        'ticket' => 'Ticket',
        'priority' => 'Priority',
        'status' => 'Status',
        'activity' => 'Last activity',
        'opened_by' => 'Opened by :name',
    ],
    'empty' => [
        'title' => 'No tickets here',
        'active_title' => 'No open requests',
    ],
    'create' => [
        'title' => 'New support request',
        'placeholder' => 'Describe what you need…',
        'submit' => 'Send request',
    ],
    'fields' => [
        'subject' => 'Subject',
        'message' => 'Message',
        'priority' => 'Priority',
        'reply' => 'Reply',
        'attach' => 'Attach files',
        'attach_help' => 'PDF, PNG, JPG or text · up to 3 files, 5 MB each',
        'uploading' => 'Uploading…',
        'remove_file' => 'Remove :name',
        'attachments' => 'attachments',
        'attachment' => 'attachment',
    ],
    'flash' => [
        'created' => 'Request :reference sent. Meta Style replies here.',
        'replied' => 'Reply sent.',
        'closed' => 'Ticket closed.',
        'reopened' => 'Ticket reopened.',
    ],
    'confirm' => [
        'close_title' => 'Close this ticket?',
        'close_body' => 'Close it once your question is answered. You can reopen it later.',
    ],
    'errors' => [
        'forbidden' => 'You do not have permission to manage support requests.',
        'invalid' => 'A subject, a message and a valid priority are required.',
        'closed' => 'This ticket is closed. Reopen it to reply.',
        'not_reopenable' => 'Only a resolved or closed ticket can be reopened.',
        'too_many_files' => 'Attach at most :count files.',
        'file_rejected' => 'That file cannot be attached. Use PDF, PNG, JPG or text, up to 5 MB.',
    ],
    'ticket' => [
        'breadcrumb' => 'Breadcrumb',
        'conversation' => 'Conversation',
        'no_messages' => 'No messages yet.',
        'download' => 'Download :name',
        'reply_placeholder' => 'Write your reply…',
        'reply_reopens' => 'Replying reopens this resolved ticket.',
        'closed_note' => 'This ticket is closed. Reopen it to continue the conversation.',
        'details' => 'Details',
        'reference' => 'Reference',
        'opened_by' => 'Opened by',
        'opened' => 'Opened',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
        'separate_note' => 'Only your team and Meta Style see this ticket — never your customers.',
    ],
    'author' => [
        'center' => 'Your team',
        'platform' => 'Meta Style',
    ],
    'size' => [
        'kb' => ':size KB',
        'mb' => ':size MB',
    ],
];
