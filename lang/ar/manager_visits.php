<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Manager — today's visits (the floor board)
|--------------------------------------------------------------------------
|
| docs/16-JOURNEY-RESOURCES.md. Keys in exact parity with en/ckb.
|
*/

return [
    'live' => 'مباشر',
    'live_hint' => 'تتحدث تلقائياً ما دامت هذه الصفحة مفتوحة.',

    'actions' => [
        'walk_in' => 'زيارة بدون موعد',
        'check_in' => 'تسجيل الوصول',
        'start' => 'بدء',
        'finish' => 'إنهاء',
        'checkout' => 'الدفع',
        'details' => 'فتح الزيارة',
        'hand_on' => 'نقل إلى التالي',
        'give_number' => 'إعطاء رقم انتظار',
        'reassign' => 'إعادة الإسناد',
        'swap' => 'تبديل الغرفة',
        'skip' => 'تخطي',
        'note' => 'ملاحظة',
        'left' => 'غادر العميل',
        'cancel_booking' => 'إلغاء الحجز',
        'complete' => 'إكمال الزيارة',
    ],

    'stats' => [
        'label' => 'تصفية حسب المرحلة',
        'late' => ':count متأخر',
        'walk_ins' => ':count بدون موعد',
    ],

    'filters' => [
        'team_member' => 'عضو الفريق',
        'everyone' => 'الجميع',
    ],

    'lanes' => [
        'label' => 'عرض',
        'not_arrived' => 'لم يصل',
        'waiting' => 'في الانتظار',
        'in_service' => 'قيد الخدمة',
        'completed' => 'مكتملة',
        'abandoned' => 'غادروا',
        'empty_not_arrived' => 'لا أحد آخر متوقع.',
        'empty_waiting' => 'لا أحد في الانتظار.',
        'empty_in_service' => 'لا أحد على الكرسي.',
        'empty_completed' => 'لا زيارات مكتملة بعد.',
        'empty_abandoned' => 'لم يغادر أحد مبكراً.',
    ],

    'card' => [
        'walk_in' => 'بدون موعد',
        'late' => 'متأخر :minutes د',
        'waiting' => 'ينتظر منذ :minutes د',
        'elapsed' => ':minutes من :expected د',
        'progress' => ':done من :total خدمات',
    ],

    'notices' => [
        'checked_in' => 'تم تسجيل الوصول.',
        'started' => 'بدأت الخدمة.',
        'finished' => 'انتهت الخدمة.',
        'walk_in' => 'تم إنشاء زيارة بدون موعد.',
        'walk_in_ticket' => 'تم إنشاء زيارة بدون موعد بالرقم :number.',
        'ticket' => 'صدر الرقم :number.',
        'skipped' => 'تم تخطي الخدمة.',
        'reassigned' => 'تمت إعادة الإسناد. يبقى الحجز يُظهر من حُجز معه.',
        'swapped' => 'تم تبديل الغرفة. تبقى السابقة في السجل.',
        'handed_on' => 'تم النقل إلى الخدمة التالية.',
        'note_added' => 'أُضيفت الملاحظة.',
        'note_deleted' => 'حُذفت الملاحظة.',
        'completed' => 'اكتملت الزيارة.',
        'left' => 'انتهت الزيارة. لم يتغير الحجز نفسه.',
        'cancelled' => 'انتهت الزيارة وأُلغي الحجز.',
    ],

    'panel' => [
        'walk_in_arrived' => 'بدون موعد · وصل :time',
        'booked_for' => 'محجوز الساعة :time · :reference',
        'arrived' => 'وصل :time',
        'completed_at' => 'اكتملت :time',
        'left_at' => 'غادر :time',
        'left_reason' => 'غادر مبكراً: :reason',
        'services' => 'الخدمات',
        'booked_with' => 'محجوز مع',
        'anyone' => 'أي شخص',
        'with' => 'الآن مع',
        'reassigned' => 'أُعيد إسنادها',
        'planned' => 'المخطط',
        'actual' => 'الفعلي',
        'skipped_because' => 'تم التخطي: :reason',
        'handoffs' => 'عمليات النقل',
        'handoff_end' => 'انتهى',
    ],

    'visibility' => [
        'internal' => 'الفريق',
        'manager_only' => 'المدراء فقط',
    ],

    'notes' => [
        'delete' => 'حذف الملاحظة',
        'delete_confirm' => 'حذف هذه الملاحظة؟ لا يمكن التراجع عن ذلك.',
        'advisory' => 'ملاحظات تشغيلية فقط — لا معلومات طبية أو صحية أبداً. يستطيع الموظفون الآخرون قراءتها.',
    ],

    'forms' => [
        'skip' => [
            'title' => 'تخطي هذه الخدمة',
            'reason' => 'لماذا؟',
            'submit' => 'تخطي الخدمة',
        ],
        'handoff' => [
            'title' => 'النقل إلى الخدمة التالية',
            'note' => 'ملاحظة للشخص التالي',
            'submit' => 'نقل',
        ],
        'reassign' => [
            'title' => 'إعادة الإسناد',
            'none' => 'لا أحد آخر في هذا الفرع يستطيع تقديم هذه الخدمة.',
            'employee' => 'عضو الفريق',
            'submit' => 'إعادة الإسناد',
        ],
        'swap' => [
            'title' => 'تبديل الغرفة أو الجهاز',
            'none' => 'هذه الخدمة لا تستخدم غرفة أو جهازاً الآن.',
            'from' => 'الحالي',
            'to' => 'الجديد',
            'submit' => 'تبديل',
        ],
        'note' => [
            'title' => 'إضافة ملاحظة',
            'body' => 'الملاحظة',
            'visibility' => 'من يستطيع قراءتها',
            'submit' => 'إضافة الملاحظة',
        ],
        'leave' => [
            'title' => 'غادر العميل',
            'help' => 'ينهي الزيارة. لا يُلغى الحجز نفسه.',
            'confirm' => 'إنهاء هذه الزيارة؟ الخدمات التي لم تبدأ لن تتم.',
            'submit' => 'إنهاء الزيارة',
        ],
        'cancel' => [
            'title' => 'إلغاء الحجز',
            'help' => 'ينهي الزيارة ويلغي الحجز وفق قواعد الحجز.',
            'confirm' => 'إنهاء الزيارة وإلغاء الحجز؟',
            'submit' => 'إلغاء الحجز',
        ],
    ],
];
