<?php

declare(strict_types=1);

/*
 * Why an uploaded file was refused (Kernel\Media\Application\StoreMediaItem).
 * Shown next to the upload field on every Manager surface that stores media.
 */
return [
    'incomplete' => 'لم يكتمل الرفع. يرجى المحاولة مرة أخرى.',
    'unreadable' => 'تعذّرت قراءة الملف المرفوع.',
    'not_image' => 'هذا الملف ليس صورة.',
    'image_size' => 'يجب ألا يتجاوز حجم الصورة :max ميغابايت.',
    'image_type' => 'يجب أن تكون الصورة بصيغة JPEG أو PNG أو WebP.',
    'image_dimensions' => 'يجب ألا يتجاوز كل بُعد من أبعاد الصورة :max بكسل.',
    'image_limit' => '{1} يمكن لهذا العنصر أن يحتوي على صورة واحدة كحد أقصى.|[2,*] يمكن لهذا العنصر أن يحتوي على :count صور كحد أقصى.',
    'item_limit' => '{1} يمكن لهذه المكتبة أن تحتوي على ملف واحد كحد أقصى.|[2,*] يمكن لهذه المكتبة أن تحتوي على :count ملفًا كحد أقصى.',
    'video_size' => 'يجب ألا يتجاوز حجم الفيديو :max ميغابايت.',
    'video_type' => 'يجب أن يكون الفيديو بصيغة MP4 أو WebM.',
    'icon_size' => 'يجب ألا يتجاوز حجم الأيقونة :max كيلوبايت.',
    'icon_type' => 'يجب أن تكون الأيقونة بصيغة PNG أو ICO.',
    'icon_square' => 'يجب أن تكون الأيقونة مربعة، بين :min و:max بكسل.',
];
