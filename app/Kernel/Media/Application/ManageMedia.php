<?php

declare(strict_types=1);

namespace App\Kernel\Media\Application;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Media\MediaOwner;
use App\Kernel\Media\Models\MediaItem;
use App\Kernel\Storage\MediaStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Deleting and reordering stored media.
 *
 * Deletion removes the ROW AND THE FILE together. A row without a file renders
 * a broken image; a file without a row is storage nobody will ever reclaim,
 * because nothing knows it exists. Doing one without the other is the common
 * bug in media handling and it is only ever noticed months later.
 */
final class ManageMedia
{
    public function __construct(
        private readonly MediaStore $store,
        private readonly Audit $audit,
    ) {}

    public function delete(MediaItem $item, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::MediaUpload)) {
            throw new AuthorizationException('You may not manage media.');
        }

        $uuid = $item->uuid;
        $owner = $item->owner_type->value.'#'.$item->owner_id;

        $item->purge($this->store);

        $this->audit->record(new AuditEvent(
            action: 'media.item.deleted',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: MediaItem::class,
            targetId: $uuid,
            targetLabel: $owner,
        ));
    }

    /**
     * Replaces one image with another, keeping its position.
     *
     * Implemented as delete-then-store rather than overwriting the stored file:
     * the paths are generated and unguessable, so reusing one would mean a
     * cached or shared URL silently starts showing a different picture.
     *
     * @param  list<string>  $orderedUuids
     */
    public function reorder(MediaOwner $owner, int $ownerId, array $orderedUuids, User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::MediaUpload)) {
            throw new AuthorizationException('You may not manage media.');
        }

        DB::connection('tenant')->transaction(function () use ($owner, $ownerId, $orderedUuids): void {
            foreach ($orderedUuids as $position => $uuid) {
                // Scoped to the owner, so a uuid from another service cannot be
                // dragged into this gallery's ordering.
                MediaItem::query()
                    ->where('owner_type', $owner->value)
                    ->where('owner_id', $ownerId)
                    ->where('uuid', $uuid)
                    ->update(['sort_order' => $position]);
            }
        });

        $this->audit->record(new AuditEvent(
            action: 'media.items.reordered',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: MediaItem::class,
            targetLabel: $owner->value.'#'.$ownerId,
            after: ['count' => count($orderedUuids)],
        ));
    }
}
