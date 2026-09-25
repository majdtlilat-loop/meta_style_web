<?php

declare(strict_types=1);

return [
    'title' => 'دعم Meta Style',
    'results' => '{0} لا توجد تذاكر|{1} تذكرة واحدة|[2,*] :count تذاكر',
    'actions' => [
        'new' => 'طلب جديد',
        'reopen' => 'إعادة فتح',
        'close' => 'إغلاق التذكرة',
        'send' => 'إرسال الرد',
    ],
    'filters' => [
        'label' => 'حالة التذكرة',
        'active' => 'النشطة',
        'waiting_center' => 'بانتظارك',
        'resolved' => 'المحلولة',
        'closed' => 'المغلقة',
        'all' => 'الكل',
    ],
    'status' => [
        'open' => 'مفتوحة',
        'in_progress' => 'قيد المعالجة',
        'waiting_center' => 'بانتظار ردك',
        'resolved' => 'محلولة',
        'closed' => 'مغلقة',
    ],
    'table' => [
        'ticket' => 'التذكرة',
        'priority' => 'الأولوية',
        'status' => 'الحالة',
        'activity' => 'آخر نشاط',
        'opened_by' => 'فتحها :name',
    ],
    'empty' => [
        'title' => 'لا توجد تذاكر هنا',
        'active_title' => 'لا توجد طلبات مفتوحة',
    ],
    'create' => [
        'title' => 'طلب دعم جديد',
        'placeholder' => 'صف ما تحتاج إليه…',
        'submit' => 'إرسال الطلب',
    ],
    'fields' => [
        'subject' => 'الموضوع',
        'message' => 'الرسالة',
        'priority' => 'الأولوية',
        'reply' => 'الرد',
        'attach' => 'إرفاق ملفات',
        'attach_help' => 'PDF أو PNG أو JPG أو نص · حتى 3 ملفات، 5 ميغابايت لكل ملف',
        'uploading' => 'جارٍ الرفع…',
        'remove_file' => 'إزالة :name',
        'attachments' => 'المرفقات',
        'attachment' => 'المرفق',
    ],
    'flash' => [
        'created' => 'تم إرسال الطلب :reference. سيرد فريق Meta Style هنا.',
        'replied' => 'تم إرسال الرد.',
        'closed' => 'تم إغلاق التذكرة.',
        'reopened' => 'تمت إعادة فتح التذكرة.',
    ],
    'confirm' => [
        'close_title' => 'إغلاق هذه التذكرة؟',
        'close_body' => 'أغلقها بعد الإجابة عن سؤالك. يمكنك إعادة فتحها لاحقًا.',
    ],
    'errors' => [
        'forbidden' => 'ليست لديك صلاحية لإدارة طلبات الدعم.',
        'invalid' => 'الموضوع والرسالة وأولوية صحيحة مطلوبة.',
        'closed' => 'هذه التذكرة مغلقة. أعد فتحها للرد.',
        'not_reopenable' => 'يمكن إعادة فتح التذكرة المحلولة أو المغلقة فقط.',
        'too_many_files' => 'أرفق :count ملفات كحد أقصى.',
        'file_rejected' => 'لا يمكن إرفاق هذا الملف. استخدم PDF أو PNG أو JPG أو نصًا، بحد أقصى 5 ميغابايت.',
    ],
    'ticket' => [
        'breadcrumb' => 'مسار التنقل',
        'conversation' => 'المحادثة',
        'no_messages' => 'لا توجد رسائل بعد.',
        'download' => 'تنزيل :name',
        'reply_placeholder' => 'اكتب ردك…',
        'reply_reopens' => 'الرد يعيد فتح هذه التذكرة المحلولة.',
        'closed_note' => 'هذه التذكرة مغلقة. أعد فتحها لمتابعة المحادثة.',
        'details' => 'التفاصيل',
        'reference' => 'المرجع',
        'opened_by' => 'فتحها',
        'opened' => 'تاريخ الفتح',
        'resolved' => 'تاريخ الحل',
        'closed' => 'تاريخ الإغلاق',
        'separate_note' => 'لا يرى هذه التذكرة إلا فريقك وMeta Style — وليس عملاؤك أبدًا.',
    ],
    'author' => [
        'center' => 'فريقك',
        'platform' => 'Meta Style',
    ],
    'size' => [
        'kb' => ':size كيلوبايت',
        'mb' => ':size ميغابايت',
    ],
];
