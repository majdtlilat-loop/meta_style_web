<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Domain\Models\Service;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

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
    public function __construct(private readonly Audit $audit) {}

    public function __invoke(Service $service, User $actingUser): Service
    {
        if (! $actingUser->hasPermission(Permission::ServiceArchive)) {
            throw new AuthorizationException('You may not archive services.');
        }

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
            before: ['is_active' => true],
            after: ['is_active' => false, 'archived_at' => $service->archived_at?->toIso8601String()],
        ));

        return $service;
    }

    /**
     * Brings an archived service back.
     *
     * Deliberately restores it INACTIVE and NON-PUBLIC. A service returning
     * from the archive should reappear where its owner can check its price
     * before customers can see it — restoring straight to the live menu is how
     * a stale price gets sold.
     */
    public function restore(Service $service, User $actingUser): Service
    {
        if (! $actingUser->hasPermission(Permission::ServiceArchive)) {
            throw new AuthorizationException('You may not restore services.');
        }

        $service->forceFill(['archived_at' => null, 'is_active' => false, 'is_public' => false])->save();

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
