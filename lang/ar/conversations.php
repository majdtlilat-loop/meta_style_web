<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The conversation surface
|--------------------------------------------------------------------------
|
| docs/07-LOCALIZATION.md, docs/25-WHATSAPP.md §§17.
|
| Named after the SURFACE, never after a dotless literal: `__('Conversations')`
| would be parsed as a translation GROUP and, on a case-insensitive filesystem,
| return this whole array. The translation-collision architecture test enforces
| the naming.
|
| The customer-facing line here is the ONLY thing the application says to a
| customer in its own voice. Everything else a customer reads is either the
| assistant's words or a member of staff's.
|
| It deliberately explains NOTHING. Not that the assistant failed, not that the
| center has run out of a paid allowance, not that a provider is down -- a
| person messaging a salon about their haircut is not a party to any of that,
| and saying so would embarrass the center to their own customer
| (docs/13-ROADMAP.md Phase 13 §§51).
|
*/

return [
    'handoff_acknowledgement' => 'شكرًا لرسالتك. سيرد عليك أحد أفراد الفريق هنا قريبًا.',

    'title' => 'المحادثات',
    'empty' => 'لا توجد محادثات مفتوحة.',
    'waiting' => 'بانتظار موظف',
    'status_ai_active' => 'المساعد',
    'status_human_requested' => 'يحتاج إلى موظف',
    'status_human_active' => 'مع :name',
    'status_closed' => 'مغلقة',
    'take_over' => 'استلام المحادثة',
    'return_to_assistant' => 'إعادتها إلى المساعد',
    'close' => 'إغلاق',
    'reply' => 'رد',
    'reply_placeholder' => 'اكتب ردًا…',
    'unknown_contact' => 'رقم غير معروف',
    'delivery_pending' => 'جارٍ الإرسال…',
    'delivery_failed' => 'لم تُسلَّم',
    'delivery_unknown' => 'التسليم غير مؤكد',

    // ---- Inbox screen (Manager) --------------------------------------
    'waiting_count' => ':count بانتظار موظف',
    'assistant_on' => 'المساعد مفعّل',
    'not_allowed' => 'لا يمكنك عرض المحادثات.',
    'not_allowed_hint' => 'اطلب من المدير صلاحية الوصول إلى صندوق الرسائل.',
    'all_open' => 'كل المفتوحة',
    'filter_ai_active' => 'المساعد',
    'filter_human_requested' => 'يحتاج إلى موظف',
    'filter_human_active' => 'مع الفريق',
    'a_colleague' => 'زميل',
    'thread' => 'المحادثة',
    'pick' => 'اختر محادثة',
    'author_customer' => 'العميل',
    'author_ai' => 'المساعد',
    'author_staff' => 'الفريق',
    'author_system' => 'النظام',
    'template' => 'قالب: :name',
    'no_messages' => 'لا رسائل بعد.',
    'close_confirm' => 'إغلاق هذه المحادثة؟ أي رسالة جديدة من العميل تفتح محادثة جديدة.',
    'reply_help' => 'تُرسل باسم المركز. الرد يعني استلام المحادثة من المساعد.',
    'reply_locked' => 'الرد يتطلب واتساب ضمن خطتك. ما زال بإمكانك قراءة المحادثات وإغلاقها.',
    'sent' => 'الرد في طريقه للإرسال.',
    'taken_over' => 'أنت تتولى هذه المحادثة.',
    'returned' => 'أُعيدت إلى المساعد.',
    'closed' => 'أُغلقت المحادثة.',

    // ---- تأكيد حجز الضيف (docs/25-WHATSAPP.md §22) ----------------------
    'booking_confirmation' => [
        'body' => "مرحبًا :customer، تم تأكيد حجزك لدى :center (:branch).\nالتاريخ: :date\nالوقت: :time\nالخدمات: :services\nرقم الحجز: :reference\nللتواصل: :contact",
        'time_range' => ':start–:end',
        'service_variation' => ':service (:variation)',
        'service_addons' => ':service + :addons',
        'service_employee' => ':service مع :employee',
        'separator' => '، ',
        'none' => '—',
    ],
];
