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
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaCollection;
use App\Kernel\Storage\MediaStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Validates an uploaded image, stores it, and records it.
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
    ): MediaItem {
        if (! $actingUser->hasPermission(Permission::MediaUpload)) {
            throw new AuthorizationException('You may not upload media.');
        }

        $dimensions = $this->validate($file, $owner, $ownerId);

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
            throw ValidationException::withMessages(['file' => 'The upload did not complete.']);
        }

        if ($file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => sprintf('Images must be %d MB or smaller.', intdiv($maxBytes, 1024 * 1024)),
            ]);
        }

        $path = $file->getRealPath();

        if ($path === false) {
            throw ValidationException::withMessages(['file' => 'The upload could not be read.']);
        }

        // The authority on what this file IS. A renamed executable fails here,
        // whatever its extension or declared type claims.
        $info = @getimagesize($path);

        if ($info === false) {
            throw ValidationException::withMessages(['file' => 'That file is not an image.']);
        }

        [$width, $height] = $info;
        $mime = $info['mime'];

        if (! in_array($mime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => 'Images must be JPEG, PNG or WebP.',
            ]);
        }

        if ($width > $maxDimension || $height > $maxDimension) {
            throw ValidationException::withMessages([
                'file' => "Images must be at most {$maxDimension} pixels on each side.",
            ]);
        }

        $existing = MediaItem::query()->for($owner, $ownerId)->count();

        if ($existing >= $owner->maxItems()) {
            throw ValidationException::withMessages([
                'file' => sprintf('A %s may have at most %d image(s).', $owner->value, $owner->maxItems()),
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
            default => 'jpg',
        };
    }
}
