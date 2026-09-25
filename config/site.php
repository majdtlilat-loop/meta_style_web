<?php

/*
|--------------------------------------------------------------------------
| The center's public site — operational limits and providers
|--------------------------------------------------------------------------
|
| What a center may put on its own public landing page is a CODE-owned
| catalog (App\Modules\CenterSite\Domain\SiteCatalog): section types, layouts,
| icons, social networks. This file holds the numbers and hosts that an
| operator may reasonably tune per deployment: how large an upload may be, how
| many site files a center may keep, and which map provider draws the map.
|
| Nothing here is ever supplied by a center. A map embed is built from the
| branch's validated coordinates and the provider host below — never from a
| URL the center typed (ADR-071 style: provider destinations are platform
| config, https only).
|
*/

return [

    'media' => [
        // Every image, video and team photo a center has uploaded for its
        // public site. Removing an image from a page only unreferences it
        // (published and archived versions may still use it).
        'max_items' => 300,

        // Images use the catalog rules (JPEG, PNG, WebP; metastyle.catalog.media).
        // Videos: MP4 or WebM only, sniffed from the container header.
        // Kept at Livewire's default temporary-upload ceiling (12 MB); raising it
        // needs livewire.temporary_file_upload.rules and PHP's upload limits too.
        'video_max_kb' => 12 * 1024,

        // Favicon: PNG or ICO, square. Never SVG.
        'favicon_max_kb' => 256,
        'favicon_min_px' => 16,
        'favicon_max_px' => 512,

        // Logos: JPEG, PNG or WebP, bounded so a header never loads a poster.
        'logo_max_kb' => 1024,
    ],

    'map' => [
        // Link-out: opens the branch's coordinates in the provider's site.
        'link' => 'https://www.openstreetmap.org/?mlat={lat}&mlon={lng}#map={zoom}/{lat}/{lng}',

        // Embed: an https iframe built ONLY from validated coordinates.
        'embed' => 'https://www.openstreetmap.org/export/embed.html?bbox={west}%2C{south}%2C{east}%2C{north}&layer=mapnik&marker={lat}%2C{lng}',

        'default_zoom' => 15,
    ],

    'rating' => [
        // The aggregate rating block stays hidden until at least this many
        // reviews count toward the average — a single review is an anecdote.
        'min_reviews' => 3,
    ],

];
