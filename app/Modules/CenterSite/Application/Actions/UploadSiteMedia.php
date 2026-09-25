<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application\Actions;

use App\Kernel\Identity\Models\User;
use App\Kernel\Media\Application\StoreMediaItem;
use App\Kernel\Media\MediaKind;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaCollection;
use App\Modules\CenterSite\Application\SiteAccess;
use App\Modules\CenterSite\Application\SiteMedia;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Adds an image or a video to the center's site library.
 *
 * Validation is StoreMediaItem's, on the file's bytes: images JPEG, PNG or
 * WebP within the catalog limits; videos MP4 or WebM under the configured
 * cap. Never SVG, never anything executable. The new item is only referenced
 * by the draft once the builder puts its uuid in a slot; removing it from a
 * slot later only unreferences it, because published and archived versions
 * may still point at it.
 */
final class UploadSiteMedia
{
    public function __construct(private readonly StoreMediaItem $store) {}

    /**
     * @param  'image'|'video'  $kind
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function __invoke(UploadedFile $file, string $kind, User $actingUser): MediaItem
    {
        SiteAccess::ensureManage($actingUser);

        return ($this->store)(
            $file,
            MediaOwner::Site,
            SiteMedia::OWNER_ID,
            $actingUser,
            [],
            MediaCollection::Branding,
            $kind === 'video' ? MediaKind::Video : MediaKind::Image,
        );
    }
}
