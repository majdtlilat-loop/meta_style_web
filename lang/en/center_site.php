<?php

declare(strict_types=1);

return [
    'skip' => 'Skip to content',
    'nav_label' => 'Main menu',
    'languages' => 'Language',
    'menu' => 'Menu',
    'book_now' => 'Book now',
    'book' => 'Book',
    'details' => 'Details',
    'from' => 'From',
    'minutes' => ':count min',

    'days' => [
        '0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday',
        '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday',
    ],
    'hours' => ['closed' => 'Closed'],

    'footer' => [
        'contact' => 'Contact',
        'hours' => 'Opening hours',
        'links' => 'Links',
        'social' => 'Follow us',
    ],

    'social' => [
        'instagram' => 'Instagram', 'facebook' => 'Facebook', 'tiktok' => 'TikTok', 'x' => 'X', 'youtube' => 'YouTube',
        'snapchat' => 'Snapchat', 'linkedin' => 'LinkedIn', 'whatsapp' => 'WhatsApp', 'telegram' => 'Telegram', 'website' => 'Website',
    ],

    'contact' => [
        'address' => 'Address',
        'phone' => 'Phone',
        'whatsapp' => 'WhatsApp',
        'email' => 'Email',
        'directions' => 'Get directions',
        'open_map' => 'Open in maps',
        'map_of' => 'Map of :name',
    ],

    'offers' => [
        'days' => ':count days',
        'valid_days' => 'Valid for :count days',
        'benefits' => '{1} 1 benefit|[2,*] :count benefits',
        'sessions' => '{1} 1 session|[2,*] :count sessions',
    ],

    'rating' => [
        'label' => 'Rated :average out of 5',
        'count' => '{1} Based on 1 review|[2,*] Based on :count reviews',
    ],

    'preview' => [
        'title' => 'Preview',
        'help' => 'This is your saved draft. Customers see the published page.',
        'empty_title' => '“:section” is hidden on the live page',
        'empty_help' => 'It has nothing to show yet — add content, or the data it reads, to make it appear.',
    ],

    'types' => [
        'about' => 'About', 'services' => 'Services', 'featured_services' => 'Featured services', 'categories' => 'Categories',
        'team' => 'Team', 'why_us' => 'Why choose us', 'gallery' => 'Gallery', 'memberships' => 'Memberships',
        'packages' => 'Packages', 'rating' => 'Rating', 'hours' => 'Opening hours', 'branches' => 'Branches',
        'booking_cta' => 'Booking', 'contact' => 'Contact', 'map' => 'Map', 'faq' => 'Questions', 'text_media' => 'Text and media',
    ],

    // The first page a center has, before anyone opens the builder. Every
    // line is written for customers and claims nothing about the center.
    'defaults' => [
        'book_now' => 'Book now',
        'view_services' => 'View services',
        'all_services' => 'All services',
        'hero_eyebrow' => 'Welcome',
        'hero_title' => ':name',
        'hero_body' => 'Browse our services and book your visit online in a few steps.',
        'nav_services' => 'Services',
        'nav_hours' => 'Hours',
        'nav_contact' => 'Contact',
        'nav_booking' => 'Book online',
        'copyright' => ':name. All rights reserved.',
        'seo_title' => ':name',
        'seo_description' => 'Services, opening hours and online booking at :name.',
        'sections' => [
            'about' => ['title' => 'About us'],
            'services' => ['title' => 'Our services', 'subtitle' => 'Choose a service and book the time that suits you.'],
            'rating' => ['title' => 'Rated by our customers'],
            'hours' => ['title' => 'Opening hours'],
            'booking_cta' => ['title' => 'Ready for your visit?', 'subtitle' => 'Book online in a few steps.'],
            'contact' => ['title' => 'Contact us'],
            'map' => ['title' => 'Find us'],
        ],
    ],
];
