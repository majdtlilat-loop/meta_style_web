<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Application\Ordering\CatalogLayout;
use App\Modules\Catalog\Application\Ordering\CatalogOrdering;
use App\Modules\Catalog\Domain\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Retires a service.
 *
 * ARCHIVE, NEVER DELETE. From Phase 6 a service is referenced by appointments,
 * and from Phase 9 by invoice lines. A center that stops offering a treatment
 * still has to be able to produce last year's invoices, and a hard delete would
 * either orphan those rows or cascade the history away with them
 * (docs/13-ROADMAP.md Phase 4 §20).
 *
 * A separate permission from updating, because it is the destructive one: a
 * center may well want a senior stylist who can adjust prices but not retire a
 * service the whole business sells.
 */
final class ArchiveService
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CatalogOrdering $ordering,
    ) {}

    public function __invoke(Service $service, User $actingUser): Service
    {
        if (! $actingUser->hasPermission(Permission::ServiceArchive)) {
            throw new AuthorizationException('You may not archive services.');
        }

        $before = [
            'is_active' => $service->is_active,
            'is_public' => $service->is_public,
            'is_online_bookable' => $service->is_online_bookable,
        ];

        $service->forceFill([
            'archived_at' => Carbon::now(),
            'is_active' => false,
            'is_public' => false,
            'is_online_bookable' => false,
        ])->save();

        $this->audit->record(new AuditEvent(
            action: 'catalog.service.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Service::class,
            targetId: $service->uuid,
            targetLabel: (string) $service->name,
            before: $before,
            after: ['is_active' => false, 'is_public' => false, 'is_online_bookable' => false, 'archived_at' => $service->archived_at?->toIso8601String()],
        ));

        return $service;
    }

    /**
     * Brings an archived service back.
     *
     * Deliberately restores it INACTIVE and NON-PUBLIC. A service returning
     * from the archive should reappear where its owner can check its price
     * before customers can see it — restoring straight to the live menu is how
     * a stale price gets sold. It rejoins the library at the end of its
     * category.
     */
    public function restore(Service $service, User $actingUser): Service
    {
        if (! $actingUser->hasPermission(Permission::ServiceArchive)) {
            throw new AuthorizationException('You may not restore services.');
        }

        DB::connection('tenant')->transaction(function () use ($service): void {
            $layout = $this->ordering->lock();

            $service->forceFill(['archived_at' => null, 'is_active' => false, 'is_public' => false])->save();

            $group = (int) ($service->service_category_id ?? CatalogLayout::UNCATEGORISED);
            $layout->placeService($service, $layout->hasGroup($group) ? $group : CatalogLayout::UNCATEGORISED);
            $layout->persist();
        });

        $this->audit->record(new AuditEvent(
            action: 'catalog.service.restored',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Service::class,
            targetId: $service->uuid,
            targetLabel: (string) $service->name,
        ));

        return $service;
    }
}
