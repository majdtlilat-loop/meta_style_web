<?php

declare(strict_types=1);

return [
    'title' => 'Usage',
    'set_override' => 'Set allowance',

    'filters' => [
        'label' => 'Filter usage',
        'center' => 'Center',
        'all_centers' => 'All centers',
        'resource' => 'Resource',
        'all_resources' => 'All resources',
        'status' => 'Status',
        'all_statuses' => 'Any status',
        'clear' => 'Clear filters',
    ],

    'table' => [
        'center' => 'Center',
        'resource' => 'Resource',
        'usage' => 'Used',
        'status' => 'Status',
        'period' => 'Period ends',
        'updated' => 'Updated',
        'allowance' => 'Allowance',
        'applies' => 'Applies',
        'reason' => 'Reason',
    ],

    'resources' => [
        'advanced_report_ai_runs' => 'Advanced Reports AI',
        'ai_runs' => 'Customer-facing RAYAN',
    ],

    'unlimited' => 'Unlimited',
    'metered' => 'Measured, not limited',
    'unknown_center' => 'Unknown center',
    'applies_now' => 'Now',
    'applies_next' => 'Next period',
    'lag_note' => 'These figures are copies taken from each center and may be a few minutes behind.',

    'empty' => 'No usage recorded yet.',
    'empty_filtered' => 'No usage matches these filters.',
    'overrides_title' => 'Allowance overrides',
    'no_overrides' => 'No center has an allowance override.',

    'fields' => [
        'center' => 'Center',
        'choose_center' => 'Choose a center',
        'resource' => 'Resource',
        'choose_resource' => 'Choose a resource',
        'allowance' => 'Allowance per period',
        'unlimited' => 'Unlimited',
        'enforce_immediately' => 'Apply a decrease now instead of from the next period',
        'enforce_help' => 'Only when the center must be stopped during the current period. Increases always apply now.',
        'reason' => 'Reason',
    ],

    'override_title' => 'Set an allowance',
    'override_help' => 'The two AI products have separate run allowances. Token metering stays shared.',
    'save' => 'Save allowance',
    'saved' => 'Allowance saved. It is applied safely by the next reconciliation.',
    'change' => 'Change',
    'clear' => 'Reset to plan',
    'clear_title' => 'Reset this allowance to the plan?',
    'clear_body' => ':resource for :center returns to the plan allowance.',
    'cleared' => 'Allowance reset to the plan.',
];
