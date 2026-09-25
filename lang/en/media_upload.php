<?php

declare(strict_types=1);

/*
 * Why an uploaded file was refused (Kernel\Media\Application\StoreMediaItem).
 * Shown next to the upload field on every Manager surface that stores media.
 */
return [
    'incomplete' => 'The upload did not complete. Please try again.',
    'unreadable' => 'The upload could not be read.',
    'not_image' => 'That file is not an image.',
    'image_size' => 'Images must be :max MB or smaller.',
    'image_type' => 'Images must be JPEG, PNG or WebP.',
    'image_dimensions' => 'Images must be at most :max pixels on each side.',
    'image_limit' => '{1} This item may have at most :count image.|[2,*] This item may have at most :count images.',
    'item_limit' => '{1} This library may hold at most :count file.|[2,*] This library may hold at most :count files.',
    'video_size' => 'Videos must be :max MB or smaller.',
    'video_type' => 'Videos must be MP4 or WebM.',
    'icon_size' => 'Icons must be :max KB or smaller.',
    'icon_type' => 'Icons must be PNG or ICO.',
    'icon_square' => 'Icons must be square, between :min and :max pixels.',
];
