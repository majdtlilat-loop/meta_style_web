<?php

return [
    'actions' => ['edit' => 'Edit', 'cancel' => 'Cancel'],
    'fields' => ['center' => 'Center', 'plan' => 'Plan', 'status' => 'Status', 'reason' => 'Reason', 'required_reason' => 'Required reason'],
    'filters' => ['status' => 'Filter status', 'all_statuses' => 'All statuses'],
    'periods' => ['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'yearly' => 'Yearly'],
    'statuses' => [
        'active' => 'Active', 'inactive' => 'Inactive', 'trialing' => 'Trialing', 'past_due' => 'Past due', 'suspended' => 'Suspended',
        'cancelled' => 'Cancelled', 'expired' => 'Expired', 'open' => 'Open', 'settled' => 'Settled', 'void' => 'Void',
        'healthy' => 'Healthy', 'warning' => 'Warning', 'critical' => 'Critical', 'normal' => 'Normal', 'unlimited' => 'Unlimited',
    ],
];
