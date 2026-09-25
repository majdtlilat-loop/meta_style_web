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
    'handoff_acknowledgement' => 'سوپاس بۆ نامەکەت. یەکێک لە تیمەکە بەم زووانە لێرە وەڵامت دەداتەوە.',

    'title' => 'گفتوگۆکان',
    'empty' => 'هیچ گفتوگۆیەکی کراوە نییە.',
    'waiting' => 'چاوەڕوانی کەسێکە',
    'status_ai_active' => 'یاریدەدەر',
    'status_human_requested' => 'پێویستی بە کەسێکە',
    'status_human_active' => 'لەگەڵ :name',
    'status_closed' => 'داخراو',
    'take_over' => 'وەرگرتنی گفتوگۆ',
    'return_to_assistant' => 'گەڕاندنەوە بۆ یاریدەدەر',
    'close' => 'داخستن',
    'reply' => 'وەڵام',
    'reply_placeholder' => 'وەڵامێک بنووسە…',
    'unknown_contact' => 'ژمارەی نەناسراو',
    'delivery_pending' => 'دەنێردرێت…',
    'delivery_failed' => 'نەگەیشت',
    'delivery_unknown' => 'گەیاندن دڵنیا نییە',

    // ---- Inbox screen (Manager) --------------------------------------
    'waiting_count' => ':count چاوەڕێی کەسێکن',
    'assistant_on' => 'یاریدەدەر چالاکە',
    'not_allowed' => 'ناتوانیت گفتوگۆکان ببینیت.',
    'not_allowed_hint' => 'داوای مۆڵەتی سندوقی نامەکان لە بەڕێوەبەر بکە.',
    'all_open' => 'هەموو کراوەکان',
    'filter_ai_active' => 'یاریدەدەر',
    'filter_human_requested' => 'پێویستی بە کەسێکە',
    'filter_human_active' => 'لەگەڵ تیم',
    'a_colleague' => 'هاوکارێک',
    'thread' => 'گفتوگۆ',
    'pick' => 'گفتوگۆیەک هەڵبژێرە',
    'author_customer' => 'کڕیار',
    'author_ai' => 'یاریدەدەر',
    'author_staff' => 'تیم',
    'author_system' => 'سیستەم',
    'template' => 'قاڵب: :name',
    'no_messages' => 'هێشتا هیچ نامەیەک نییە.',
    'close_confirm' => 'ئەم گفتوگۆیە دابخرێت؟ نامەیەکی نوێی کڕیار گفتوگۆیەکی نوێ دەکاتەوە.',
    'reply_help' => 'بە ناوی سەنتەرەوە دەنێردرێت. وەڵامدانەوە واتە وەرگرتنی گفتوگۆکە لە یاریدەدەر.',
    'reply_locked' => 'وەڵامدانەوە پێویستی بە واتسئاپە لە پلانەکەتدا. هێشتا دەتوانیت گفتوگۆکان بخوێنیتەوە و دایانبخەیت.',
    'sent' => 'وەڵامەکە بۆ ناردن ئامادەکرا.',
    'taken_over' => 'تۆ ئەم گفتوگۆیە بەڕێوە دەبەیت.',
    'returned' => 'گەڕێندرایەوە بۆ یاریدەدەر.',
    'closed' => 'گفتوگۆکە داخرا.',

    // ---- پشتڕاستکردنەوەی نۆرەی میوان (docs/25-WHATSAPP.md §22) ------------
    'booking_confirmation' => [
        'body' => "سڵاو :customer، نۆرەکەت لە :center (:branch) پشتڕاستکرایەوە.\nبەروار: :date\nکات: :time\nخزمەتگوزارییەکان: :services\nژمارەی نۆرە: :reference\nپەیوەندی: :contact",
        'time_range' => ':start–:end',
        'service_variation' => ':service (:variation)',
        'service_addons' => ':service + :addons',
        'service_employee' => ':service لەگەڵ :employee',
        'separator' => '، ',
        'none' => '—',
    ],
];
