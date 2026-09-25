<?php

declare(strict_types=1);

/*
 * الإدارة › الخدمات — مكتبة الخدمات.
 */
return [
    'title' => 'الخدمات',

    'summary' => [
        'services' => '{0} لا توجد خدمات|{1} خدمة واحدة|{2} خدمتان|[3,10] :count خدمات|[11,*] :count خدمة',
        'categories' => '{0} لا توجد فئات|{1} فئة واحدة|{2} فئتان|[3,10] :count فئات|[11,*] :count فئة',
    ],

    'actions' => [
        'add_service' => 'إضافة خدمة',
        'add_category' => 'إضافة فئة',
        'duplicate' => 'نسخ',
        'move_to_category' => 'نقل إلى فئة…',
        'hide' => 'إخفاء من القائمة',
        'show' => 'إظهار في القائمة',
        'online_on' => 'السماح بالحجز عبر الإنترنت',
        'online_off' => 'إيقاف الحجز عبر الإنترنت',
    ],

    'categories' => [
        'title' => 'الفئات',
        'all' => 'كل الخدمات',
        'uncategorised' => 'بدون فئة',
        'drag' => 'اسحب لإعادة ترتيب :name',
        'actions' => 'إجراءات إضافية لـ :name',
        'edit' => 'تعديل الفئة',
        'archive_title' => 'أرشفة الفئة',
        'archive_confirm' => '{0} أرشفة «:name»؟ ستختفي من القائمة.|{1} أرشفة «:name»؟ تبقى خدمتها الوحيدة وتصبح بدون فئة.|[2,*] أرشفة «:name»؟ تبقى خدماتها (:count) وتصبح بدون فئة.',
        'archived' => 'الفئات المؤرشفة (:count)',
    ],

    'ordering' => [
        'drag_hint' => 'اسحب، أو استخدم مفاتيح الأسهم',
        'move_up' => 'نقل :name إلى الأعلى',
        'move_down' => 'نقل :name إلى الأسفل',
        'categories_hint' => 'اسحب خدمة إلى فئة لنقلها إليها.',
        'filtered' => 'امسح البحث والمرشحات لإعادة ترتيب الخدمات.',
    ],

    'states' => [
        'hidden' => 'مخفية',
        'hidden_hint' => 'لا تظهر في القائمة العامة',
        'offline' => 'بدون حجز إلكتروني',
        'offline_hint' => 'لا يمكن للعملاء حجزها عبر الإنترنت',
        'on_menu' => 'في القائمة',
    ],

    'filters' => [
        'label' => 'البحث عن الخدمات',
        'search' => 'ابحث في الخدمات',
        'status' => 'الحالة',
        'status_all' => 'الكل',
        'visibility' => 'الظهور',
        'visibility_any' => 'أي ظهور',
        'visibility_public' => 'في القائمة',
        'visibility_hidden' => 'مخفية من القائمة',
        'visibility_online' => 'قابلة للحجز عبر الإنترنت',
        'visibility_offline' => 'غير قابلة للحجز عبر الإنترنت',
    ],

    'empty' => [
        'library_title' => 'ابنِ مكتبة خدماتك',
        'no_matches_title' => 'لا توجد خدمات مطابقة',
    ],

    'sections' => [
        'empty' => 'لا توجد خدمات هنا بعد.',
        'empty_drop' => 'لا توجد خدمات هنا بعد — أضف خدمة أو اسحب واحدة إلى هنا.',
    ],

    'move' => [
        'title' => 'نقل «:name»',
        'target' => 'الفئة',
        'current' => 'الحالية',
        'submit' => 'نقل',
    ],

    'services' => [
        'variations' => '{1} خيار واحد|{2} خياران|[3,10] :count خيارات|[11,*] :count خيارًا',
        'from' => 'ابتداءً من',
        'drag' => 'اسحب لإعادة ترتيب :name',
        'edit' => 'تعديل :name',
        'actions' => 'إجراءات إضافية لـ :name',
        'archive_title' => 'أرشفة الخدمة',
        'archive_confirm' => 'أرشفة «:name»؟ ستختفي من القائمة والحجوزات، وتبقى في الفواتير السابقة.',
        'restore_hint' => 'تعود غير نشطة ومخفية، لتتحقق من سعرها أولًا.',
        'copy_name' => ':name (نسخة)',
    ],

    'duration' => [
        'minutes' => ':count د',
        'hours' => ':count س',
        'hours_minutes' => ':hours س :minutes د',
    ],

    'notices' => [
        'created' => 'تمت إضافة الخدمة.',
        'created_add_photos' => 'تمت إضافة الخدمة. يمكنك إضافة الصور الآن أو الإغلاق.',
        'saved' => 'تم حفظ الخدمة.',
        'duplicated' => 'تم إنشاء نسخة — غير نشطة ومخفية حتى تراجعها.',
        'activated' => 'تم تفعيل الخدمة.',
        'deactivated' => 'تم إيقاف الخدمة.',
        'shown' => 'الخدمة ظاهرة في القائمة.',
        'hidden' => 'الخدمة مخفية من القائمة.',
        'online_on' => 'تم السماح بالحجز عبر الإنترنت.',
        'online_off' => 'تم إيقاف الحجز عبر الإنترنت.',
        'archived' => 'تمت أرشفة الخدمة.',
        'restored' => 'تمت استعادة الخدمة — غير نشطة ومخفية.',
        'moved' => 'تم نقل الخدمة.',
        'category_created' => 'تمت إضافة الفئة.',
        'category_created_image' => 'تمت إضافة الفئة. يمكنك إضافة صورتها الآن أو الإغلاق.',
        'category_saved' => 'تم حفظ الفئة.',
        'category_archived' => 'تمت أرشفة الفئة. أصبحت خدماتها بدون فئة.',
        'category_restored' => 'تمت استعادة الفئة — غير نشطة ومخفية.',
    ],

    'errors' => [
        'not_found' => 'هذا العنصر لم يعد موجودًا. تم تحديث القائمة.',
        'name' => 'أدخل اسمًا.',
        'variation_name' => 'أدخل اسمًا لهذا الخيار.',
        'duration' => 'أدخل مدة بين 1 و1440 دقيقة.',
        'price_range' => 'هذا السعر خارج النطاق المسموح.',
        'branches' => 'اختر فرعًا واحدًا على الأقل، أو اجعلها متاحة في كل الفروع.',
        'department' => 'هذا القسم لم يعد متاحًا.',
        'category' => 'هذه الفئة مؤرشفة أو لم تعد متاحة.',
        'service_archived' => 'هذه الخدمة مؤرشفة. استعدها أولًا.',
        'requirements' => 'راجع متطلبات الموارد: كل نوع مرة واحدة، وكمية من 1 إلى 255، والأنواع النشطة فقط.',
        'media_invalid' => 'استخدم صورة JPEG أو PNG أو WebP لا تتجاوز 5 ميغابايت و4000 بكسل لكل ضلع.',
        'media_limit' => 'وصلت إلى الحد الأقصى للصور.',
    ],

    'fields' => [
        'name' => 'الاسم',
        'summary' => 'وصف قصير',
        'description' => 'الوصف',
        'price' => 'السعر',
        'duration' => 'المدة',
        'category' => 'الفئة',
        'department' => 'القسم',
        'active' => 'نشطة',
        'public' => 'إظهار في القائمة العامة',
        'online' => 'قابلة للحجز عبر الإنترنت',
        'all_branches' => 'متاحة في كل الفروع',
        'branches' => 'الفروع',
        'variation_name' => 'اسم الخيار',
        'resource_type' => 'نوع المورد',
        'quantity' => 'الكمية',
        'photo' => 'الصورة',
    ],

    'editor' => [
        'create_title' => 'خدمة جديدة',
        'edit_title' => 'تعديل :name',
        'create' => 'إنشاء الخدمة',
        'minutes' => 'دقيقة',
        'sections' => [
            'details' => 'التفاصيل',
            'price' => 'السعر والمدة',
            'availability' => 'التوفر',
            'team' => 'من يمكنه تقديمها',
            'variations' => 'الخيارات',
            'photos' => 'الصور',
            'resources' => 'متطلبات الموارد',
        ],
        'department_help' => 'يوجّه العمل — الطابور والغرف ومسار الزيارة.',
        'active_help' => 'لا يمكن بيع الخدمات غير النشطة أو حجزها.',
        'public_help' => 'يمكن بيع الخدمات المخفية في نقطة البيع.',
        'online_locked' => 'خطتك لا تتضمن الحجز الإلكتروني بعد؛ يُحفظ هذا الخيار لحين توفره.',
        'no_staff' => 'لا يوجد موظفون نشطون بعد.',
        'add_variation' => 'إضافة خيار',
        'drag_variation' => 'اسحب لإعادة ترتيب الخيار :number',
        'move_variation_up' => 'نقل الخيار :number إلى الأعلى',
        'move_variation_down' => 'نقل الخيار :number إلى الأسفل',
        'remove_variation' => 'حذف الخيار :number',
        'follows' => 'يتبع الخدمة (:value)',
        'photos_after_save' => 'أنشئ الخدمة أولًا، ثم أضف هنا حتى ثماني صور.',
        'add_requirement' => 'إضافة متطلب',
        'no_requirements' => 'لا تحتاج إلى موارد.',
        'choose_resource' => 'اختر نوع المورد',
        'remove_requirement' => 'حذف المتطلب',
    ],

    'category_editor' => [
        'create_title' => 'فئة جديدة',
        'edit_title' => 'تعديل :name',
        'create' => 'إنشاء الفئة',
        'active_help' => 'تبقى الفئات غير النشطة في المكتبة وتختفي من القائمة.',
        'image' => 'الصورة',
        'image_after_save' => 'أنشئ الفئة أولًا، ثم أضف صورتها هنا.',
        'archive_hint' => 'ستختفي من القائمة. تبقى خدماتها وتصبح بدون فئة.',
        'archive_confirm' => 'أرشفة هذه الفئة؟ تبقى خدماتها وتصبح بدون فئة.',
    ],

    'media' => [
        'add_photos' => 'إضافة صور',
        'add_image' => 'إضافة صورة',
        'replace_image' => 'استبدال الصورة',
        'rules_gallery' => 'JPEG أو PNG أو WebP · حتى 5 ميغابايت · حتى :count صور',
        'rules_single' => 'JPEG أو PNG أو WebP · حتى 5 ميغابايت',
        'uploading' => 'جارٍ الرفع…',
        'uploaded' => '{1} تمت إضافة الصورة.|{2} تمت إضافة صورتين.|[3,*] تمت إضافة :count صور.',
        'removed' => 'تم حذف الصورة.',
        'remove' => 'حذف الصورة :number',
        'remove_title' => 'حذف الصورة',
        'remove_confirm' => 'حذف هذه الصورة؟ ستُحذف نهائيًا.',
        'cover' => 'الغلاف',
        'make_cover' => 'استخدام الصورة :number كغلاف',
        'make_cover_short' => 'استخدام كغلاف',
        'drag' => 'اسحب لإعادة ترتيب الصورة :number',
        'move_earlier' => 'تقديم الصورة :number',
        'move_later' => 'تأخير الصورة :number',
        'limit' => 'يمكن أن تحتوي الخدمة على :count صور كحد أقصى.',
        'full' => 'كل أماكن الصور (:count) مستخدمة. احذف صورة لإضافة أخرى.',
        'none' => 'لا توجد صور بعد.',
    ],

    'quick' => [
        'label' => 'إضافة سريعة',
        'name' => 'خدمة جديدة في :category',
        'submit' => 'إضافة',
    ],
];
