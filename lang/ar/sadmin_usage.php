<?php

declare(strict_types=1);

return [
    'title' => 'الاستخدام',
    'set_override' => 'تحديد حصة',

    'filters' => [
        'label' => 'تصفية الاستخدام',
        'center' => 'المركز',
        'all_centers' => 'كل المراكز',
        'resource' => 'المورد',
        'all_resources' => 'كل الموارد',
        'status' => 'الحالة',
        'all_statuses' => 'أي حالة',
        'clear' => 'مسح التصفية',
    ],

    'table' => [
        'center' => 'المركز',
        'resource' => 'المورد',
        'usage' => 'المستخدم',
        'status' => 'الحالة',
        'period' => 'نهاية الفترة',
        'updated' => 'آخر تحديث',
        'allowance' => 'الحصة',
        'applies' => 'يسري',
        'reason' => 'السبب',
    ],

    'resources' => [
        'advanced_report_ai_runs' => 'ذكاء التقارير المتقدمة',
        'ai_runs' => 'RAYAN الموجّه للعملاء',
    ],

    'unlimited' => 'غير محدود',
    'metered' => 'يُقاس ولا يُقيَّد',
    'unknown_center' => 'مركز غير معروف',
    'applies_now' => 'الآن',
    'applies_next' => 'الفترة القادمة',
    'lag_note' => 'هذه الأرقام نسخ مأخوذة من كل مركز وقد تتأخر بضع دقائق.',

    'empty' => 'لم يُسجَّل أي استخدام بعد.',
    'empty_filtered' => 'لا يوجد استخدام مطابق لهذه التصفية.',
    'overrides_title' => 'تجاوزات الحصص',
    'no_overrides' => 'لا يملك أي مركز تجاوزًا للحصة.',

    'fields' => [
        'center' => 'المركز',
        'choose_center' => 'اختر مركزًا',
        'resource' => 'المورد',
        'choose_resource' => 'اختر موردًا',
        'allowance' => 'الحصة لكل فترة',
        'unlimited' => 'غير محدود',
        'enforce_immediately' => 'تطبيق التخفيض الآن بدلًا من الفترة القادمة',
        'enforce_help' => 'فقط عندما يجب إيقاف المركز خلال الفترة الحالية. الزيادة تُطبَّق فورًا دائمًا.',
        'reason' => 'السبب',
    ],

    'override_title' => 'تحديد حصة',
    'override_help' => 'لمنتجي الذكاء الاصطناعي حصتا تشغيل منفصلتان، ويبقى قياس الرموز مشتركًا.',
    'save' => 'حفظ الحصة',
    'saved' => 'تم حفظ الحصة، وستُطبَّق بأمان في المطابقة القادمة.',
    'change' => 'تغيير',
    'clear' => 'إعادة إلى الخطة',
    'clear_title' => 'إعادة هذه الحصة إلى الخطة؟',
    'clear_body' => 'تعود :resource لمركز :center إلى حصة الخطة.',
    'cleared' => 'أُعيدت الحصة إلى الخطة.',
];
