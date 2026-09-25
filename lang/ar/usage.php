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
    'title' => 'الاستهلاك',
    'not_allowed' => 'لا يمكنك عرض الاستهلاك.',
    'none' => 'لا يوجد استهلاك في هذه الفترة بعد.',
    'group_ai' => 'المساعد',
    'group_advanced_reports' => 'تحليل التقارير المتقدمة',
    'group_whatsapp' => 'واتساب',
    'resource' => 'المورد',
    'used' => 'المستخدم',
    'allowance' => 'الحصة',
    'remaining' => 'المتبقي',
    'status' => 'الحالة',
    'resets' => 'التجديد',
    'unlimited' => 'غير محدود',
    'metered_only' => 'مُقاس وليس محدودًا',
    'status_normal' => 'طبيعي',
    'status_warning' => 'يقترب',
    'status_high' => 'شارف على النفاد',
    'status_exhausted' => 'نفد',
    'resource_ai_runs' => 'ردود المساعد',
    'resource_advanced_report_ai_runs' => 'تحليلات ريان للتقارير',
    'resource_ai_input_tokens' => 'كلمات مقروءة',
    'resource_ai_output_tokens' => 'كلمات مكتوبة',
    'resource_ai_tool_calls' => 'عمليات بحث',
    'resource_ai_failed_runs' => 'ردود فاشلة',
    'resource_wa_inbound' => 'رسائل واردة',
    'resource_wa_outbound' => 'رسائل مرسلة',
    'resource_wa_template' => 'قوالب مرسلة',
    'resource_wa_failed' => 'رسائل لم تُسلّم',
];
