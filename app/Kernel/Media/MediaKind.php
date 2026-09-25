<?php

declare(strict_types=1);

namespace App\Kernel\Media;

/**
 * What an upload must BE for the slot it is going into.
 *
 * The rules differ by kind, and they are checked on the bytes, never on the
 * filename or the client's declared type:
 *
 *   image    JPEG, PNG or WebP (config `metastyle.catalog.media`)
 *   video    MP4 or WebM, sniffed from the container header, size-capped
 *            (config `site.media.video_max_kb`)
 *   favicon  PNG or ICO, square, 16–512 px (config `site.media.favicon_*`)
 *
 * There is deliberately no SVG kind: an SVG is a document that can carry
 * script, and every one of these files is served on a guest-facing page.
 */
enum MediaKind: string
{
    case Image = 'image';
    case Video = 'video';
    case Favicon = 'favicon';
}
