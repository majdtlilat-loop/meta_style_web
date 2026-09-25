<?php

declare(strict_types=1);

return [
    'title' => 'الدعم',
    'results' => '{0} لا توجد تذاكر|{1} تذكرة واحدة|{2} تذكرتان|[3,10] :count تذاكر|[11,*] :count تذكرة',
    'new' => 'تذكرة جديدة',

    'filters' => [
        'label' => 'تصفية التذاكر',
        'status' => 'الحالة',
        'active' => 'بحاجة إلى عمل',
        'all' => 'الكل',
        'priority' => 'الأولوية',
        'all_priorities' => 'أي أولوية',
        'center' => 'المركز',
        'all_centers' => 'كل المراكز',
        'search' => 'بحث',
        'search_placeholder' => 'الموضوع أو المرجع',
        'clear' => 'مسح التصفية',
    ],

    'table' => [
        'ticket' => 'التذكرة',
        'center' => 'المركز',
        'status' => 'الحالة',
        'priority' => 'الأولوية',
        'activity' => 'آخر نشاط',
        'opened_by' => 'فتحها :name',
    ],

    'empty' => [
        'title' => 'لا توجد تذاكر مطابقة لهذه التصفية',
        'none_title' => 'لا توجد تذاكر بحاجة إلى عمل',
        'description' => 'غيّر التصفية أو امسحها.',
    ],

    'create' => [
        'title' => 'فتح تذكرة لمركز',
        'center' => 'المركز',
        'choose_center' => 'اختر مركزًا',
        'subject' => 'الموضوع',
        'priority' => 'الأولوية',
        'message' => 'الرسالة',
        'submit' => 'إنشاء التذكرة',
    ],

    'show' => [
        'back' => 'الدعم',
        'conversation' => 'المحادثة',
        'no_messages' => 'لا توجد رسائل بعد.',
        'reply' => 'رد',
        'reply_placeholder' => 'اكتب ردًا للمركز…',
        'note_placeholder' => 'اكتب ملاحظة يراها فريق المنصة فقط…',
        'internal' => 'ملاحظة داخلية',
        'internal_help' => 'مخفية عن المركز.',
        'attachment' => 'إرفاق ملف',
        'attachment_help' => 'PDF أو PNG أو JPG أو TXT حتى 5 ميغابايت.',
        'send' => 'إرسال الرد',
        'save_note' => 'حفظ الملاحظة',
        'details' => 'التفاصيل',
        'reference' => 'المرجع',
        'center' => 'المركز',
        'opened' => 'تاريخ الفتح',
        'opened_by' => 'فتحها',
        'resolved' => 'تاريخ الحل',
        'closed' => 'تاريخ الإغلاق',
        'manage' => 'الحالة والإسناد',
        'status' => 'الحالة',
        'priority' => 'الأولوية',
        'assignee' => 'مُسندة إلى',
        'unassigned' => 'غير مُسندة',
        'reason' => 'سبب التغيير',
        'update' => 'تحديث التذكرة',
        'download' => 'تنزيل :name',
    ],

    'author_types' => [
        'platform' => 'المنصة',
        'center' => 'المركز',
        'system' => 'النظام',
        'staff' => 'موظفو المركز',
    ],

    'created' => 'تم إنشاء التذكرة.',
    'reply_added' => 'تم إرسال الرد.',
    'note_added' => 'تم حفظ الملاحظة.',
    'state_updated' => 'تم تحديث التذكرة.',
];
