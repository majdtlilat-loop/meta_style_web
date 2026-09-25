<?php

declare(strict_types=1);

return [
    'ticket_created' => [
        'title' => 'New support ticket :reference',
        'body' => ':center: :subject',
    ],
    'ticket_replied' => [
        'title' => 'Reply on :reference',
        'body' => ':center replied to the ticket.',
    ],
    'center_registered' => [
        'title' => 'New center: :center',
        'body' => 'Registered on the :plan plan and ready to use.',
    ],
    'center_created' => [
        'title' => 'Center ready: :center',
        'body' => 'Created on the :plan plan. The owner was emailed a link to set a password.',
    ],
    'provisioning_failed' => [
        'title' => 'Center setup failed',
        'body' => ':center could not be set up. Retry it from Centers.',
    ],
    'subscription_plan_changed' => [
        'title' => 'Plan changed',
        'body' => ':center is on a new plan.',
    ],
    'subscription_cycle_changed' => [
        'title' => 'Billing cycle changed',
        'body' => ':center changed its billing cycle.',
    ],
    'subscription_activated' => [
        'title' => 'Subscription activated',
        'body' => ':center started a paid subscription.',
    ],
    'subscription_trial_extended' => [
        'title' => 'Trial end changed',
        'body' => 'The trial end date for :center changed.',
    ],
    'subscription_renewal_changed' => [
        'title' => 'Renewal date changed',
        'body' => 'The renewal date for :center changed.',
    ],
    'invoice_issued' => [
        'title' => 'Invoice :number issued',
        'body' => ':amount for :center.',
    ],
    'payment_recorded' => [
        'title' => 'Payment recorded on :number',
        'body' => ':amount received from :center.',
    ],
    'mail' => [
        'open' => 'Open in Meta Style',
    ],
];
