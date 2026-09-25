<?php

declare(strict_types=1);

/*
 * Manager → Settings → WhatsApp (docs/25-WHATSAPP.md §21). The group is named
 * after its surface, never after a dotless literal (SafeguardsTest).
 */
return [
    'title' => 'WhatsApp',
    'inactive' => 'WhatsApp booking is unavailable right now',

    'status' => [
        'connected' => 'Connected',
        'not_connected' => 'Not connected',
        'booking_on' => 'Booking on',
        'booking_off' => 'Booking off',
        'on' => 'On',
        'off' => 'Off',
    ],

    'actions' => [
        'connect' => 'Connect WhatsApp',
        'edit' => 'Edit connection',
        'check' => 'Check setup',
        'turn_on' => 'Turn on',
        'turn_off' => 'Turn off',
        'turn_off_title' => 'Turn off WhatsApp booking?',
        'turn_off_confirm' => 'New WhatsApp messages will not be received until you turn it back on.',
        'open_conversations' => 'Open conversations',
    ],

    'link' => [
        'locked' => 'Not in plan',
        'attention' => 'Needs attention',
    ],

    'empty' => [
        'title' => 'WhatsApp is not connected',
        'view_only' => 'Only people who can manage WhatsApp can connect it.',
    ],

    'connection' => [
        'title' => 'Connection',
        'number' => 'WhatsApp number',
        'display_name' => 'Name',
        'provider' => 'Provider',
        'phone_number_id' => 'Phone number ID',
        'business_account_id' => 'Business account ID',
        'booking' => 'WhatsApp booking',
        'credentials' => 'Credentials',
        'configured' => 'Last configured',
        'configured_by' => ':name · :time',
        'last_sent' => 'Last message sent',
        'last_error' => 'Last provider error',
        'turned_on' => 'WhatsApp booking is on.',
        'turned_off' => 'WhatsApp booking is off. New messages are not received.',
    ],

    'credentials' => [
        'configured' => 'Configured',
        'not_configured' => 'Not configured',
        'unreadable' => 'The saved credentials can no longer be read. Enter them again.',
        'fields' => [
            'access_token' => 'Access token',
            'app_secret' => 'App secret',
            'verify_token' => 'Verify token',
        ],
        'tips' => [
            'verify_token' => 'A phrase you choose. Enter the same phrase in the webhook settings of your Meta app.',
        ],
    ],

    'webhook' => [
        'title' => 'Webhook',
        'states' => [
            'verified' => 'Verified',
            'pending' => 'Not verified yet',
            'failing' => 'Messages rejected',
        ],
        'url' => 'Callback URL',
        'url_tip' => 'Paste this URL and your verify token into the webhook settings of your Meta app.',
        'copy' => 'Copy the callback URL',
        'verify_token' => 'Verify token',
        'set' => 'Set',
        'not_set' => 'Not set',
        'handshake' => 'Verified by WhatsApp',
        'last_inbound' => 'Last notification received',
        'last_rejected' => 'Last rejected notification',
    ],

    'check' => [
        'title' => 'Setup check',
        'note' => 'Checks what Meta Style has recorded. It does not contact WhatsApp.',
        'checked_at' => 'Checked at :time',
        'states' => [
            'ready' => 'Ready',
            'waiting' => 'Waiting for WhatsApp',
            'problem' => 'Configuration problem',
            'not_connected' => 'Not connected',
        ],
        'item_states' => [
            'ok' => 'Passed',
            'problem' => 'Problem',
            'pending' => 'Waiting',
            'unknown' => 'No result yet',
        ],
        'result' => [
            'ready' => 'Ready. Everything Meta Style checks is in place.',
            'waiting' => 'Almost ready. WhatsApp has not verified the webhook yet.',
            'not_connected' => 'WhatsApp is not connected yet.',
            'problem' => 'Configuration problem: :item.',
        ],
    ],

    'checks' => [
        'channel' => 'Included in your plan',
        'provider' => 'Messaging provider',
        'phone_number_id' => 'Phone number ID',
        'credentials' => 'Credentials',
        'enabled' => 'WhatsApp booking on',
        'webhook' => 'Webhook',
        'delivery' => 'Last message sent',
        'provider_error' => 'Provider error',
    ],

    'problems' => [
        'channel_inactive' => 'Your plan does not include WhatsApp booking',
        'provider_unavailable' => 'The messaging provider is not available',
        'phone_number_id_missing' => 'The phone number ID is missing',
        'phone_number_id_invalid' => 'The phone number ID is not valid',
        'credentials_missing' => 'The credentials are not saved',
        'credentials_incomplete' => 'The credentials are incomplete',
        'credentials_unreadable' => 'The saved credentials can no longer be read',
        'account_disabled' => 'WhatsApp booking is turned off',
        'webhook_not_verified' => 'Not verified yet',
        'signature_failures' => 'Notifications are being rejected — check the app secret',
        'last_send_failed' => 'The last message was not sent',
        'last_send_unknown' => 'Delivery not confirmed yet',
        'nothing_sent' => 'Nothing sent yet',
        'provider_error' => 'WhatsApp reported an error',
        'unknown' => 'Unknown problem',
    ],

    'delivery' => [
        'sent' => 'Sent',
        'failed' => 'Not sent',
        'unknown' => 'Not confirmed',
        'pending' => 'Sending',
    ],

    'form' => [
        'title_new' => 'Connect WhatsApp',
        'title_edit' => 'Edit connection',
        'display_name' => 'Name',
        'display_phone_number' => 'WhatsApp number',
        'phone_number_id' => 'Phone number ID',
        'business_account_id' => 'Business account ID',
        'credentials' => 'Credentials',
        'secret_note' => 'Stored encrypted and never shown again.',
        'replace' => 'Replace credentials',
        'keep' => 'Keep the saved credentials',
        'retype' => 'For your security, enter the credentials again.',
        'enabled' => 'Turn on WhatsApp booking',
        'save' => 'Save connection',
        'saved' => 'Connection saved.',
        'saved_with_credentials' => 'Connection saved. The credentials were replaced.',
    ],

    'assistant' => [
        'title' => 'RAYAN assistant',
        'enabled' => 'Let RAYAN answer on WhatsApp',
        'off_note' => 'When off, new conversations go to your team.',
        'unavailable' => 'RAYAN is not available yet. Conversations go to your team.',
        'model' => 'Model',
        'default_model' => 'Default (:model)',
        'default_model_plain' => 'Default',
        'tone' => 'Tone',
        'tone_tip' => 'How RAYAN should sound. It cannot change booking rules, prices or permissions.',
        'tone_placeholder' => 'Warm and brief. Address customers politely.',
        'save' => 'Save assistant',
        'saved' => 'Assistant settings saved.',
        'read_only' => 'Only people who can manage the assistant can change this.',
    ],

    'flow' => [
        'title' => 'What the booking bot does',
        'team_title' => 'Your team',
        'assistant_title' => 'RAYAN',
        'locked' => 'Not in plan',
        'team' => [
            'inbox' => 'Every customer message is kept in Conversations',
            'takeover' => 'Your team can take over any conversation',
            'reply' => 'Replies go out from your WhatsApp number',
        ],
        'assistant' => [
            'branches' => 'Lists your branches',
            'services' => 'Explains services, prices, durations and add-ons',
            'slots' => 'Finds available times',
            'book' => 'Books through your booking rules',
            'reschedule' => 'Moves a booking to a new time',
            'cancel' => 'Cancels a booking',
            'own_bookings' => 'Looks up the customer’s own bookings',
            'language' => 'Replies in the customer’s saved language, or your primary language',
            'handoff' => 'Hands the conversation to your team when it cannot finish',
        ],
    ],

    'languages' => [
        'title' => 'Languages',
        'primary' => 'Primary',
        'change' => 'Change languages',
    ],

    // Guest booking confirmations (docs/25-WHATSAPP.md §22).
    'confirmations' => [
        'title' => 'Booking confirmations',
        'guest_toggle' => 'Send booking confirmation to guest customers via WhatsApp',
        'guest_tip' => 'Guests are customers without an app account. Registered customers keep their in-app notification.',
        'states' => [
            'active' => 'Sending',
            'blocked' => 'Not sending',
            'off' => 'Off',
        ],
        'blockers' => [
            'channel_inactive' => 'WhatsApp is not in your plan.',
            'not_connected' => 'WhatsApp is not connected.',
            'account_off' => 'WhatsApp is turned off.',
            'provider_unavailable' => 'The messaging provider is unavailable.',
            'no_template' => 'No approved confirmation template is set up yet.',
            'template_language' => 'None of your languages can be sent on WhatsApp yet.',
        ],
        'not_sending' => 'Not sending: :reason',
        'template' => 'Template',
        'template_missing' => 'Not set up',
        'language' => 'Language',
        'language_value' => 'Customer’s language, else :primary',
        'language_none' => 'None available',
        'language_unavailable' => 'Not available on WhatsApp',
        'recent' => 'Last :days days',
        'counts' => [
            'sent' => 'Sent',
            'failed' => 'Failed',
            'unconfirmed' => 'Unconfirmed',
            'skipped' => 'Not sent',
        ],
        'last_issue' => 'Last issue',
        'issue_states' => [
            'failed' => 'Not delivered',
            'unknown' => 'Delivery unconfirmed',
            'pending' => 'Sending',
            'skipped' => 'Not sent',
        ],
        'reasons' => [
            'disabled' => 'Switched off',
            'opted_out' => 'Customer opted out of booking messages',
            'no_phone' => 'No valid mobile number',
            'no_reference' => 'No booking number',
            'not_connected' => 'WhatsApp not connected',
            'account_off' => 'WhatsApp turned off',
            'provider_unavailable' => 'Provider unavailable',
            'no_template' => 'No approved template',
            'template_language' => 'Language not available on WhatsApp',
            'rate_limited' => 'Sending limit reached',
            'not_configured' => 'WhatsApp not configured',
            'error' => 'Could not be sent',
        ],
        'read_only' => 'Only people who can manage WhatsApp can change this.',
        'saved_on' => 'Guest booking confirmations turned on.',
        'saved_off' => 'Guest booking confirmations turned off.',
    ],

    'errors' => [
        'not_connected' => 'Connect WhatsApp first.',
        'entitlement' => 'Your plan does not include WhatsApp booking.',
        'assistant_entitlement' => 'Your plan does not include the RAYAN assistant.',
        'forbidden' => 'You do not have permission to change this.',
        'phone_number_id' => 'That phone number ID is not valid.',
        'provider' => 'The messaging provider is not available.',
        'not_configured' => 'Save the credentials before turning WhatsApp booking on.',
        'assistant_invalid' => 'That model or tone cannot be used.',
        'generic' => 'That could not be saved. Try again.',
    ],
];
