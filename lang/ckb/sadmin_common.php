<?php

return [
    'actions' => ['edit' => 'دەستکاری', 'cancel' => 'هەڵوەشاندنەوە'],
    'fields' => ['center' => 'سەنتەر', 'plan' => 'پلان', 'status' => 'دۆخ', 'reason' => 'هۆکار', 'required_reason' => 'هۆکار پێویستە'],
    'filters' => ['status' => 'پاڵاوتن بە دۆخ', 'all_statuses' => 'هەموو دۆخەکان'],
    'periods' => ['monthly' => 'مانگانە', 'quarterly' => 'سێ مانگانە', 'yearly' => 'ساڵانە'],
    'statuses' => [
        'active' => 'چالاک', 'inactive' => 'ناچالاک', 'trialing' => 'تاقیکردنەوە', 'past_due' => 'دواکەوتوو', 'suspended' => 'ڕاگیراو',
        'cancelled' => 'هەڵوەشاوە', 'expired' => 'بەسەرچوو', 'open' => 'کراوە', 'settled' => 'پارەدراو', 'void' => 'پوچەڵ',
        'healthy' => 'ساغ', 'warning' => 'ئاگاداری', 'critical' => 'مەترسیدار', 'normal' => 'ئاسایی', 'unlimited' => 'بێسنوور',
    ],
];
