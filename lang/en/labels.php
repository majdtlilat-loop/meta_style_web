<?php

declare(strict_types=1);

/*
 * Human labels for center-side states. Raw values never reach a page.
 */
return [
    'appointment_status' => [
        'booked' => 'Booked',
        'confirmed' => 'Confirmed',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        'no_show' => 'No-show',
    ],
    'pos_payment_method' => [
        'cash' => 'Cash',
        'manual_electronic' => 'Card / transfer',
        'gateway' => 'Online',
        'bank_transfer' => 'Bank transfer',
        'card' => 'Card',
    ],
    'ticket_state' => [
        'waiting' => 'Waiting',
        'called' => 'Called',
        'serving' => 'Serving',
        'held' => 'On hold',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ],
    'journey_status' => [
        'active' => 'In progress',
        'completed' => 'Completed',
        'aborted' => 'Aborted',
    ],
    'access_level' => [
        'full' => 'Full access',
        'read_only' => 'Read only',
        'limited' => 'Limited',
        'suspended' => 'Suspended',
    ],

    'payment_status' => [
        'pending' => 'Pending',
        'succeeded' => 'Paid',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    'stage_status' => [
        'waiting' => 'Waiting',
        'in_service' => 'In service',
        'completed' => 'Completed',
        'skipped' => 'Skipped',
    ],
];
