<?php

declare(strict_types=1);

return [
    'lock' => [
        'available_from' => 'Available from :plan',
        'contact' => 'Contact us',
        'not_available' => 'Not enabled',
    ],
    'ui' => [
        'eyebrow' => 'Not in your plan',
        'your_plan' => 'Your plan',
        'included_in' => 'Included in',
        'recommended' => 'Recommended',
        'current' => 'Current plan',
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'per_month' => ':price / month',
        'per_year' => ':price / year',
        'save' => 'Save :percent% yearly',
        'what_you_get' => 'What you get',
        'also_needs' => 'Also includes',
        'allowances' => 'Allowances',
        'view_plans' => 'View plans',
        'contact' => 'Contact Meta Style',
        'close' => 'Close',
        'upgrade_body' => 'Upgrade to unlock :feature for your center.',
        'contact_body' => 'No public plan includes :feature yet. Meta Style can enable it for your center.',
        'unavailable_body' => 'Your plan includes :feature, but it is not enabled for your center. Contact Meta Style.',
        'history_notice' => 'Your plan no longer includes :feature. Existing records stay readable; new changes are paused.',
    ],
    'booking' => [
        'summary' => 'Take bookings from your team and from your public booking page, checked against employees, rooms and opening hours.',
        'capabilities' => [
            'Calendar and list of every booking',
            'Public booking page for your customers',
            'Availability by employee, room and branch hours',
            'Reschedule, cancel and no-show tracking',
        ],
    ],
    'queue_management' => [
        'summary' => 'Run a fair queue for walk-ins and appointments with numbered tickets.',
        'capabilities' => [
            'Numbered tickets per branch and day',
            'Call, recall, hold and skip',
            'Route customers to the right destination',
            'Full history of every ticket',
        ],
    ],
    'queue_display' => [
        'summary' => 'Show who is being called on a screen in your waiting area.',
        'capabilities' => [
            'Live display of called tickets',
            'Numbers and destinations only — never names or phones',
        ],
    ],
    'queue_voice' => [
        'summary' => 'Announce called tickets out loud on the queue display.',
        'capabilities' => [
            'Spoken ticket calls on the display screen',
        ],
    ],
    'pos' => [
        'summary' => 'Charge visits and walk-in sales at the till and issue invoices.',
        'capabilities' => [
            'Services, products and adjustments in one sale',
            'Cashier shifts with cash counts',
            'Invoices numbered per branch',
            'Voids with a reason, kept in the audit trail',
        ],
    ],
    'printing' => [
        'summary' => 'Print receipts, invoices and queue tickets.',
        'capabilities' => [
            '80 mm receipts and A4 invoices',
            'Queue ticket printing',
        ],
    ],
    'payments' => [
        'summary' => 'Accept online payments on invoices through your own payment gateway.',
        'capabilities' => [
            'Gateway accounts per branch',
            'Online payment on the customer invoice',
            'Payments confirmed only by the provider',
            'Refunds recorded against the payment',
        ],
    ],
    'finance' => [
        'summary' => 'See how money moves through your center and close each day with a count.',
        'capabilities' => [
            'Collected, refunded and net movement by day',
            'Expenses with categories',
            'Counted cash closes with variance',
            'A ledger per branch',
        ],
    ],
    'loyalty' => [
        'summary' => 'Reward returning customers with points they can spend at the till.',
        'capabilities' => [
            'Points earned on collected payments',
            'Redemption at the till',
            'Tiers by lifetime points',
            'Point expiry you control',
        ],
    ],
    'memberships' => [
        'summary' => 'Sell memberships with benefits your customers use on every visit.',
        'capabilities' => [
            'Membership plans with price and period',
            'Discounts and included services',
            'Activation when the invoice is settled',
            'Benefit usage history',
        ],
    ],
    'packages' => [
        'summary' => 'Sell prepaid service packages used session by session.',
        'capabilities' => [
            'Bundles of sessions for your services',
            'Validity periods',
            'Sessions used only for services performed',
            'Remaining sessions for every customer',
        ],
    ],
    'reviews' => [
        'summary' => 'Collect reviews after completed visits and see how each service and employee is rated.',
        'capabilities' => [
            'Review links after every completed visit',
            'Ratings per service and employee',
            'Moderation: hide or flag',
            'Rating summaries',
        ],
    ],
    'reports_standard' => [
        'summary' => 'Understand your center with ready-made reports.',
        'capabilities' => [
            'Bookings, visits and services',
            'Sales, payments and finance movement',
            'Customer activity',
            'CSV export and print',
        ],
    ],
    'reports_advanced' => [
        'summary' => 'An analytics workspace: trends, comparisons and AI insights across your whole center.',
        'capabilities' => [
            'Executive summary and business trends against the previous period or last year',
            'Branch, employee and service comparisons side by side',
            'Sales, booking, customer and service intelligence with busy-hour heatmaps',
            'AI insights from RAYAN with its own allowance',
            'Ten detailed analyses with CSV export',
        ],
    ],
    'whatsapp_booking' => [
        'summary' => 'Talk to customers on WhatsApp from your own number, with your team in one shared inbox.',
        'capabilities' => [
            'Your own WhatsApp Business number',
            'A shared inbox where your team reads and replies',
            'Delivery status for every message sent',
        ],
    ],
    'rayan_ai' => [
        'summary' => 'RAYAN answers customers on WhatsApp, books through your rules and hands the conversation to your team when it cannot finish.',
        'capabilities' => [
            'Finds services, branches and available times',
            'Books, moves and cancels appointments through your booking rules',
            'Hands over to a person when it cannot finish',
        ],
    ],
];
