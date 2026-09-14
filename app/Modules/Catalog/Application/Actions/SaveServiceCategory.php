<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;

/**
 * Creates, updates or archives a customer-facing menu category.
 *
 * Unlike a department, archiving a category does NOT require its services to
 * be moved first. A service with no category is still perfectly sellable — it
 * simply appears ungrouped on the menu — whereas a service with no department
 * has lost the operational routing that Queue and the Service Journey will
 * depend on. The asymmetry is the whole point of keeping the two separate.
 */
final class SaveServiceCategory
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, string|null>  $name
     * @param  array<string, string|null>  $description
     */
    public function __invoke(
        array $name,
        User $actingUser,
        ?ServiceCategory $category = null,
        array $description = [],
        bool $isActive = true,
        bool $isPublic = true,
        int $sortOrder = 0,
    ): ServiceCategory {
        if (! $actingUser->hasPermission(Permission::CategoryManage)) {
            throw new AuthorizationException('You may not manage menu categories.');
        }

        $existing = $category;
        $category ??= new ServiceCategory;

        $category->forceFill([
            'name' => TranslatedText::fromArray($name),
            'description' => $description === [] ? null : TranslatedText::fromArray($description),
            'is_active' => $isActive,
            'is_public' => $isPublic,
            'sort_order' => $sortOrder,
        ]);

        $category->save();

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'catalog.category.created' : 'catalog.category.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceCategory::class,
            targetId: $category->uuid,
            targetLabel: (string) $category->name,
            after: ['is_active' => $isActive, 'is_public' => $isPublic, 'sort_order' => $sortOrder],
        ));

        return $category;
    }

    public function archive(ServiceCategory $category, User $actingUser): ServiceCategory
    {
        if (! $actingUser->hasPermission(Permission::CategoryManage)) {
            throw new AuthorizationException('You may not manage menu categories.');
        }

        // Detaching rather than cascading: the services survive, uncategorised.
        $category->services()->update(['service_category_id' => null]);

        $category->forceFill([
            'archived_at' => Carbon::now(),
            'is_active' => false,
            'is_public' => false,
        ])->save();

        $this->audit->record(new AuditEvent(
            action: 'catalog.category.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceCategory::class,
            targetId: $category->uuid,
            targetLabel: (string) $category->name,
        ));

        return $category;
    }
}
