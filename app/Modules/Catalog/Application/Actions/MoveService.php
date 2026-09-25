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
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reorders a service inside its category, or moves it to another one.
 *
 * One intent per call — this service, that list, that position — and the
 * order itself is re-derived from the LOCKED rows, clamped and renumbered.
 * A client-sent full order is never trusted, so a stale browser cannot write
 * an order it never saw; it simply re-renders.
 *
 * Moving between categories changes where the service appears on the menu and
 * nothing else: not its department (ADR-037), not its price, not a booking.
 */
final class MoveService
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CatalogOrdering $ordering,
    ) {}

    /**
     * Reorders within the service's own category.
     */
    public function __invoke(Service $service, int $toIndex, User $actingUser): void
    {
        $this->move($service, $actingUser, null, fn (CatalogLayout $layout, array $at): int => $toIndex);
    }

    /**
     * One step up (-1) or down (+1), from the position read under the lock.
     */
    public function step(Service $service, int $delta, User $actingUser): void
    {
        $this->move($service, $actingUser, null, fn (CatalogLayout $layout, array $at): int => $at[1] + $delta);
    }

    /**
     * Moves to another category (null = uncategorised) at a position, or at
     * the end when no position is given.
     */
    public function toCategory(Service $service, ?ServiceCategory $target, ?int $toIndex, User $actingUser): void
    {
        $this->move($service, $actingUser, ['category' => $target], fn (CatalogLayout $layout, array $at): ?int => $toIndex);
    }

    /**
     * @param  array{category: ServiceCategory|null}|null  $destination  null = stay in the current category
     * @param  callable(CatalogLayout, array{0: int, 1: int}): ?int  $target
     */
    private function move(Service $service, User $actingUser, ?array $destination, callable $target): void
    {
        if (! $actingUser->hasPermission(Permission::ServiceUpdate)) {
            throw new AuthorizationException('You may not change services.');
        }

        /** @var array{from: array{0: int, 1: int}, to: array{0: int, 1: int}, fromUuid: string|null, toUuid: string|null} $result */
        $result = DB::connection('tenant')->transaction(function () use ($service, $destination, $target): array {
            $layout = $this->ordering->lock();
            $at = $layout->locateService((int) $service->id);

            if ($at === null) {
                throw ValidationException::withMessages([
                    'service' => 'An archived service cannot be moved.',
                ]);
            }

            $group = $at[0];

            if ($destination !== null) {
                $category = $destination['category'];
                $group = $category === null ? CatalogLayout::UNCATEGORISED : (int) $category->id;

                if (! $layout->hasGroup($group)) {
                    throw ValidationException::withMessages([
                        'category' => 'That category is archived.',
                    ]);
                }
            }

            $placed = $layout->placeService($service, $group, $target($layout, $at));

            if ($group !== $at[0]) {
                Service::query()->whereKey($service->id)->update([
                    'service_category_id' => $group === CatalogLayout::UNCATEGORISED ? null : $group,
                ]);
                $service->service_category_id = $group === CatalogLayout::UNCATEGORISED ? null : $group;
            }

            $layout->persist();

            return [
                'from' => $at,
                'to' => $placed['to'],
                'fromUuid' => $layout->categoryUuid($at[0]),
                'toUuid' => $layout->categoryUuid($group),
            ];
        });

        if ($result['from'] === $result['to']) {
            return;
        }

        $this->audit->record(new AuditEvent(
            action: 'catalog.service.moved',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Service::class,
            targetId: $service->uuid,
            targetLabel: (string) $service->name,
            before: ['category' => $result['fromUuid'], 'position' => $result['from'][1]],
            after: ['category' => $result['toUuid'], 'position' => $result['to'][1]],
        ));
    }
}
