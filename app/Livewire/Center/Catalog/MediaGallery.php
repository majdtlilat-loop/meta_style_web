<?php

declare(strict_types=1);

namespace App\Livewire\Center\Catalog;

use App\Kernel\Authorization\Permission;
use App\Kernel\Media\Application\ManageMedia;
use App\Kernel\Media\Application\StoreMediaItem;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Livewire\Center\Catalog\Concerns\CatalogFeedback;
use App\Modules\Catalog\Application\CatalogQuery;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * The photos of one service (a gallery) or one category (a single image).
 *
 * Every write goes through the media kernel — StoreMediaItem validates the
 * BYTES and generates the path, ManageMedia deletes the row and the file
 * together and reorders within the owner only. Nothing here builds a path.
 *
 * Reordering sends one intent (this photo to that position); the full order
 * handed to the kernel is re-derived from the owner's rows, never taken from
 * the browser. A category's single image is replaced by removing it first:
 * the kernel keeps one image per category.
 *
 * Changing a photo is changing its owner: besides the kernel's `media.upload`,
 * a service's photos need `service.create` or `service.update` and a
 * category's image needs `category.manage`.
 */
final class MediaGallery extends Component
{
    use CatalogFeedback;
    use WithFileUploads;

    /** 'service' or 'service_category'. */
    #[Locked]
    public string $owner = 'service';

    #[Locked]
    public string $ownerUuid = '';

    /** One upload, or several when the owner holds a gallery. */
    public mixed $photos = null;

    public function mount(string $owner, string $ownerUuid): void
    {
        if (! in_array($owner, [MediaOwner::Service->value, MediaOwner::ServiceCategory->value], true)) {
            abort(404);
        }

        app(CatalogQuery::class)->authorize($this->actor());

        $this->owner = $owner;
        $this->ownerUuid = $ownerUuid;
    }

    public function updatedPhotos(): void
    {
        $files = array_values(array_filter(
            is_array($this->photos) ? $this->photos : [$this->photos],
            static fn (mixed $file): bool => $file instanceof UploadedFile,
        ));
        $this->photos = null;
        $this->resetErrorBag();

        [$type, $ownerId] = $this->ownerKey();

        if ($files === [] || $ownerId === null) {
            return;
        }

        $existing = MediaItem::query()->for($type, $ownerId)->count();

        if ($type->allowsMultiple() && $existing + count($files) > $type->maxItems()) {
            $this->addError('photos', __('manager_catalog.media.limit', ['count' => $type->maxItems()]));

            return;
        }

        $stored = 0;

        foreach ($files as $file) {
            $check = Validator::make(['photo' => $file], ['photo' => [
                'image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:max_width=4000,max_height=4000',
            ]], [], ['photo' => __('manager_catalog.fields.photo')]);

            if ($check->fails()) {
                $this->addError('photos', (string) $check->errors()->first('photo'));

                continue;
            }

            $ok = $this->attempt(function () use ($file, $type, $ownerId): void {
                $this->ensureMayEditOwner();

                if (! $type->allowsMultiple()) {
                    foreach (MediaItem::query()->for($type, $ownerId)->get() as $old) {
                        app(ManageMedia::class)->delete($old, $this->actor());
                    }
                }

                app(StoreMediaItem::class)($file, $type, $ownerId, $this->actor());
            });

            $stored += $ok ? 1 : 0;
        }

        if ($stored > 0) {
            $this->flash(trans_choice('manager_catalog.media.uploaded', $stored, ['count' => $stored]));
            $this->dispatch('catalog-changed');
        }
    }

    public function removeMedia(string $mediaUuid): void
    {
        $this->attempt(function () use ($mediaUuid): void {
            $this->ensureMayEditOwner();
            app(ManageMedia::class)->delete($this->itemOrFail($mediaUuid), $this->actor());
            $this->dispatch('catalog-changed');
        }, __('manager_catalog.media.removed'));
    }

    public function moveMedia(string $mediaUuid, int $toIndex): void
    {
        $this->attempt(function () use ($mediaUuid, $toIndex): void {
            $this->ensureMayEditOwner();
            [$type, $ownerId] = $this->ownerKey();
            $item = $this->itemOrFail($mediaUuid);

            $order = MediaItem::query()->for($type, (int) $ownerId)->pluck('uuid')->map(fn ($u): string => (string) $u)->all();
            $from = (int) array_search($item->uuid, $order, true);
            array_splice($order, $from, 1);
            array_splice($order, max(0, min($toIndex, count($order))), 0, [$item->uuid]);

            app(ManageMedia::class)->reorder($type, (int) $ownerId, array_values($order), $this->actor());
            $this->dispatch('catalog-changed');
        });
    }

    public function moveMediaBy(string $mediaUuid, int $delta): void
    {
        [$type, $ownerId] = $this->ownerKey();
        $order = MediaItem::query()->for($type, (int) $ownerId)->pluck('uuid')->map(fn ($u): string => (string) $u)->all();
        $from = array_search($mediaUuid, $order, true);

        if ($from !== false) {
            $this->moveMedia($mediaUuid, $from + ($delta <=> 0));
        }
    }

    public function render(): View
    {
        [$type, $ownerId] = $this->ownerKey();

        $items = $ownerId === null ? collect() : MediaItem::query()->for($type, $ownerId)->get();

        return view('livewire.center.catalog.media-gallery', [
            'items' => $items->values()->map(fn (MediaItem $item, int $index): array => [
                'uuid' => $item->uuid,
                'url' => $item->url(),
                'size' => $item->width !== null && $item->height !== null ? $item->width.' × '.$item->height : null,
                'first' => $index === 0,
                'last' => $index === $items->count() - 1,
            ])->all(),
            'multiple' => $type->allowsMultiple(),
            'max' => $type->maxItems(),
            'full' => $type->allowsMultiple() && $items->count() >= $type->maxItems(),
            'canManage' => $this->mayEditOwner(),
        ]);
    }

    private function mayEditOwner(): bool
    {
        $user = $this->actor();

        $ownerPermission = $this->owner === MediaOwner::ServiceCategory->value
            ? $user->hasPermission(Permission::CategoryManage)
            : $user->hasPermission(Permission::ServiceUpdate) || $user->hasPermission(Permission::ServiceCreate);

        return $ownerPermission && $user->hasPermission(Permission::MediaUpload);
    }

    /**
     * @throws AuthorizationException
     */
    private function ensureMayEditOwner(): void
    {
        if (! $this->mayEditOwner()) {
            throw new AuthorizationException('You may not change these photos.');
        }
    }

    /**
     * The owner's kind and id, resolved in THIS center only.
     *
     * @return array{0: MediaOwner, 1: int|null}
     */
    private function ownerKey(): array
    {
        $type = MediaOwner::from($this->owner);
        $model = $type === MediaOwner::Service
            ? Service::query()->where('uuid', $this->ownerUuid)->first()
            : ServiceCategory::query()->where('uuid', $this->ownerUuid)->first();

        return [$type, $model?->id];
    }

    private function itemOrFail(string $mediaUuid): MediaItem
    {
        [$type, $ownerId] = $this->ownerKey();

        return MediaItem::query()->for($type, (int) $ownerId)->where('uuid', $mediaUuid)->firstOrFail();
    }
}
