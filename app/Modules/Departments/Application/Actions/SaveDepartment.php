<?php

declare(strict_types=1);

namespace App\Modules\Departments\Application\Actions;

use App\Kernel\Audit\Actor;
use App\Kernel\Audit\Audit;
use App\Kernel\Audit\AuditEvent;
use App\Kernel\Audit\Enums\AuditCategory;
use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TranslatedText;
use App\Modules\Departments\Domain\Models\Department;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Creates, updates or archives an operational department.
 *
 * Departments are not branch-scoped: Hair exists across the whole center, and
 * scoping it per branch would mean a manager of one branch could not see what
 * the business is organised into. Branch scope belongs to the things that
 * happen AT a branch.
 */
final class SaveDepartment
{
    public function __construct(private readonly Audit $audit) {}

    /**
     * @param  array<string, string|null>  $name
     * @param  array<string, string|null>  $description
     */
    public function __invoke(
        array $name,
        User $actingUser,
        ?Department $department = null,
        array $description = [],
        bool $isActive = true,
        int $sortOrder = 0,
    ): Department {
        if (! $actingUser->hasPermission(Permission::DepartmentManage)) {
            throw new AuthorizationException('You may not manage departments.');
        }

        $existing = $department;
        $department ??= new Department;

        $department->forceFill([
            'name' => TranslatedText::fromArray($name),
            'description' => $description === [] ? null : TranslatedText::fromArray($description),
            'is_active' => $isActive,
            'sort_order' => $sortOrder,
        ]);

        $department->save();

        $this->audit->record(new AuditEvent(
            action: $existing === null ? 'catalog.department.created' : 'catalog.department.updated',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Department::class,
            targetId: $department->uuid,
            targetLabel: (string) $department->name,
            after: ['is_active' => $isActive, 'sort_order' => $sortOrder],
        ));

        return $department;
    }

    /**
     * Retires a department. Its services keep working — they simply lose their
     * operational grouping, which is better than cascading a delete through a
     * catalog the center still sells from.
     */
    public function archive(Department $department, User $actingUser): Department
    {
        if (! $actingUser->hasPermission(Permission::DepartmentManage)) {
            throw new AuthorizationException('You may not manage departments.');
        }

        $inUse = $department->services()->whereNull('archived_at')->count();

        if ($inUse > 0) {
            throw ValidationException::withMessages([
                'department' => "This department still has {$inUse} active service(s). Move or archive them first.",
            ]);
        }

        $department->forceFill(['archived_at' => Carbon::now(), 'is_active' => false])->save();

        $this->audit->record(new AuditEvent(
            action: 'catalog.department.archived',
            category: AuditCategory::Config,
            actor: Actor::staff($actingUser),
            targetType: Department::class,
            targetId: $department->uuid,
            targetLabel: (string) $department->name,
        ));

        return $department;
    }
}
