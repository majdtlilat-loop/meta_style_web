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
    | `theme` is the fallback for any value a stored menu does not carry. The
    | keys added after Phase 4 (layout, type scale, image ratio, price, CTA,
    | header, language switch, colour source) default to exactly what the
    | renderer drew before they existed, so a menu published earlier looks the
    | same until its owner changes it.
    |
    | `preset` is applied on top when an owner PICKS the template in the
    | editor: it is what makes the four feel different, and it only ever
    | reaches a live page through a publish.
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
                'layout' => 'grid',
                'type_scale' => 'medium',
                'image_ratio' => 'natural',
                'price_style' => 'inline',
                'cta_style' => 'solid',
                'hero_style' => 'plain',
                'gradient_angle' => '135',
                'language_switch' => 'pills',
                'colors_source' => 'menu',
            ],
            'preset' => ['layout' => 'stacked', 'image_ratio' => 'landscape'],
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
                'layout' => 'grid',
                'type_scale' => 'medium',
                'image_ratio' => 'natural',
                'price_style' => 'inline',
                'cta_style' => 'solid',
                'hero_style' => 'plain',
                'gradient_angle' => '135',
                'language_switch' => 'pills',
                'colors_source' => 'menu',
            ],
            'preset' => ['hero_style' => 'tint', 'price_style' => 'badge', 'cta_style' => 'pill', 'image_ratio' => 'landscape'],
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
                'layout' => 'grid',
                'type_scale' => 'medium',
                'image_ratio' => 'natural',
                'price_style' => 'inline',
                'cta_style' => 'solid',
                'hero_style' => 'plain',
                'gradient_angle' => '135',
                'language_switch' => 'pills',
                'colors_source' => 'menu',
            ],
            'preset' => ['layout' => 'stacked', 'hero_style' => 'gradient', 'price_style' => 'below', 'cta_style' => 'outline', 'image_ratio' => 'portrait', 'type_scale' => 'large'],
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
                'layout' => 'grid',
                'type_scale' => 'medium',
                'image_ratio' => 'natural',
                'price_style' => 'inline',
                'cta_style' => 'solid',
                'hero_style' => 'plain',
                'gradient_angle' => '135',
                'language_switch' => 'pills',
                'colors_source' => 'menu',
            ],
            'preset' => ['layout' => 'compact', 'hero_style' => 'gradient', 'price_style' => 'badge', 'image_ratio' => 'square', 'gradient_angle' => '180'],
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
    | The gradient is not a CSS string either: it is built from the two
    | validated colours and an angle from this list, so the only thing a center
    | chooses is which of a handful of known-safe declarations it gets.
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

            // How service cards are arranged: one per row, a responsive grid,
            // or dense rows for a long price list.
            'layout' => ['stacked', 'grid', 'compact'],

            // A multiplier on the base size, never a pixel value.
            'type_scale' => ['small', 'medium', 'large'],

            // Service and category images. `natural` keeps each image's own
            // proportions; the others crop to a fixed ratio.
            'image_ratio' => ['natural', 'landscape', 'square', 'portrait', 'hidden'],

            'price_style' => ['inline', 'badge', 'below'],
            'cta_style' => ['solid', 'outline', 'pill'],

            // The page header: plain, a soft tint of the accent, or a
            // primary → accent gradient at one of the angles below.
            'hero_style' => ['plain', 'tint', 'gradient'],
            'gradient_angle' => ['90', '135', '180'],

            // How a guest changes language when the center has more than one.
            'language_switch' => ['pills', 'menu', 'hidden'],

            // `brand` follows the center's own brand colours when it has set
            // them; `menu` keeps the colours chosen here.
            'colors_source' => ['menu', 'brand'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Sections
    |--------------------------------------------------------------------------
    |
    | The menu is an ordered list of these, each shown or hidden, and the public
    | page draws them in exactly that order.
    |
    | A section whose module does not exist is NOT listed here at all — no
    | Offers placeholder. A section that renders an empty box promising a
    | feature the center has not bought is worse than its absence, and seeding
    | fake offers to make a template look good would be inventing business data
    | (docs/13-ROADMAP.md Phase 4 §14).
    |
    */

    'sections' => [
        'hero' => ['config' => ['show_logo', 'show_tagline']],
        'categories' => ['config' => ['layout', 'show_images']],
        'featured_services' => ['config' => ['limit']],
        'all_services' => ['config' => ['group_by']],
        'employees' => ['config' => []],
        'location' => ['config' => ['show_map', 'show_hours']],
        'contact' => ['config' => ['show_whatsapp', 'show_phone', 'show_email']],
    ],

    'section_config' => [
        'layout' => ['grid', 'list', 'chips'],
        'group_by' => ['category', 'department', 'none'],
        'show_logo' => 'bool',
        'show_tagline' => 'bool',
        'show_images' => 'bool',
        'show_map' => 'bool',
        'show_hours' => 'bool',
        'show_whatsapp' => 'bool',
        'show_phone' => 'bool',
        'show_email' => 'bool',
        'limit' => 'int:1..24',
    ],

    /*
    | What a setting means when a stored section does not carry it — the
    | behaviour the renderer had before the setting existed.
    */
    'section_defaults' => [
        'hero' => ['show_logo' => true, 'show_tagline' => true],
        'categories' => ['layout' => 'grid', 'show_images' => false],
        'featured_services' => ['limit' => 6],
        'all_services' => ['group_by' => 'category'],
        'employees' => [],
        'location' => ['show_map' => true, 'show_hours' => true],
        'contact' => ['show_whatsapp' => true, 'show_phone' => true, 'show_email' => true],
    ],

    /*
    |--------------------------------------------------------------------------
    | The other guest pages: booking, cart and the policy texts
    |--------------------------------------------------------------------------
    |
    | The same discipline as the menu, for the pages a customer reaches from
    | it. Colours are `#rrggbb`, choices come from these lists (the first is
    | the default unless `defaults` names another), switches are booleans and
    | texts are plain text with a length cap, one per language. An empty text
    | means "use the built-in copy", which is translated into every language.
    |
    | The cart has no backend yet (checkout is Phase 16): its appearance styles
    | the page a customer sees today — never a fake cart.
    |
    */

    'pages' => [
        'booking' => [
            'colours' => ['primary' => '#111827', 'accent' => '#6366f1'],
            'choices' => [
                'header_style' => ['plain', 'banner', 'gradient'],
                'background' => ['auto', 'light', 'dark'],
                'steps_style' => ['numbered', 'plain'],
                'cta_style' => ['solid', 'outline', 'pill'],
            ],
            'flags' => [
                'inherit_brand' => true,
                'show_logo' => true,
                'show_prices' => true,
                'show_duration' => true,
                'show_policies' => true,
            ],
            'texts' => ['title' => 80, 'intro' => 280, 'cta_label' => 40, 'confirmation_message' => 280],
        ],
        'cart' => [
            'colours' => ['primary' => '#111827', 'accent' => '#6366f1'],
            'choices' => [
                'layout' => ['centered', 'split'],
                'summary' => ['panel', 'inline', 'hidden'],
                'background' => ['light', 'tint', 'dark'],
                'cta_style' => ['solid', 'outline', 'pill'],
            ],
            'flags' => [
                'inherit_brand' => true,
                'show_logo' => true,
            ],
            'texts' => [
                'heading' => 80,
                'intro' => 200,
                'empty_title' => 80,
                'empty_body' => 240,
                'cta_label' => 40,
                'checkout_heading' => 80,
                'checkout_body' => 240,
            ],
        ],
        'policies' => [
            'texts' => ['cancellation' => 1500, 'terms' => 3000],
        ],
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
