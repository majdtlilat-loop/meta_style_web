<?php

declare(strict_types=1);

return [
    'appointment_status' => [
        'booked' => 'نۆرە گیراوە',
        'confirmed' => 'پشتڕاستکراوە',
        'completed' => 'تەواوبوو',
        'cancelled' => 'هەڵوەشاوە',
        'no_show' => 'ئامادە نەبوو',
    ],
    'pos_payment_method' => [
        'cash' => 'نەختینە',
        'manual_electronic' => 'کارت / گواستنەوە',
        'gateway' => 'ئۆنلاین',
        'bank_transfer' => 'گواستنەوەی بانکی',
        'card' => 'کارت',
    ],
    'ticket_state' => [
        'waiting' => 'چاوەڕوان',
        'called' => 'بانگکراوە',
        'serving' => 'لە خزمەتدایە',
        'held' => 'ڕاگیراو',
        'completed' => 'تەواوبوو',
        'cancelled' => 'هەڵوەشاوە',
    ],
    'journey_status' => [
        'active' => 'لە جێبەجێکردندایە',
        'completed' => 'تەواوبوو',
        'aborted' => 'وەستێنراو',
    ],
    'access_level' => [
        'full' => 'دەستگەیشتنی تەواو',
        'read_only' => 'تەنها خوێندنەوە',
        'limited' => 'سنووردار',
        'suspended' => 'ڕاگیراو',
    ],

    'payment_status' => [
        'pending' => 'چاوەڕوان',
        'succeeded' => 'دراوە',
        'failed' => 'سەرکەوتوو نەبوو',
        'cancelled' => 'هەڵوەشاوە',
    ],

    'stage_status' => [
        'waiting' => 'چاوەڕوان',
        'in_service' => 'لە خزمەتدایە',
        'completed' => 'تەواوبوو',
        'skipped' => 'تێپەڕێندراو',
    ],
];
