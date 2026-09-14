<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Resources\Domain\Models\ResourceType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Creates, updates or archives a resource TYPE.
 *
 * Not branch-scoped, for the same reason a department is not: "Laser Machine"
 * is a classification the whole center uses, and the individual machines are
 * what live at a branch.
 *
 * Renaming a type does NOT rewrite history — every reservation already carries
 * its own snapshot of the type name (docs/13-ROADMAP.md Phase 7 §36).
 */
final class SaveResourceType
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, string|null>  $name
     * @param  array<string, string|null>  $description
     */
    public function __invoke(
        array $name,
        User $actingUser,
        ?ResourceType $type = null,
        array $description = [],
        bool $isActive = true,
        int $sortOrder = 0,
    ): ResourceType {
        $this->authorize($actingUser);

        $existing = $type;
        $type ??= new ResourceType;

        $before = $existing === null ? null : $this->snapshot($existing);

        $type->forceFill([
            'name' => TranslatedText::fromArray($name),
            'description' => $description === [] ? null : TranslatedText::fromArray($description),
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ]);

        $type->save();

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'resources.type.created' : 'resources.type.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ResourceType::class,
            targetId: $type->uuid,
            targetLabel: (string) $type->name,
            before: $before,
            after: $this->snapshot($type),
        ));

        return $type;
    }

    /**
     * Retires a type.
     *
     * Refused while services still require it: a requirement pointing at a
     * retired classification is a service nothing can satisfy, and discovering
     * that at the booking desk is worse than discovering it here.
     */
    public function archive(ResourceType $type, User $actingUser): ResourceType
    {
        $this->authorize($actingUser);

        $required = $type->requirements()->count();

        if ($required > 0) {
            throw ValidationException::withMessages([
                'resource_type' => "This type is still required by {$required} service(s). Remove those requirements first.",
            ]);
        }

        $resources = $type->resources()->whereNull('archived_at')->count();

        if ($resources > 0) {
            throw ValidationException::withMessages([
                'resource_type' => "This type still has {$resources} active resource(s). Archive them first.",
            ]);
        }

        $type->forceFill(['archived_at' => Carbon::now(), 'is_active' => false])->save();

        $this->audit->record(new AuditEvent(
            action: 'resources.type.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: ResourceType::class,
            targetId: $type->uuid,
            targetLabel: (string) $type->name,
        ));

        return $type;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ResourceType $type): array
    {
        return [
            'is_active' => $type->is_active,
            'sort_order' => $type->sort_order,
        ];
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actingUser): void
    {
        if (! $actingUser->hasPermission(Permission::ResourceManage)) {
            throw new AuthorizationException('You may not manage resources.');
        }
    }
}
