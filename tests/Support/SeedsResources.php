<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Kernel\Localization\TranslatedText;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ResourceType;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;

/**
 * Chairs, rooms and devices for the Phase 7 tests.
 *
 * Deliberately thin: every helper writes exactly the rows a test needs and
 * nothing else, so a test that fails points at its own setup rather than at a
 * shared fixture three files away.
 */
trait SeedsResources
{
    protected function seedResourceType(string $name = 'Treatment Room'): ResourceType
    {
        /** @var ResourceType $type */
        $type = ResourceType::query()->create([
            'name' => TranslatedText::fromArray(['en' => $name]),
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return $type;
    }

    protected function seedResource(
        ResourceType $type,
        Branch $branch,
        string $name = 'Room 1',
        int $capacity = 1,
        ?Department $department = null,
        int $sortOrder = 0,
    ): OperationalResource {
        /** @var OperationalResource $resource */
        $resource = OperationalResource::query()->create([
            'resource_type_id' => $type->getKey(),
            'branch_id' => $branch->getKey(),
            'department_id' => $department?->getKey(),
            'name' => TranslatedText::fromArray(['en' => $name]),
            'capacity' => $capacity,
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);

        return $resource;
    }

    /**
     * "This service needs N of that type."
     */
    protected function requireResource(Service $service, ResourceType $type, int $quantity = 1): void
    {
        ServiceResourceRequirement::query()->updateOrCreate(
            ['service_id' => $service->getKey(), 'resource_type_id' => $type->getKey()],
            ['quantity' => $quantity],
        );
    }

    protected function seedDepartment(string $name = 'Laser'): Department
    {
        /** @var Department $department */
        $department = Department::query()->create([
            'name' => TranslatedText::fromArray(['en' => $name]),
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return $department;
    }
}
