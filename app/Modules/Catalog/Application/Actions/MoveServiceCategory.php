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
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves one menu category to a new position.
 *
 * The caller states ONE intent — this category, that position — never a whole
 * list. The order is read back from the locked rows, the index is clamped, and
 * categories are renumbered 0..n-1. Because a service's position is its place
 * in the whole library, the services follow their category (docs/13-ROADMAP.md
 * Phase 15, Manager services library).
 */
final class MoveServiceCategory
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CatalogOrdering $ordering,
    ) {}

    /**
     * @return int the position the category ended at
     */
    public function __invoke(ServiceCategory $category, int $toIndex, User $actingUser): int
    {
        return $this->move($category, $actingUser, fn (CatalogLayout $layout, int $from): int => $toIndex);
    }

    /**
     * One step up (-1) or down (+1): the keyboard and button path. The
     * current position is read under the lock, not trusted from the page.
     */
    public function step(ServiceCategory $category, int $delta, User $actingUser): int
    {
        return $this->move($category, $actingUser, fn (CatalogLayout $layout, int $from): int => $from + $delta);
    }

    /**
     * @param  callable(CatalogLayout, int): int  $target
     */
    private function move(ServiceCategory $category, User $actingUser, callable $target): int
    {
        if (! $actingUser->hasPermission(Permission::CategoryManage)) {
            throw new AuthorizationException('You may not manage menu categories.');
        }

        /** @var array{0: int, 1: int} $move */
        $move = DB::connection('tenant')->transaction(function () use ($category, $target): array {
            $layout = $this->ordering->lock();
            $from = $layout->categoryIndex((int) $category->id);

            if ($from === null) {
                throw ValidationException::withMessages([
                    'category' => 'An archived category cannot be moved.',
                ]);
            }

            $move = $layout->moveCategory((int) $category->id, $target($layout, $from));
            $layout->persist();

            return $move;
        });

        [$from, $to] = $move;

        if ($from !== $to) {
            $this->audit->record(new AuditEvent(
                action: 'catalog.category.moved',
                category: AuditCategory::Config,
                actor: Actor::staff($actingUser),
                targetType: ServiceCategory::class,
                targetId: $category->uuid,
                targetLabel: (string) $category->name,
                before: ['position' => $from],
                after: ['position' => $to],
            ));
        }

        return $to;
    }
}
