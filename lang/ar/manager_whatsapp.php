<?php

declare(strict_types=1);

/*
 * Manager → Settings → WhatsApp (docs/25-WHATSAPP.md §21).
 */
return [
    'title' => 'واتساب',
    'inactive' => 'حجز واتساب غير متاح حاليًا',

    'status' => [
        'connected' => 'متصل',
        'not_connected' => 'غير متصل',
        'booking_on' => 'الحجز مفعّل',
        'booking_off' => 'الحجز متوقف',
        'on' => 'مفعّل',
        'off' => 'متوقف',
    ],

    'actions' => [
        'connect' => 'ربط واتساب',
        'edit' => 'تعديل الربط',
        'check' => 'فحص الإعداد',
        'turn_on' => 'تشغيل',
        'turn_off' => 'إيقاف',
        'turn_off_title' => 'إيقاف حجز واتساب؟',
        'turn_off_confirm' => 'لن تُستقبل رسائل واتساب الجديدة حتى تعيد تشغيله.',
        'open_conversations' => 'فتح المحادثات',
    ],

    'link' => [
        'locked' => 'غير مشمول في الخطة',
        'attention' => 'يحتاج إلى متابعة',
    ],

    'empty' => [
        'title' => 'واتساب غير مربوط',
        'view_only' => 'يمكن فقط لمن يملك صلاحية إدارة واتساب ربطه.',
    ],

    'connection' => [
        'title' => 'الربط',
        'number' => 'رقم واتساب',
        'display_name' => 'الاسم',
        'provider' => 'المزوّد',
        'phone_number_id' => 'معرّف رقم الهاتف',
        'business_account_id' => 'معرّف حساب الأعمال',
        'booking' => 'حجز واتساب',
        'credentials' => 'بيانات الاعتماد',
        'configured' => 'آخر إعداد',
        'configured_by' => ':name · :time',
        'last_sent' => 'آخر رسالة مرسلة',
        'last_error' => 'آخر خطأ من المزوّد',
        'turned_on' => 'تم تشغيل حجز واتساب.',
        'turned_off' => 'تم إيقاف حجز واتساب. لن تُستقبل الرسائل الجديدة.',
    ],

    'credentials' => [
        'configured' => 'مُعدّ',
        'not_configured' => 'غير مُعدّ',
        'unreadable' => 'لم يعد بالإمكان قراءة بيانات الاعتماد المحفوظة. أدخلها مرة أخرى.',
        'fields' => [
            'access_token' => 'رمز الوصول',
            'app_secret' => 'سر التطبيق',
            'verify_token' => 'رمز التحقق',
        ],
        'tips' => [
            'verify_token' => 'عبارة تختارها بنفسك. أدخل العبارة نفسها في إعدادات الويب هوك في تطبيق Meta الخاص بك.',
        ],
    ],

    'webhook' => [
        'title' => 'الويب هوك',
        'states' => [
            'verified' => 'تم التحقق',
            'pending' => 'لم يتم التحقق بعد',
            'failing' => 'الإشعارات مرفوضة',
        ],
        'url' => 'رابط الاستدعاء',
        'url_tip' => 'الصق هذا الرابط ورمز التحقق في إعدادات الويب هوك في تطبيق Meta الخاص بك.',
        'copy' => 'نسخ رابط الاستدعاء',
        'verify_token' => 'رمز التحقق',
        'set' => 'مُعيّن',
        'not_set' => 'غير مُعيّن',
        'handshake' => 'تحقق واتساب',
        'last_inbound' => 'آخر إشعار مستلم',
        'last_rejected' => 'آخر إشعار مرفوض',
    ],

    'check' => [
        'title' => 'فحص الإعداد',
        'note' => 'يفحص ما سجّله Meta Style فقط، ولا يتصل بواتساب.',
        'checked_at' => 'تم الفحص الساعة :time',
        'states' => [
            'ready' => 'جاهز',
            'waiting' => 'بانتظار واتساب',
            'problem' => 'مشكلة في الإعداد',
            'not_connected' => 'غير متصل',
        ],
        'item_states' => [
            'ok' => 'ناجح',
            'problem' => 'مشكلة',
            'pending' => 'قيد الانتظار',
            'unknown' => 'لا توجد نتيجة بعد',
        ],
        'result' => [
            'ready' => 'جاهز. كل ما يفحصه Meta Style مكتمل.',
            'waiting' => 'أوشك على الجاهزية. لم يتحقق واتساب من الويب هوك بعد.',
            'not_connected' => 'لم يتم ربط واتساب بعد.',
            'problem' => 'مشكلة في الإعداد: :item.',
        ],
    ],

    'checks' => [
        'channel' => 'مشمول في خطتك',
        'provider' => 'مزوّد الرسائل',
        'phone_number_id' => 'معرّف رقم الهاتف',
        'credentials' => 'بيانات الاعتماد',
        'enabled' => 'حجز واتساب مفعّل',
        'webhook' => 'الويب هوك',
        'delivery' => 'آخر رسالة مرسلة',
        'provider_error' => 'خطأ من المزوّد',
    ],

    'problems' => [
        'channel_inactive' => 'خطتك لا تشمل حجز واتساب',
        'provider_unavailable' => 'مزوّد الرسائل غير متاح',
        'phone_number_id_missing' => 'معرّف رقم الهاتف مفقود',
        'phone_number_id_invalid' => 'معرّف رقم الهاتف غير صالح',
        'credentials_missing' => 'بيانات الاعتماد غير محفوظة',
        'credentials_incomplete' => 'بيانات الاعتماد غير مكتملة',
        'credentials_unreadable' => 'لم يعد بالإمكان قراءة بيانات الاعتماد المحفوظة',
        'account_disabled' => 'حجز واتساب متوقف',
        'webhook_not_verified' => 'لم يتم التحقق بعد',
        'signature_failures' => 'الإشعارات مرفوضة — تحقّق من سر التطبيق',
        'last_send_failed' => 'لم تُرسل آخر رسالة',
        'last_send_unknown' => 'لم يتأكد التسليم بعد',
        'nothing_sent' => 'لم يُرسل شيء بعد',
        'provider_error' => 'أبلغ واتساب عن خطأ',
        'unknown' => 'مشكلة غير معروفة',
    ],

    'delivery' => [
        'sent' => 'أُرسلت',
        'failed' => 'لم تُرسل',
        'unknown' => 'غير مؤكد',
        'pending' => 'قيد الإرسال',
    ],

    'form' => [
        'title_new' => 'ربط واتساب',
        'title_edit' => 'تعديل الربط',
        'display_name' => 'الاسم',
        'display_phone_number' => 'رقم واتساب',
        'phone_number_id' => 'معرّف رقم الهاتف',
        'business_account_id' => 'معرّف حساب الأعمال',
        'credentials' => 'بيانات الاعتماد',
        'secret_note' => 'تُحفظ مشفّرة ولا تُعرض مرة أخرى.',
        'replace' => 'استبدال بيانات الاعتماد',
        'keep' => 'الإبقاء على بيانات الاعتماد المحفوظة',
        'retype' => 'لأمانك، أدخل بيانات الاعتماد مرة أخرى.',
        'enabled' => 'تشغيل حجز واتساب',
        'save' => 'حفظ الربط',
        'saved' => 'تم حفظ الربط.',
        'saved_with_credentials' => 'تم حفظ الربط واستبدال بيانات الاعتماد.',
    ],

    'assistant' => [
        'title' => 'المساعد ريان',
        'enabled' => 'السماح لريان بالرد على واتساب',
        'off_note' => 'عند الإيقاف، تذهب المحادثات الجديدة إلى فريقك.',
        'unavailable' => 'ريان غير متاح بعد. تذهب المحادثات إلى فريقك.',
        'model' => 'النموذج',
        'default_model' => 'الافتراضي (:model)',
        'default_model_plain' => 'الافتراضي',
        'tone' => 'الأسلوب',
        'tone_tip' => 'كيف يتحدث ريان. لا يمكنه تغيير قواعد الحجز أو الأسعار أو الصلاحيات.',
        'tone_placeholder' => 'ودود ومختصر. خاطب العملاء بلباقة.',
        'save' => 'حفظ إعدادات المساعد',
        'saved' => 'تم حفظ إعدادات المساعد.',
        'read_only' => 'يمكن فقط لمن يملك صلاحية إدارة المساعد تغيير ذلك.',
    ],

    'flow' => [
        'title' => 'ما يقوم به بوت الحجز',
        'team_title' => 'فريقك',
        'assistant_title' => 'ريان',
        'locked' => 'غير مشمول في الخطة',
        'team' => [
            'inbox' => 'تُحفظ كل رسالة من العملاء في المحادثات',
            'takeover' => 'يمكن لفريقك تولّي أي محادثة',
            'reply' => 'تُرسل الردود من رقم واتساب الخاص بك',
        ],
        'assistant' => [
            'branches' => 'يعرض فروعك',
            'services' => 'يشرح الخدمات والأسعار والمدد والإضافات',
            'slots' => 'يجد الأوقات المتاحة',
            'book' => 'يحجز وفق قواعد الحجز لديك',
            'reschedule' => 'ينقل الحجز إلى وقت جديد',
            'cancel' => 'يلغي الحجز',
            'own_bookings' => 'يستعرض حجوزات العميل نفسه',
            'language' => 'يرد بلغة العميل المحفوظة أو بلغتك الأساسية',
            'handoff' => 'يسلّم المحادثة لفريقك عندما لا يستطيع إكمالها',
        ],
    ],

    'languages' => [
        'title' => 'اللغات',
        'primary' => 'الأساسية',
        'change' => 'تغيير اللغات',
    ],

    // تأكيدات حجز الضيوف (docs/25-WHATSAPP.md §22).
    'confirmations' => [
        'title' => 'تأكيدات الحجز',
        'guest_toggle' => 'إرسال تأكيد الحجز للعملاء الضيوف عبر واتساب',
        'guest_tip' => 'الضيوف هم العملاء الذين ليس لديهم حساب في التطبيق. يحتفظ العملاء المسجلون بإشعارهم داخل التطبيق.',
        'states' => [
            'active' => 'يُرسَل',
            'blocked' => 'لا يُرسَل',
            'off' => 'متوقف',
        ],
        'blockers' => [
            'channel_inactive' => 'واتساب غير مشمول في خطتك.',
            'not_connected' => 'واتساب غير متصل.',
            'account_off' => 'واتساب متوقف.',
            'provider_unavailable' => 'مزوّد الرسائل غير متاح.',
            'no_template' => 'لم يُعَدّ قالب تأكيد معتمد بعد.',
            'template_language' => 'لا يمكن الإرسال عبر واتساب بأيٍّ من لغاتك بعد.',
        ],
        'not_sending' => 'لا يُرسَل: :reason',
        'template' => 'القالب',
        'template_missing' => 'غير مُعَدّ',
        'language' => 'اللغة',
        'language_value' => 'لغة العميل، وإلا :primary',
        'language_none' => 'لا توجد لغة متاحة',
        'language_unavailable' => 'غير متاحة على واتساب',
        'recent' => 'آخر :days يومًا',
        'counts' => [
            'sent' => 'أُرسل',
            'failed' => 'فشل',
            'unconfirmed' => 'غير مؤكد',
            'skipped' => 'لم يُرسل',
        ],
        'last_issue' => 'آخر مشكلة',
        'issue_states' => [
            'failed' => 'لم يُسلَّم',
            'unknown' => 'التسليم غير مؤكد',
            'pending' => 'قيد الإرسال',
            'skipped' => 'لم يُرسل',
        ],
        'reasons' => [
            'disabled' => 'متوقف من الإعدادات',
            'opted_out' => 'العميل ألغى الاشتراك في رسائل الحجز',
            'no_phone' => 'لا يوجد رقم جوال صالح',
            'no_reference' => 'لا يوجد رقم حجز',
            'not_connected' => 'واتساب غير متصل',
            'account_off' => 'واتساب متوقف',
            'provider_unavailable' => 'المزوّد غير متاح',
            'no_template' => 'لا يوجد قالب معتمد',
            'template_language' => 'اللغة غير متاحة على واتساب',
            'rate_limited' => 'تم بلوغ حد الإرسال',
            'not_configured' => 'واتساب غير مُعَدّ',
            'error' => 'تعذّر الإرسال',
        ],
        'read_only' => 'يمكن فقط لمن يدير واتساب تغيير هذا.',
        'saved_on' => 'تم تشغيل تأكيدات الحجز للضيوف.',
        'saved_off' => 'تم إيقاف تأكيدات الحجز للضيوف.',
    ],

    'errors' => [
        'not_connected' => 'اربط واتساب أولًا.',
        'entitlement' => 'خطتك لا تشمل حجز واتساب.',
        'assistant_entitlement' => 'خطتك لا تشمل المساعد ريان.',
        'forbidden' => 'ليست لديك صلاحية لتغيير ذلك.',
        'phone_number_id' => 'معرّف رقم الهاتف هذا غير صالح.',
        'provider' => 'مزوّد الرسائل غير متاح.',
        'not_configured' => 'احفظ بيانات الاعتماد قبل تشغيل حجز واتساب.',
        'assistant_invalid' => 'لا يمكن استخدام هذا النموذج أو الأسلوب.',
        'generic' => 'تعذّر الحفظ. حاول مرة أخرى.',
    ],
];
