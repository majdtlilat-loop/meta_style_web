<?php

declare(strict_types=1);

return [
    'title' => 'Operations',
    'refresh' => 'Refresh center health',
    'environment' => 'Environment: :env',
    'readiness' => [
        'title' => 'Production readiness',
        'note' => 'Checked against production rules, whatever this environment is.',
        'technical_details' => 'Technical details',
        'recommended_action' => 'Recommended action',
        'checks' => [
            'middleware_order' => [
                'name' => 'Middleware order',
                'detail' => 'Center resolution must run before sign-in on every protected page.',
            ],
            'rate_limit_backend' => [
                'name' => 'Rate-limit backend',
                'detail' => 'Limits must use a store every application process shares.',
            ],
            'cache_tags' => [
                'name' => 'Center cache isolation',
                'detail' => 'The cache must support the tags that keep centers apart.',
            ],
            'queue_driver' => [
                'name' => 'Queue driver',
                'detail' => 'Provisioning must run on a durable background queue.',
            ],
            'session_driver' => [
                'name' => 'Session driver',
                'detail' => 'Sessions must survive between requests and across workers.',
            ],
            'debug_mode' => [
                'name' => 'Debug mode',
                'detail' => 'Production must never show framework diagnostics.',
            ],
            'application_key' => [
                'name' => 'Application key',
                'detail' => 'Encrypted registration credentials need an application key.',
            ],
            'platform_domains' => [
                'name' => 'Platform domains',
                'detail' => 'Corporate, Super Admin and center hosts must follow one domain model.',
            ],
            'wildcard_dns_and_tls' => [
                'name' => 'Wildcard DNS and TLS',
                'detail' => 'Center subdomains need wildcard DNS and certificates.',
            ],
            'advanced_reports_connection' => [
                'name' => 'Advanced Reports connection',
                'detail' => 'Advanced Reports need their reporting connection; Standard Reports do not.',
            ],
            'booking_verification_key' => [
                'name' => 'Booking verification key',
                'detail' => 'Booking verification codes need a complete, strong keyring.',
            ],
            'public_media_link' => [
                'name' => 'Public media link',
                'detail' => 'Landing-page images and videos are served from public/storage.',
            ],
        ],
    ],
    'projections' => [
        'title' => 'Center health',
        'center' => 'Center',
        'health' => 'Health',
        'provisioning' => 'Provisioning',
        'migration' => 'Migration',
        'support' => 'Open tickets',
        'alerts' => 'Active alerts',
        'projected' => 'Updated',
        'unknown_center' => 'Unknown center',
        'empty' => 'No center health yet. Refresh to build it from control-plane facts.',
    ],
    'operations' => [
        'title' => 'Recent provisioning operations',
        'started' => 'Started',
        'center' => 'Center',
        'type' => 'Operation',
        'status' => 'Status',
        'attempt' => 'Attempt',
        'duration' => 'Duration',
        'empty' => 'No provisioning operations yet.',
    ],
    'statuses' => [
        'healthy' => 'Healthy',
        'attention' => 'Warning',
        'critical' => 'Error',
        'ok' => 'Healthy',
        'warning' => 'Warning',
        'failure' => 'Error',
        'running' => 'Running',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'pending' => 'Pending',
    ],
    'types' => [
        'provision' => 'Provision',
        'migrate' => 'Migrate',
        'seed' => 'Seed',
        'archive' => 'Archive',
    ],
    'refreshed' => '{0} No center to refresh|{1} :count center refreshed|[2,*] :count centers refreshed',
];
