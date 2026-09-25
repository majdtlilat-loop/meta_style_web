<?php

declare(strict_types=1);

namespace App\Modules\CenterSite\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Identity\Models\User;
use App\Kernel\Media\Application\ManageMedia;
use App\Kernel\Media\Application\StoreMediaItem;
use App\Kernel\Media\MediaKind;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaCollection;
use App\Modules\CenterSite\Application\BrandSettings;
use App\Modules\CenterSite\Application\SiteAccess;
use App\Modules\CenterSite\Application\SiteMedia;
use App\Modules\CenterSite\Domain\CenterBrand;
use App\Modules\CenterSite\Domain\InvalidSiteContent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Uploads, replaces and removes the brand's logo (light and dark) and favicon.
 *
 * The file is validated on its BYTES by StoreMediaItem (logos: JPEG, PNG or
 * WebP; favicon: square PNG or ICO; never SVG) and stored in the center's
 * public brand library. A replaced or removed brand file is deleted with its
 * row: nothing else references a brand asset — pages always read the current
 * brand — so keeping it would be storage nobody reclaims.
 */
final class ManageBrandAssets
{
    public function __construct(
        private readonly BrandSettings $settings,
        private readonly StoreMediaItem $store,
        private readonly ManageMedia $media,
        private readonly Audit $audit,
    ) {}

    /**
     * @return array<string, mixed> the saved brand
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws InvalidSiteContent
     */
    public function upload(string $slot, UploadedFile $file, User $actingUser): array
    {
        SiteAccess::ensureManage($actingUser);
        $this->assertSlot($slot);

        if ($slot !== 'favicon') {
            $maxKb = (int) config('site.media.logo_max_kb', 1024);
            if ((int) $file->getSize() > $maxKb * 1024) {
                throw ValidationException::withMessages(['file' => __('manager_site.brand.errors.logo_size', ['max' => $maxKb])]);
            }
        }

        $item = ($this->store)(
            $file,
            MediaOwner::Brand,
            SiteMedia::OWNER_ID,
            $actingUser,
            [],
            MediaCollection::Branding,
            $slot === 'favicon' ? MediaKind::Favicon : MediaKind::Image,
        );

        $brand = $this->settings->get();
        $previous = $brand[$slot];
        $brand[$slot] = $item->uuid;
        $this->settings->put($brand);
        $this->purge($previous, $actingUser);

        $this->record('center.brand.asset_uploaded', $slot, $previous !== '', $actingUser, ['width' => $item->width, 'height' => $item->height]);

        return $brand;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     * @throws InvalidSiteContent
     */
    public function remove(string $slot, User $actingUser): array
    {
        SiteAccess::ensureManage($actingUser);
        $this->assertSlot($slot);

        $brand = $this->settings->get();
        $previous = $brand[$slot];
        if ($previous === '') {
            return $brand;
        }
        $brand[$slot] = '';
        $this->settings->put($brand);
        $this->purge($previous, $actingUser);

        $this->record('center.brand.asset_removed', $slot, true, $actingUser);

        return $brand;
    }

    private function assertSlot(string $slot): void
    {
        if (! in_array($slot, CenterBrand::ASSETS, true)) {
            throw new InvalidSiteContent('presentation', $slot);
        }
    }

    private function purge(string $uuid, User $actingUser): void
    {
        if ($uuid === '') {
            return;
        }

        /** @var MediaItem|null $item */
        $item = MediaItem::query()->for(MediaOwner::Brand, SiteMedia::OWNER_ID)->where('uuid', $uuid)->first();
        if ($item instanceof MediaItem) {
            $this->media->delete($item, $actingUser);
        }
    }

    /**
     * @param  array<string, int|null>  $details
     */
    private function record(string $action, string $slot, bool $hadCustom, User $actingUser, array $details = []): void
    {
        $this->audit->record(new AuditEvent(
            action: $action,
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: CenterBrand::class,
            targetId: 'site-brand',
            targetLabel: 'center brand · '.$slot,
            before: ['slot' => $slot, 'custom' => $hadCustom],
            after: ['slot' => $slot] + $details,
        ));
    }
}
