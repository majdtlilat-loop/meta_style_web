<?php

declare(strict_types=1);

return [
    'title' => 'Super Admin',
    'skip_to_content' => 'Skip to content',
    'navigation_label' => 'Platform navigation',
    'navigation' => [
        'overview' => 'Overview',
        'centers' => 'Centers',
        'plans' => 'Plans',
        'subscriptions' => 'Subscriptions',
        'billing' => 'SaaS billing',
        'usage' => 'Usage',
        'support' => 'Support',
        'operations' => 'Operations',
        'cms' => 'Landing CMS',
        'audit' => 'Audit',
        'settings' => 'Settings',
    ],
    'topbar' => [
        'open_navigation' => 'Open navigation',
        'operations' => 'Platform operations',
        'alerts' => 'Alerts',
        'user_menu' => 'User menu',
        'sign_out' => 'Sign out',
        'close_navigation' => 'Close navigation',
    ],
    'sidebar' => [
        'collapse' => 'Collapse or expand sidebar',
    ],
    'language' => [
        'choose' => 'Choose language',
    ],
    'theme' => [
        'toggle' => 'Toggle color theme',
        'use_light' => 'Use light theme',
        'use_dark' => 'Use dark theme',
    ],
    'notifications' => [
        'open' => 'Open notifications',
        'title' => 'Notifications',
        'unread_count' => '{1} :count unread|[2,*] :count unread',
        'unread' => 'Unread notification',
        'empty_title' => 'No active alerts',
        'empty_body' => 'Operational alerts will appear here when action is required.',
        'view_all' => 'View all notifications',
    ],
    'status' => [
        'info' => 'Information',
        'warning' => 'Warning',
        'critical' => 'Critical',
        'danger' => 'Danger',
        'success' => 'Success',
    ],
    'center_domain' => [
        'missing' => 'No domain is registered for this center.',
        'invalid' => 'The registered domain is invalid, outside the configured base host, or uses a reserved platform name.',
        'mismatch' => 'The registered domain and stored center slug do not agree.',
    ],
    'dashboard' => [
        'eyebrow' => 'Control plane',
        'title' => 'Operational overview',
        'description' => 'Control-plane facts only. No tenant databases are queried on this page.',
        'metrics_label' => 'Platform metrics',
        'centers' => 'Centers',
        'active_subscriptions' => 'Active subscriptions',
        'open_invoices' => 'Open SaaS invoices',
        'open_tickets' => 'Open support tickets',
        'recent_centers' => 'Recently created centers',
        'recent_description' => 'Latest control-plane center records.',
        'view_all' => 'View all centers',
        'empty_title' => 'No centers yet',
        'empty_description' => 'Verified registrations will appear here after provisioning.',
        'table' => [
            'center' => 'Center',
            'address' => 'Address',
            'status' => 'Status',
            'created' => 'Created',
        ],
    ],
];
