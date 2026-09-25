<?php

declare(strict_types=1);

return [
    'skip' => 'انتقل إلى المحتوى',
    'nav_label' => 'القائمة الرئيسية',
    'languages' => 'اللغة',
    'menu' => 'القائمة',
    'book_now' => 'احجز الآن',
    'book' => 'احجز',
    'details' => 'التفاصيل',
    'from' => 'ابتداءً من',
    'minutes' => ':count دقيقة',

    'days' => [
        '0' => 'الأحد', '1' => 'الاثنين', '2' => 'الثلاثاء', '3' => 'الأربعاء',
        '4' => 'الخميس', '5' => 'الجمعة', '6' => 'السبت',
    ],
    'hours' => ['closed' => 'مغلق'],

    'footer' => [
        'contact' => 'التواصل',
        'hours' => 'ساعات العمل',
        'links' => 'روابط',
        'social' => 'تابعنا',
    ],

    'social' => [
        'instagram' => 'إنستغرام', 'facebook' => 'فيسبوك', 'tiktok' => 'تيك توك', 'x' => 'إكس', 'youtube' => 'يوتيوب',
        'snapchat' => 'سناب شات', 'linkedin' => 'لينكدإن', 'whatsapp' => 'واتساب', 'telegram' => 'تيليغرام', 'website' => 'الموقع الإلكتروني',
    ],

    'contact' => [
        'address' => 'العنوان',
        'phone' => 'الهاتف',
        'whatsapp' => 'واتساب',
        'email' => 'البريد الإلكتروني',
        'directions' => 'الاتجاهات',
        'open_map' => 'فتح في الخرائط',
        'map_of' => 'خريطة :name',
    ],

    'offers' => [
        'days' => ':count يومًا',
        'valid_days' => 'صالحة لمدة :count يومًا',
        'benefits' => '{1} ميزة واحدة|{2} ميزتان|[3,10] :count مزايا|[11,*] :count ميزة',
        'sessions' => '{1} جلسة واحدة|{2} جلستان|[3,10] :count جلسات|[11,*] :count جلسة',
    ],

    'rating' => [
        'label' => 'التقييم :average من 5',
        'count' => '{1} بناءً على مراجعة واحدة|{2} بناءً على مراجعتين|[3,10] بناءً على :count مراجعات|[11,*] بناءً على :count مراجعة',
    ],

    'preview' => [
        'title' => 'معاينة',
        'help' => 'هذه مسودتك المحفوظة. يرى العملاء الصفحة المنشورة.',
        'empty_title' => '«:section» مخفي في الصفحة المنشورة',
        'empty_help' => 'لا يوجد ما يعرضه بعد — أضف محتوى أو البيانات التي يقرأها ليظهر.',
    ],

    'types' => [
        'about' => 'من نحن', 'services' => 'الخدمات', 'featured_services' => 'خدمات مميزة', 'categories' => 'الفئات',
        'team' => 'الفريق', 'why_us' => 'لماذا نحن', 'gallery' => 'معرض الصور', 'memberships' => 'العضويات',
        'packages' => 'الباقات', 'rating' => 'التقييم', 'hours' => 'ساعات العمل', 'branches' => 'الفروع',
        'booking_cta' => 'الحجز', 'contact' => 'التواصل', 'map' => 'الخريطة', 'faq' => 'الأسئلة', 'text_media' => 'نص ووسائط',
    ],

    'defaults' => [
        'book_now' => 'احجز الآن',
        'view_services' => 'عرض الخدمات',
        'all_services' => 'كل الخدمات',
        'hero_eyebrow' => 'أهلًا بك',
        'hero_title' => ':name',
        'hero_body' => 'تصفّح خدماتنا واحجز زيارتك عبر الإنترنت بخطوات قليلة.',
        'nav_services' => 'الخدمات',
        'nav_hours' => 'ساعات العمل',
        'nav_contact' => 'التواصل',
        'nav_booking' => 'احجز عبر الإنترنت',
        'copyright' => ':name. جميع الحقوق محفوظة.',
        'seo_title' => ':name',
        'seo_description' => 'الخدمات وساعات العمل والحجز عبر الإنترنت في :name.',
        'sections' => [
            'about' => ['title' => 'من نحن'],
            'services' => ['title' => 'خدماتنا', 'subtitle' => 'اختر الخدمة واحجز الوقت الذي يناسبك.'],
            'rating' => ['title' => 'تقييم عملائنا'],
            'hours' => ['title' => 'ساعات العمل'],
            'booking_cta' => ['title' => 'مستعد لزيارتك؟', 'subtitle' => 'احجز عبر الإنترنت بخطوات قليلة.'],
            'contact' => ['title' => 'تواصل معنا'],
            'map' => ['title' => 'موقعنا'],
        ],
    ],
];
