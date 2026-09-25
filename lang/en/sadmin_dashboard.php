<?php

declare(strict_types=1);

return [
    'title' => 'Overview',
    'kpi' => [
        'label' => 'Key figures',
        'total_centers' => 'Total centers',
        'new_centers' => 'New centers',
        'live_subscriptions' => 'Live subscriptions',
        'live_hint' => ':trialing on trial · :past_due past due',
        'collected' => 'Collected',
        'outstanding' => 'Outstanding balance',
        'overdue' => '{0} Nothing overdue|{1} 1 invoice overdue|[2,*] :count invoices overdue',
        'open_tickets' => 'Open tickets',
        'urgent' => '{0} None urgent|{1} 1 urgent or high|[2,*] :count urgent or high',
    ],
    'charts' => [
        'new_centers' => 'New centers',
        'centers_by_status' => 'Centers by status',
        'billed_vs_collected' => 'Billed vs collected',
        'billed' => 'Billed',
        'collected' => 'Collected',
        'by_plan' => 'Live subscriptions by plan',
        'by_cycle' => 'Subscriptions by billing cycle',
        'invoice_status' => 'Invoices by status',
    ],
    'billing' => [
        'title' => 'Billing',
        'issued' => 'Invoices issued',
        'overdue' => 'Overdue invoices',
        'billed' => 'Billed',
        'collected' => 'Collected',
        'outstanding' => 'Outstanding',
        'empty' => 'No SaaS billing yet',
    ],
    'support' => [
        'title' => 'Support',
        'new' => 'New',
        'resolved' => 'Resolved',
        'open' => 'Open now',
        'empty' => 'No open tickets',
    ],
    'registrations' => [
        'title' => 'Recent registrations',
        'empty' => 'No registrations yet',
    ],
    'activity' => [
        'title' => 'Platform activity',
        'empty' => 'No activity recorded',
    ],
    'issues' => [
        'title' => '{1} 1 center failed provisioning|[2,*] :count centers failed provisioning',
    ],
    'actions' => [
        'centers' => 'View centers',
        'billing' => 'View billing',
        'support' => 'View support',
        'audit' => 'View audit',
        'operations' => 'Operations',
    ],
    'strip' => [
        'label' => 'At a glance',
        'active' => 'Active centers',
        'trial' => 'On trial',
        'suspended' => 'Suspended',
        'monthly' => 'Monthly subscriptions',
        'yearly' => 'Yearly subscriptions',
        'invoices' => 'Invoices in period',
        'users' => 'Platform users',
        'alerts' => 'Active alerts',
    ],
];
