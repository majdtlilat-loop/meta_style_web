<?php

/*
|--------------------------------------------------------------------------
| Electronic menu presentation catalog
|--------------------------------------------------------------------------
|
| The complete set of things a center may change about how its public menu
| looks. Owned by CODE, exactly like the entitlement and permission catalogs and
| for the same reason: a value referenced by a Blade template must exist.
|
| This is deliberately a closed list. "Highly customizable" is a product goal;
| "arbitrary" is a vulnerability. A center-authored HTML block, style block or
| script on a guest-accessible page is stored XSS against that center's own
| customers, and no amount of sanitising makes accepting one a good idea. So
| there is no field anywhere that takes markup — only choices from here.
|
| Growing a template's options is a change to this file, not a migration across
| every tenant database (docs/13-ROADMAP.md Phase 4 §14).
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Templates
    |--------------------------------------------------------------------------
    |
    | Four, not forty. Each supplies a complete default theme; a center's own
    | choices override individual values. A template marketplace is a Phase 4
    | trap — the work is in the menu being correct, not in there being many.
    |
    */

    'templates' => [
        'minimal' => [
            'theme' => [
                'primary' => '#111827',
                'accent' => '#6366f1',
                'background' => 'light',
                'font' => 'sans',
                'card_style' => 'flat',
                'corners' => 'rounded',
                'density' => 'comfortable',
            ],
        ],
        'modern' => [
            'theme' => [
                'primary' => '#0f766e',
                'accent' => '#14b8a6',
                'background' => 'light',
                'font' => 'sans',
                'card_style' => 'elevated',
                'corners' => 'rounded',
                'density' => 'comfortable',
            ],
        ],
        'luxury' => [
            'theme' => [
                'primary' => '#7c2d12',
                'accent' => '#c9a227',
                'background' => 'light',
                'font' => 'serif',
                'card_style' => 'bordered',
                'corners' => 'square',
                'density' => 'spacious',
            ],
        ],
        'barber_dark' => [
            'theme' => [
                'primary' => '#e5e7eb',
                'accent' => '#f59e0b',
                'background' => 'dark',
                'font' => 'sans',
                'card_style' => 'elevated',
                'corners' => 'square',
                'density' => 'compact',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Theme options
    |--------------------------------------------------------------------------
    |
    | Colours are validated against a hex pattern; everything else must be one
    | of the listed values. A free-text font name would be a CSS injection point
    | and a broken render on any device that does not have it.
    |
    */

    'theme' => [
        'colors' => ['primary', 'accent'],
        'color_pattern' => '/^#[0-9a-fA-F]{6}$/',

        'options' => [
            'background' => ['light', 'dark'],

            // Each maps to a font stack in the layout. Arabic and Kurdish
            // Sorani need a face that actually has the glyphs, which is why
            // this is a curated list and not a text field.
            'font' => ['sans', 'serif', 'display'],

            'card_style' => ['flat', 'bordered', 'elevated'],
            'corners' => ['square', 'rounded', 'pill'],
            'density' => ['compact', 'comfortable', 'spacious'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sections
    |--------------------------------------------------------------------------
    |
    | The menu is an ordered list of these, each shown or hidden.
    |
    | `requires` names the module whose data the section renders. A section
    | whose module does not exist yet is NOT listed here at all — no Offers
    | placeholder, no Reviews placeholder. A section that renders an empty box
    | promising a feature the center has not bought is worse than its absence,
    | and seeding fake offers to make a template look good would be inventing
    | business data (docs/13-ROADMAP.md Phase 4 §14).
    |
    */

    'sections' => [
        'hero' => ['config' => ['show_logo', 'show_tagline']],
        'categories' => ['config' => ['layout']],
        'featured_services' => ['config' => ['limit']],
        'all_services' => ['config' => ['group_by']],
        'employees' => ['config' => []],
        'location' => ['config' => ['show_map']],
        'contact' => ['config' => ['show_whatsapp']],
    ],

    'section_config' => [
        'layout' => ['grid', 'list'],
        'group_by' => ['category', 'department', 'none'],
        'show_logo' => 'bool',
        'show_tagline' => 'bool',
        'show_map' => 'bool',
        'show_whatsapp' => 'bool',
        'limit' => 'int:1..24',
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    |
    | What provisioning gives a brand-new center: a working menu it can publish
    | immediately, not an empty page it has to assemble first.
    |
    */

    'default_template' => 'minimal',

    'default_sections' => [
        ['key' => 'hero', 'visible' => true, 'config' => ['show_logo' => true, 'show_tagline' => true]],
        ['key' => 'categories', 'visible' => true, 'config' => ['layout' => 'grid']],
        ['key' => 'featured_services', 'visible' => false, 'config' => ['limit' => 6]],
        ['key' => 'all_services', 'visible' => true, 'config' => ['group_by' => 'category']],
        ['key' => 'employees', 'visible' => false, 'config' => []],
        ['key' => 'location', 'visible' => true, 'config' => ['show_map' => true]],
        ['key' => 'contact', 'visible' => true, 'config' => ['show_whatsapp' => true]],
    ],

];
