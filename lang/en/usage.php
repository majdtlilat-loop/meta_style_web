<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The usage dashboard
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md, docs/26-USAGE-QUOTAS.md §14.
|
| Named after the SURFACE. A dotless `__('Usage')` would be parsed as a
| translation GROUP and, on a case-insensitive filesystem, return this whole
| array -- the collision an architecture test refuses.
|
| Nothing here states a price. A center is shown what THEY used against what
| THEY bought; Meta Style's own costs and margins are not theirs to read.
|
*/

return [
    'title' => 'Usage',
    'not_allowed' => 'You may not view usage.',
    'none' => 'Nothing used yet this period.',
    'group_ai' => 'Assistant',
    'group_advanced_reports' => 'Advanced Report analysis',
    'group_whatsapp' => 'WhatsApp',
    'resource' => 'Resource',
    'used' => 'Used',
    'allowance' => 'Allowance',
    'remaining' => 'Remaining',
    'status' => 'Status',
    'resets' => 'Resets',
    'unlimited' => 'Unlimited',
    'metered_only' => 'measured, not limited',
    'status_normal' => 'Normal',
    'status_warning' => 'Getting close',
    'status_high' => 'Nearly used up',
    'status_exhausted' => 'Used up',
    'resource_ai_runs' => 'Assistant replies',
    'resource_advanced_report_ai_runs' => 'RAYAN report analyses',
    'resource_ai_input_tokens' => 'Words read',
    'resource_ai_output_tokens' => 'Words written',
    'resource_ai_tool_calls' => 'Lookups',
    'resource_ai_failed_runs' => 'Failed replies',
    'resource_wa_inbound' => 'Messages received',
    'resource_wa_outbound' => 'Messages sent',
    'resource_wa_template' => 'Templates sent',
    'resource_wa_failed' => 'Messages not delivered',
];
