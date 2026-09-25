<?php

declare(strict_types=1);

namespace App\Modules\Queue\Application;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Queue\Domain\Models\QueueDisplay;
use App\Modules\Queue\Domain\Models\QueueDisplayMedia;
use App\Modules\Queue\Domain\Models\QueueServicePoint;
use App\Modules\Resources\Domain\Models\OperationalResource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The queue's furniture: service desks and the screens that show calls.
 *
 * Read side of `SaveServicePoint` / `SaveDisplay`, for the Manager setup tab.
 * Everything is narrowed to the viewer's branches and needs
 * `queue.display.manage` — the same permission the Actions require — so the
 * list never offers a row its Action would refuse (docs/17-QUEUE.md §§8, 9, 18).
 *
 * Arrays out, with the display's PUBLIC key only as the URL a manager opens on
 * the screen. It is never audited and never logged; it is the capability the
 * television holds, rotated by `SaveDisplay::rotate()` when it leaks.
 */
final class QueueSetupQuery
{
    /**
     * @return list<array<string, mixed>>
     *
     * @throws AuthorizationException
     */
    public function servicePoints(User $viewer, bool $archived = false): array
    {
        $this->authorize($viewer);

        $query = QueueServicePoint::query()
            ->with(['branch', 'department', 'resource'])
            ->orderBy('branch_id')
            ->orderBy('sort_order')
            ->orderBy('display_code');

        $archived ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');

        $viewer->branchScope()->applyTo($query, 'branch_id');

        return $query->get()->map(static fn (QueueServicePoint $point): array => [
            'uuid' => $point->uuid,
            'name' => (string) $point->name->get(),
            'names' => $point->name->all(),
            'code' => $point->display_code,
            'prefix' => $point->ticket_prefix,
            'branch' => $point->branch?->name->get(),
            'branch_uuid' => $point->branch?->uuid,
            'department' => $point->department?->name->get(),
            'department_uuid' => $point->department?->uuid,
            'resource' => $point->resource?->name->get(),
            'resource_uuid' => $point->resource?->uuid,
            'is_active' => $point->is_active,
            'sort_order' => $point->sort_order,
            'archived' => $point->archived_at !== null,
        ])->values()->all();
    }

    /**
     * @return list<array<string, mixed>>
     *
     * @throws AuthorizationException
     */
    public function displays(User $viewer): array
    {
        $this->authorize($viewer);

        $query = QueueDisplay::query()
            ->with(['branch', 'department', 'servicePoint'])
            ->withCount(['promoMedia', 'promoMedia as promo_playing_count' => static fn ($media) => $media->where('is_enabled', true)])
            ->whereNull('archived_at')
            ->orderBy('branch_id')
            ->orderBy('name');

        $viewer->branchScope()->applyTo($query, 'branch_id');

        return $query->get()->map(static fn (QueueDisplay $display): array => [
            'uuid' => $display->uuid,
            'name' => $display->name,
            'branch' => $display->branch?->name->get(),
            'branch_uuid' => $display->branch?->uuid,
            'scope' => $display->service_point_id !== null ? 'service_point' : ($display->department_id !== null ? 'department' : 'branch'),
            'department' => $display->department?->name->get(),
            'department_uuid' => $display->department?->uuid,
            'service_point' => $display->servicePoint?->display_code,
            'service_point_uuid' => $display->servicePoint?->uuid,
            'locale' => $display->locale,
            'recent' => $display->recentLimit(),
            'sound' => $display->sound_enabled,
            'voice' => $display->voice_enabled,
            'voice_locales' => $display->voiceLocales(),
            'is_active' => $display->is_active,
            'rotation' => $display->rotation_enabled,
            'rotation_locales' => $display->rotationLocales(),
            'rotation_seconds' => $display->rotationSeconds(),
            'promo' => $display->promo_enabled,
            'promo_seconds' => $display->slideSeconds(),
            'media_count' => (int) $display->getAttribute('promo_media_count'),
            'media_playing' => (int) $display->getAttribute('promo_playing_count'),
            'public_key' => $display->public_key,
        ])->values()->all();
    }

    /**
     * One screen's promotional media, in play order, for the Manager.
     *
     * @return list<array<string, mixed>>
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<QueueDisplay>
     */
    public function media(User $viewer, string $displayUuid): array
    {
        $display = $this->display($viewer, $displayUuid);

        $rows = $display->promoMedia()->with('mediaItem')->get();
        $last = $rows->count() - 1;

        return $rows->values()->map(static function (QueueDisplayMedia $row, int $index) use ($last): array {
            $media = $row->mediaItem;
            $video = $row->isVideo();

            return [
                'uuid' => $row->uuid,
                'kind' => $video ? 'video' : 'image',
                'url' => $media?->url(),
                'type' => $media?->mime_type,
                'size_bytes' => $media?->size_bytes,
                'dimensions' => $media?->width !== null && $media->height !== null ? $media->width.' × '.$media->height : null,
                'enabled' => $row->is_enabled,
                'caption' => $row->caption?->all() ?? [],
                'alt' => $video ? [] : ($media?->alt_text?->all() ?? []),
                // What the screen shows, with the same language fallback: a
                // caption written only in Arabic is still a caption.
                'caption_text' => (string) $row->caption?->get(),
                'alt_text' => $video ? '' : (string) $media?->alt_text?->get(),
                'first' => $index === 0,
                'last' => $index === $last,
            ];
        })->all();
    }

    /**
     * One playlist item, re-resolved through its screen: the viewer's
     * permission and branch, never a uuid taken on trust.
     *
     * @throws AuthorizationException
     * @throws ModelNotFoundException<QueueDisplay|QueueDisplayMedia>
     */
    public function mediaItem(User $viewer, string $displayUuid, string $itemUuid): QueueDisplayMedia
    {
        $display = $this->display($viewer, $displayUuid);

        $row = QueueDisplayMedia::query()
            ->where('queue_display_id', $display->getKey())
            ->where('uuid', $itemUuid)
            ->first();

        if (! $row instanceof QueueDisplayMedia) {
            throw (new ModelNotFoundException)->setModel(QueueDisplayMedia::class, [$itemUuid]);
        }

        $row->setRelation('display', $display);

        return $row;
    }

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException<QueueServicePoint>
     */
    public function point(User $viewer, string $uuid): QueueServicePoint
    {
        $this->authorize($viewer);

        $point = QueueServicePoint::query()->where('uuid', $uuid)->first();

        if (! $point instanceof QueueServicePoint || ! $viewer->canAccessBranch((int) $point->branch_id)) {
            throw (new ModelNotFoundException)->setModel(QueueServicePoint::class, [$uuid]);
        }

        return $point;
    }

    /**
     * @throws AuthorizationException
     * @throws ModelNotFoundException<QueueDisplay>
     */
    public function display(User $viewer, string $uuid): QueueDisplay
    {
        $this->authorize($viewer);

        $display = QueueDisplay::query()->where('uuid', $uuid)->whereNull('archived_at')->first();

        if (! $display instanceof QueueDisplay || ! $viewer->canAccessBranch((int) $display->branch_id)) {
            throw (new ModelNotFoundException)->setModel(QueueDisplay::class, [$uuid]);
        }

        return $display;
    }

    /**
     * Rooms and devices a desk may stand for, at one branch.
     *
     * @return list<array{uuid: string, name: string}>
     */
    public function resources(Branch $branch): array
    {
        return OperationalResource::query()
            ->bookableAt((int) $branch->getKey())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (OperationalResource $resource): array => [
                'uuid' => $resource->uuid,
                'name' => (string) $resource->name->get(),
            ])
            ->values()
            ->all();
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $viewer): void
    {
        if (! $viewer->hasPermission(Permission::QueueDisplayManage)) {
            throw new AuthorizationException('You may not manage queue displays.');
        }
    }
}
