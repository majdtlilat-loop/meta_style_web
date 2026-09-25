<?php

declare(strict_types=1);

return [
    'appointment_status' => [
        'booked' => 'محجوز',
        'confirmed' => 'مؤكد',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغى',
        'no_show' => 'لم يحضر',
    ],
    'pos_payment_method' => [
        'cash' => 'نقدًا',
        'manual_electronic' => 'بطاقة / تحويل',
        'gateway' => 'دفع إلكتروني',
        'bank_transfer' => 'تحويل بنكي',
        'card' => 'بطاقة',
    ],
    'ticket_state' => [
        'waiting' => 'بالانتظار',
        'called' => 'تم النداء',
        'serving' => 'قيد الخدمة',
        'held' => 'معلّق',
        'completed' => 'مكتمل',
        'cancelled' => 'ملغى',
    ],
    'journey_status' => [
        'active' => 'قيد التنفيذ',
        'completed' => 'مكتملة',
        'aborted' => 'متوقفة',
    ],
    'access_level' => [
        'full' => 'وصول كامل',
        'read_only' => 'قراءة فقط',
        'limited' => 'محدود',
        'suspended' => 'معلّق',
    ],

    'payment_status' => [
        'pending' => 'قيد الانتظار',
        'succeeded' => 'مدفوع',
        'failed' => 'فشل',
        'cancelled' => 'ملغى',
    ],

    'stage_status' => [
        'waiting' => 'في الانتظار',
        'in_service' => 'قيد الخدمة',
        'completed' => 'مكتملة',
        'skipped' => 'متخطّاة',
    ],
];
