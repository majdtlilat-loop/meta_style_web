<?php

return [
    'actions' => ['edit' => 'تعديل', 'cancel' => 'إلغاء'],
    'fields' => ['center' => 'المركز', 'plan' => 'الخطة', 'status' => 'الحالة', 'reason' => 'السبب', 'required_reason' => 'السبب مطلوب'],
    'filters' => ['status' => 'تصفية حسب الحالة', 'all_statuses' => 'كل الحالات'],
    'periods' => ['monthly' => 'شهري', 'quarterly' => 'ربع سنوي', 'yearly' => 'سنوي'],
    'statuses' => [
        'active' => 'نشط', 'inactive' => 'غير نشط', 'trialing' => 'فترة تجريبية', 'past_due' => 'متأخر', 'suspended' => 'معلّق',
        'cancelled' => 'ملغى', 'expired' => 'منتهي', 'open' => 'مفتوح', 'settled' => 'مسدّد', 'void' => 'ملغى',
        'healthy' => 'سليم', 'warning' => 'تحذير', 'critical' => 'حرج', 'normal' => 'طبيعي', 'unlimited' => 'غير محدود',
    ],
];
