<?php

/*
|--------------------------------------------------------------------------
| Language Registry (Phase 1 seed)
|--------------------------------------------------------------------------
|
| Direction is a property of the language, declared here — never a hardcoded
| list of RTL locales scattered through Blade or PHP (docs/07-LOCALIZATION.md
| §10). Adding a language is a row here plus translation files.
|
| From Phase 2 the authoritative registry lives in the control-plane
| `languages` table and this file becomes its fallback/seed. The lookup API
| does not change.
|
| Note: Kurdish Sorani is `ckb` (ISO 639-3). `ku` is the macrolanguage and is
| commonly read as Kurmanji — Latin script, LTR — which would give the wrong
| direction and the wrong font stack.
|
*/

return [

    'fallback' => 'en',

    'languages' => [
        'en' => [
            'name_native' => 'English',
            'name_en' => 'English',
            'short_label' => 'EN',
            'icon' => 'language-en',
            'direction' => 'ltr',
        ],
        'ar' => [
            'name_native' => 'العربية',
            'name_en' => 'Arabic',
            'short_label' => 'AR',
            'icon' => 'language-ar',
            'direction' => 'rtl',
        ],
        'ckb' => [
            'name_native' => 'کوردی سۆرانی',
            'name_en' => 'Kurdish (Sorani)',
            'short_label' => 'KU',
            'icon' => 'language-ku',
            'direction' => 'rtl',
        ],
    ],

];
