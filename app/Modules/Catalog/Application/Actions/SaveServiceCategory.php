<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Catalog\Application\Ordering\CatalogOrdering;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Catalog\Domain\Models\ServiceCategory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates, updates, archives or restores a customer-facing menu category.
 *
 * Unlike a department, archiving a category does NOT require its services to
 * be moved first. A service with no category is still perfectly sellable — it
 * simply appears ungrouped on the menu — whereas a service with no department
 * has lost the operational routing that Queue and the Service Journey will
 * depend on. The asymmetry is the whole point of keeping the two separate.
 *
 * `sortOrder` null means "the library decides": a new category goes last, an
 * edited one keeps its place. Reordering is {@see MoveServiceCategory}.
 */
final class SaveServiceCategory
{
    public function __construct(
        private readonly Audit $audit,
        private readonly CatalogOrdering $ordering,
        private readonly LanguageRegistry $languages,
    ) {}

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
        ?int $sortOrder = null,
    ): ServiceCategory {
        $this->authorize($actingUser);

        $text = $this->text($name);

        if ($text->isEmpty()) {
            throw ValidationException::withMessages(['name' => 'A category needs a name.']);
        }

        $existing = $category;

        /** @var ServiceCategory $saved */
        $saved = DB::connection('tenant')->transaction(function () use ($existing, $text, $description, $isActive, $isPublic, $sortOrder): ServiceCategory {
            $append = $existing === null && $sortOrder === null;
            $layout = $append ? $this->ordering->lock() : null;

            $category = $existing ?? new ServiceCategory;

            $category->forceFill([
                'name' => $text,
                'description' => $this->optionalText($description),
                'is_active' => $isActive,
                'is_public' => $isPublic,
                'sort_order' => $sortOrder === null
                    ? ($existing->sort_order ?? 0)
                    : max(0, min(65535, $sortOrder)),
            ]);

            $category->save();

            if ($layout !== null) {
                $layout->appendCategory($category);
                $layout->persist();
            }

            return $category->refresh();
        });

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'catalog.category.created' : 'catalog.category.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceCategory::class,
            targetId: $saved->uuid,
            targetLabel: (string) $saved->name,
            after: ['is_active' => $saved->is_active, 'is_public' => $saved->is_public, 'sort_order' => $saved->sort_order],
        ));

        return $saved;
    }

    /**
     * Retires a category. Its services survive, uncategorised, at the end of
     * the library in the order they had.
     */
    public function archive(ServiceCategory $category, User $actingUser): ServiceCategory
    {
        $this->authorize($actingUser);

        /** @var int $detached */
        $detached = DB::connection('tenant')->transaction(function () use ($category): int {
            $layout = $this->ordering->lock();
            $layout->removeCategory((int) $category->id);

            // Detaching rather than cascading: the services survive. Archived
            // ones too, so a restored service never points at a dead category.
            $detached = Service::query()
                ->where('service_category_id', $category->id)
                ->update(['service_category_id' => null]);

            $category->forceFill([
                'archived_at' => Carbon::now(),
                'is_active' => false,
                'is_public' => false,
            ])->save();

            $layout->persist();

            return $detached;
        });

        $this->audit->record(new AuditEvent(
            action: 'catalog.category.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceCategory::class,
            targetId: $category->uuid,
            targetLabel: (string) $category->name,
            after: ['detached_services' => $detached],
        ));

        return $category;
    }

    /**
     * Brings an archived category back, last in the list, hidden and inactive
     * — the owner decides when it returns to the menu. It comes back empty: its
     * services were uncategorised when it was archived and stay where they are.
     */
    public function restore(ServiceCategory $category, User $actingUser): ServiceCategory
    {
        $this->authorize($actingUser);

        if (! $category->isArchived()) {
            throw ValidationException::withMessages(['category' => 'That category is not archived.']);
        }

        DB::connection('tenant')->transaction(function () use ($category): void {
            $layout = $this->ordering->lock();

            $category->forceFill(['archived_at' => null, 'is_active' => false, 'is_public' => false])->save();

            $layout->appendCategory($category);
            $layout->persist();
        });

        $this->audit->record(new AuditEvent(
            action: 'catalog.category.restored',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ServiceCategory::class,
            targetId: $category->uuid,
            targetLabel: (string) $category->name,
        ));

        return $category->refresh();
    }

    private function authorize(User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::CategoryManage)) {
            throw new AuthorizationException('You may not manage menu categories.');
        }
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function text(array $values): TranslatedText
    {
        $clean = [];

        foreach ($values as $locale => $value) {
            if ($this->languages->supports((string) $locale) && is_string($value)) {
                $clean[$locale] = trim($value);
            }
        }

        return TranslatedText::fromArray($clean);
    }

    /**
     * @param  array<string, string|null>  $values
     */
    private function optionalText(array $values): ?TranslatedText
    {
        $text = $this->text($values);

        return $text->isEmpty() ? null : $text;
    }
}
