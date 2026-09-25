<?php

declare(strict_types=1);

namespace App\Kernel\Media\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Kernel\Media\MediaKind;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaCollection;
use App\Kernel\Storage\MediaStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates an uploaded image, video or favicon, stores it, and records it.
 *
 * VALIDATION IS ON THE BYTES, not on what the client said. `getClientMimeType`
 * and the filename extension are both attacker-controlled: a PHP file renamed
 * `photo.jpg` arrives with a perfectly respectable client mime type. What is
 * trusted here is `getimagesize()`, which parses the actual image header, plus
 * PHP's own detected mime type (docs/08-AUDIT-SECURITY.md §12).
 *
 * Tenant isolation is inherited, not reimplemented: `MediaStore::disk()` calls
 * `TenantContext::require()`, so an upload with no tenant bound raises a named
 * error instead of landing in the shared storage root.
 */
final class StoreMediaItem
{
    public function __construct(
        private readonly MediaStore $store,
        private readonly Audit $audit,
    ) {}

    /**
     * @param  array<string, string|null>  $altText
     */
    public function __invoke(
        UploadedFile $file,
        MediaOwner $owner,
        int $ownerId,
        User $actingUser,
        array $altText = [],
        MediaCollection $collection = MediaCollection::Catalog,
        MediaKind $kind = MediaKind::Image,
    ): MediaItem {
        if (! $actingUser->hasPermission(Permission::MediaUpload)) {
            throw new AuthorizationException('You may not upload media.');
        }

        $dimensions = match ($kind) {
            MediaKind::Image => $this->validate($file, $owner, $ownerId),
            MediaKind::Video => $this->validateVideo($file, $owner, $ownerId),
            MediaKind::Favicon => $this->validateFavicon($file, $owner, $ownerId),
        };

        $path = $this->store->put(
            $collection,
            (string) file_get_contents($file->getRealPath()),
            $this->extensionFor($dimensions['mime']),
        );

        /** @var MediaItem $item */
        $item = DB::connection('tenant')->transaction(fn (): MediaItem => MediaItem::query()->create([
            'owner_type' => $owner,
            'owner_id' => $ownerId,
            'collection' => $collection,
            'path' => $path,
            'mime_type' => $dimensions['mime'],
            'size_bytes' => $file->getSize(),
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'alt_text' => $altText === [] ? null : TranslatedText::fromArray($altText),
            'sort_order' => MediaItem::query()->for($owner, $ownerId)->count(),
        ]));

        $this->audit->record(new AuditEvent(
            action: 'media.item.uploaded',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: MediaItem::class,
            targetId: $item->uuid,
            targetLabel: $owner->value.'#'.$ownerId,
            // Never the file contents, and never the stored path — the first
            // would bloat the log with binary, the second hands out a location.
            after: [
                'owner' => $owner->value,
                'mime_type' => $item->mime_type,
                'size_bytes' => $item->size_bytes,
            ],
        ));

        return $item;
    }

    /**
     * @return array{mime: string, width: int, height: int}
     *
     * @throws ValidationException
     */
    private function validate(UploadedFile $file, MediaOwner $owner, int $ownerId): array
    {
        /** @var int $maxBytes */
        $maxBytes = config('metastyle.catalog.media.max_bytes', 5 * 1024 * 1024);
        /** @var list<string> $allowedMimes */
        $allowedMimes = config('metastyle.catalog.media.mimes', []);
        /** @var int $maxDimension */
        $maxDimension = config('metastyle.catalog.media.max_dimension', 4000);

        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => __('media_upload.incomplete')]);
        }

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => __('media_upload.image_size', ['max' => intdiv($maxBytes, 1024 * 1024)]),
            ]);
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('media_upload.unreadable')]);
        }

        // The authority on what this file IS. A renamed executable fails here,
        // whatever its extension or declared type claims.
        $info = @getimagesize($path);

        if ($info === false) {
            throw ValidationException::withMessages(['file' => __('media_upload.not_image')]);
        }

        [$width, $height] = $info;
        $mime = $info['mime'];

        if (! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => __('media_upload.image_type'),
            ]);
        }

        if ($width > $maxDimension || $height > $maxDimension) {
            throw ValidationException::withMessages([
                'file' => __('media_upload.image_dimensions', ['max' => $maxDimension]),
            ]);
        }

        $existing = MediaItem::query()->for($owner, $ownerId)->count();

        if ($existing >= $owner->maxItems()) {
            throw ValidationException::withMessages([
                'file' => trans_choice('media_upload.image_limit', $owner->maxItems(), ['count' => $owner->maxItems()]),
            ]);
        }

        return ['mime' => $mime, 'width' => $width, 'height' => $height];
    }

    /**
     * Derived from the DETECTED type, never from the uploaded filename — the
     * filename is attacker input and the stored name is generated anyway.
     */
    private function extensionFor(string $mime): string
    {
        return match ($mime) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/x-icon' => 'ico',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            default => 'jpg',
        };
    }

    /**
     * An MP4 or WebM video, identified from its container header.
     *
     * `finfo` and the container signature must BOTH agree: an MP4 carries an
     * `ftyp` box at byte 4, a WebM starts with the EBML magic number. A
     * renamed script or an HTML file with a `.mp4` name fails either check.
     *
     * @return array{mime: string, width: int|null, height: int|null}
     *
     * @throws ValidationException
     */
    private function validateVideo(UploadedFile $file, MediaOwner $owner, int $ownerId): array
    {
        $maxKb = (int) config('site.media.video_max_kb', 12288);
        $path = $this->readable($file, $maxKb * 1024, (string) __('media_upload.video_size', ['max' => intdiv($maxKb, 1024)]));

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $head = (string) file_get_contents($path, false, null, 0, 16);

        $mp4 = $mime === 'video/mp4' && substr($head, 4, 4) === 'ftyp';
        $webm = $mime === 'video/webm' && str_starts_with($head, "\x1A\x45\xDF\xA3");

        if (! $mp4 && ! $webm) {
            throw ValidationException::withMessages(['file' => __('media_upload.video_type')]);
        }

        $this->assertCapacity($owner, $ownerId);

        return ['mime' => $mp4 ? 'video/mp4' : 'video/webm', 'width' => null, 'height' => null];
    }

    /**
     * A square PNG or ICO icon for the browser tab. No SVG, ever.
     *
     * @return array{mime: string, width: int, height: int}
     *
     * @throws ValidationException
     */
    private function validateFavicon(UploadedFile $file, MediaOwner $owner, int $ownerId): array
    {
        $maxKb = (int) config('site.media.favicon_max_kb', 256);
        $minSide = (int) config('site.media.favicon_min_px', 16);
        $maxSide = (int) config('site.media.favicon_max_px', 512);
        $path = $this->readable($file, $maxKb * 1024, (string) __('media_upload.icon_size', ['max' => $maxKb]));

        $info = @getimagesize($path);
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($info === false
            || ! in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_ICO], true)
            || ! in_array($mime, ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'], true)) {
            throw ValidationException::withMessages(['file' => __('media_upload.icon_type')]);
        }

        [$width, $height] = [(int) $info[0], (int) $info[1]];

        if ($width !== $height || $width < $minSide || $width > $maxSide) {
            throw ValidationException::withMessages([
                'file' => __('media_upload.icon_square', ['min' => $minSide, 'max' => $maxSide]),
            ]);
        }

        $this->assertCapacity($owner, $ownerId);

        return ['mime' => $info[2] === IMAGETYPE_ICO ? 'image/x-icon' : 'image/png', 'width' => $width, 'height' => $height];
    }

    /**
     * @throws ValidationException
     */
    private function readable(UploadedFile $file, int $maxBytes, string $tooLarge): string
    {
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => __('media_upload.incomplete')]);
        }

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages(['file' => $tooLarge]);
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('media_upload.unreadable')]);
        }

        return $path;
    }

    /**
     * @throws ValidationException
     */
    private function assertCapacity(MediaOwner $owner, int $ownerId): void
    {
        if (MediaItem::query()->for($owner, $ownerId)->count() >= $owner->maxItems()) {
            throw ValidationException::withMessages([
                'file' => trans_choice('media_upload.item_limit', $owner->maxItems(), ['count' => $owner->maxItems()]),
            ]);
        }
    }
}
