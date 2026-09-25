<?php

declare(strict_types=1);

/*
 * Human labels for every platform state a Super Admin screen shows. Raw
 * values (`past_due`, `waiting_center`) are domain vocabulary and never reach
 * the page.
 */
return [
    'tenant_status' => [
        'provisioning' => 'Provisioning',
        'active' => 'Active',
        'suspended' => 'Suspended',
        'cancelled' => 'Cancelled',
        'archived' => 'Archived',
        'failed' => 'Failed',
    ],
    'provisioning_status' => [
        'pending' => 'Pending',
        'running' => 'Running',
        'completed' => 'Completed',
        'succeeded' => 'Succeeded',
        'ready' => 'Ready',
        'failed' => 'Failed',
        'provisioning' => 'Provisioning',
    ],
    'subscription_status' => [
        'trialing' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Past due',
        'suspended' => 'Suspended',
        'cancelled' => 'Cancelled',
        'expired' => 'Expired',
    ],
    'invoice_status' => [
        'draft' => 'Draft',
        'issued' => 'Issued',
        'partially_paid' => 'Partially paid',
        'overdue' => 'Overdue',
        'settled' => 'Settled',
        'void' => 'Void',
    ],
    'payment_method' => [
        'cash' => 'Cash',
        'bank_transfer' => 'Bank transfer',
        'manual_electronic' => 'Electronic (manual)',
        'other' => 'Other',
    ],
    'ticket_status' => [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'waiting_center' => 'Waiting on center',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],
    'ticket_priority' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],
    'registration_status' => [
        'pending_verification' => 'Awaiting email verification',
        'preparing' => 'Preparing',
        'ready' => 'Ready',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
        'abandoned' => 'Abandoned',
    ],
    'severity' => [
        'info' => 'Info',
        'notice' => 'Notice',
        'warning' => 'Warning',
        'critical' => 'Critical',
        'danger' => 'Danger',
        'success' => 'Success',
        'error' => 'Error',
    ],
    'audit_category' => [
        'tenancy' => 'Centers',
        'security' => 'Security',
        'config' => 'Configuration',
        'system' => 'System',
        'finance' => 'Finance',
        'booking' => 'Booking',
        'customer' => 'Customers',
    ],
    'billing_period' => [
        'monthly' => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly' => 'Yearly',
    ],
    'override_mode' => [
        'grant' => 'Granted',
        'revoke' => 'Revoked',
        'allow' => 'Allowed',
        'deny' => 'Denied',
    ],
    'health' => [
        'healthy' => 'Healthy',
        'warning' => 'Warning',
        'critical' => 'Error',
        'error' => 'Error',
        'unknown' => 'Unknown',
    ],

    'entitlement' => [
        'booking' => 'Online booking',
        'customer_accounts' => 'Customer accounts',
        'crm' => 'Customer relationship tools',
        'queue_management' => 'Queue management',
        'queue_display' => 'Queue display screen',
        'queue_voice' => 'Queue voice calling',
        'pos' => 'Point of sale',
        'printing' => 'Receipt and invoice printing',
        'payments' => 'Online payments',
        'finance' => 'Finance',
        'inventory' => 'Inventory',
        'loyalty' => 'Loyalty',
        'memberships' => 'Memberships',
        'packages' => 'Service packages',
        'reviews' => 'Reviews',
        'reports_standard' => 'Standard reports',
        'reports_advanced' => 'Advanced reports',
        'whatsapp_booking' => 'WhatsApp booking',
        'rayan_ai' => 'RAYAN assistant',
        'white_label_app' => 'White-label app',
    ],

    'entitlement_category' => [
        'core' => 'Core',
        'queue' => 'Queue',
        'commerce' => 'Sales and money',
        'engagement' => 'Customer engagement',
        'insight' => 'Reports',
        'channels' => 'Channels',
    ],

    'entitlement_source' => [
        'plan' => 'Included in plan',
        'not_in_plan' => 'Not in plan',
        'granted' => 'Granted by override',
        'revoked' => 'Revoked by override',
    ],

    'usage_resource' => [
        'ai_runs' => 'Assistant replies',
        'advanced_report_ai_runs' => 'RAYAN report analyses',
        'ai_input_tokens' => 'Words read',
        'ai_output_tokens' => 'Words written',
        'ai_tool_calls' => 'Lookups',
        'ai_failed_runs' => 'Failed replies',
        'wa_inbound' => 'Messages received',
        'wa_outbound' => 'Messages sent',
        'wa_template' => 'Templates sent',
        'wa_failed' => 'Messages not delivered',
    ],

    'usage_status' => [
        'normal' => 'Normal',
        'warning' => 'Getting close',
        'high' => 'Nearly used up',
        'exhausted' => 'Used up',
    ],
];
