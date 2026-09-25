<?php

declare(strict_types=1);

return [
    'ticket_created' => [
        'title' => 'تذكرة دعم جديدة :reference',
        'body' => ':center: :subject',
    ],
    'ticket_replied' => [
        'title' => 'رد على :reference',
        'body' => 'ردّ :center على التذكرة.',
    ],
    'center_registered' => [
        'title' => 'مركز جديد: :center',
        'body' => 'سُجّل على خطة :plan وأصبح جاهزاً للاستخدام.',
    ],
    'center_created' => [
        'title' => 'المركز جاهز: :center',
        'body' => 'أُنشئ على خطة :plan. أُرسل للمالك رابط لتعيين كلمة المرور.',
    ],
    'provisioning_failed' => [
        'title' => 'فشل إعداد مركز',
        'body' => 'تعذّر إعداد :center. أعد المحاولة من صفحة المراكز.',
    ],
    'subscription_plan_changed' => [
        'title' => 'تغيّرت الخطة',
        'body' => 'انتقل :center إلى خطة جديدة.',
    ],
    'subscription_cycle_changed' => [
        'title' => 'تغيّرت دورة الفوترة',
        'body' => 'غيّر :center دورة فوترته.',
    ],
    'subscription_activated' => [
        'title' => 'فُعّل الاشتراك',
        'body' => 'بدأ :center اشتراكاً مدفوعاً.',
    ],
    'subscription_trial_extended' => [
        'title' => 'تغيّرت نهاية التجربة',
        'body' => 'تغيّر تاريخ نهاية التجربة لـ :center.',
    ],
    'subscription_renewal_changed' => [
        'title' => 'تغيّر تاريخ التجديد',
        'body' => 'تغيّر تاريخ التجديد لـ :center.',
    ],
    'invoice_issued' => [
        'title' => 'صدرت الفاتورة :number',
        'body' => ':amount على :center.',
    ],
    'payment_recorded' => [
        'title' => 'سُجّلت دفعة على :number',
        'body' => 'استُلم :amount من :center.',
    ],
    'mail' => [
        'open' => 'فتح في Meta Style',
    ],
];
