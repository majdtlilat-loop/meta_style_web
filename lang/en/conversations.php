<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The conversation surface
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md, docs/25-WHATSAPP.md §§17.
|
| Named after the SURFACE, never after a dotless literal: `__('Conversations')`
| would be parsed as a translation GROUP and, on a case-insensitive filesystem,
| return this whole array. The translation-collision architecture test enforces
| the naming.
|
| The customer-facing lines here are the ONLY things the application says to a
| customer in its own voice: the hand-off acknowledgement, and a guest's
| booking confirmation (rendered field by field from the booking record, §22).
| Everything else a customer reads is either the assistant's words or a member
| of staff's.
|
| It deliberately explains NOTHING. Not that the assistant failed, not that the
| center has run out of a paid allowance, not that a provider is down -- a
| person messaging a salon about their haircut is not a party to any of that,
| and saying so would embarrass the center to their own customer
| (docs/13-ROADMAP.md Phase 13 §§51).
|
*/

return [
    'handoff_acknowledgement' => 'Thanks for your message. One of the team will reply here shortly.',

    // ---- The staff inbox --------------------------------------------
    'title' => 'Conversations',
    'empty' => 'No open conversations.',
    'waiting' => 'Waiting for a person',
    'status_ai_active' => 'Assistant',
    'status_human_requested' => 'Needs a person',
    'status_human_active' => 'With :name',
    'status_closed' => 'Closed',
    'take_over' => 'Take over',
    'return_to_assistant' => 'Hand back to the assistant',
    'close' => 'Close',
    'reply' => 'Reply',
    'reply_placeholder' => 'Write a reply…',
    'unknown_contact' => 'Unknown number',
    'delivery_pending' => 'Sending…',
    'delivery_failed' => 'Not delivered',
    'delivery_unknown' => 'Delivery unconfirmed',

    // ---- Inbox screen (Manager) --------------------------------------
    'waiting_count' => ':count waiting for a person',
    'assistant_on' => 'Assistant on',
    'not_allowed' => 'You may not view conversations.',
    'not_allowed_hint' => 'Ask a manager for access to the inbox.',
    'all_open' => 'All open',
    'filter_ai_active' => 'Assistant',
    'filter_human_requested' => 'Needs a person',
    'filter_human_active' => 'With the team',
    'a_colleague' => 'a colleague',
    'thread' => 'Conversation',
    'pick' => 'Choose a conversation',
    'author_customer' => 'Customer',
    'author_ai' => 'Assistant',
    'author_staff' => 'Team',
    'author_system' => 'System',
    'template' => 'Template: :name',
    'no_messages' => 'No messages yet.',
    'close_confirm' => 'Close this conversation? A new message from the customer opens a new one.',
    'reply_help' => 'Sent as the center. Replying takes the conversation over from the assistant.',
    'reply_locked' => 'Replies need WhatsApp in your plan. You can still read and close conversations.',
    'sent' => 'Reply queued for sending.',
    'taken_over' => 'You are handling this conversation.',
    'returned' => 'Handed back to the assistant.',
    'closed' => 'Conversation closed.',

    // ---- A guest's booking confirmation (docs/25-WHATSAPP.md §22) -----
    // The local rendering of the approved template: what the thread stores
    // and staff read. Every value comes from the booking record.
    'booking_confirmation' => [
        'body' => "Hello :customer, your booking at :center (:branch) is confirmed.\nDate: :date\nTime: :time\nServices: :services\nBooking number: :reference\nContact: :contact",
        'time_range' => ':start–:end',
        'service_variation' => ':service (:variation)',
        'service_addons' => ':service + :addons',
        'service_employee' => ':service with :employee',
        'separator' => ', ',
        'none' => '—',
    ],
];
