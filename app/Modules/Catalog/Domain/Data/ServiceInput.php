<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Domain\Data;

/**
 * Everything needed to create or update a service, in one validated shape.
 *
 * Variations, add-on links, branch availability and employee eligibility travel
 * WITH the service because that is how the UI edits it — one form, one save. A
 * separate action per relation would mean four round trips, four transactions,
 * and a window where a service is half-configured.
 */
final readonly class ServiceInput
{
    /**
     * @param  array<string, string|null>  $name
     * @param  array<string, string|null>  $shortDescription
     * @param  array<string, string|null>  $description
     * @param  list<array{uuid?: string|null, name: array<string, string|null>, price_minor?: int|null, duration_minutes?: int|null, is_active?: bool}>|null  $variations
     * @param  list<int>|null  $addonIds
     * @param  list<int>|null  $branchIds
     * @param  list<int>|null  $employeeIds
     *
     * `sortOrder` null means "the library decides": a new service goes to the
     * end of its category, an edited one keeps its place (and moves to the end
     * of its new category when the category changes). An integer is written
     * as-is — the API's explicit value.
     */
    public function __construct(
        public array $name,
        public int $durationMinutes,
        public int $priceMinor,
        public ?int $departmentId = null,
        public ?int $serviceCategoryId = null,
        public array $shortDescription = [],
        public array $description = [],
        public bool $isActive = true,
        public bool $isPublic = true,
        public bool $isOnlineBookable = true,
        public bool $availableAtAllBranches = true,
        public ?int $sortOrder = null,
        public ?array $variations = null,
        public ?array $addonIds = null,
        public ?array $branchIds = null,
        public ?array $employeeIds = null,
    ) {}

    /**
     * `null` on a relation means "leave it alone"; an empty array means "clear
     * it". Collapsing the two would make a partial update silently wipe a
     * service's employee eligibility.
     */
    public function touchesVariations(): bool
    {
        return $this->variations !== null;
    }

    public function touchesAddons(): bool
    {
        return $this->addonIds !== null;
    }

    public function touchesBranches(): bool
    {
        return $this->branchIds !== null;
    }

    public function touchesEmployees(): bool
    {
        return $this->employeeIds !== null;
    }
}
