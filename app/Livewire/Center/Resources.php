<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Notes\NoteAdvisory;
use App\Modules\Booking\Domain\Availability\BlockFinder;
use App\Modules\Booking\Domain\Models\Appointment;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Departments\Domain\Models\Department;
use App\Modules\Employees\Application\Actions\SaveAvailabilityBlock;
use App\Modules\Employees\Domain\Enums\AvailabilityBlockType;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Employees\Domain\Models\EmployeeAvailabilityBlock;
use App\Modules\Resources\Application\Actions\SaveResource;
use App\Modules\Resources\Application\Actions\SaveResourceType;
use App\Modules\Resources\Application\Actions\SetServiceResourceRequirements;
use App\Modules\Resources\Domain\Data\ResourceInput;
use App\Modules\Resources\Domain\Models\OperationalResource;
use App\Modules\Resources\Domain\Models\ResourceType;
use App\Modules\Resources\Domain\Models\ServiceResourceRequirement;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Managing the physical side of a center: chairs, rooms, devices, what each
 * service needs, and when people are unavailable.
 *
 * ## Nothing here decides anything
 *
 * Every save goes through the Action the API calls — which is where the branch
 * lock, the capacity floor and the audit entry live. A rule written in this
 * file would be a rule the API did not have
 * (docs/04-MODULE-BOUNDARIES.md, docs/13-ROADMAP.md Phase 7 §31).
 *
 * ## Functional, not finished
 *
 * Plain tables and forms. Phase 7 is operational plumbing; visual design is a
 * later, deliberate pass (§31, §56).
 */
#[Layout('components.layouts.app')]
final class Resources extends Component
{
    public string $tab = 'resources';

    /** @var array<string, string> */
    public array $typeName = [];

    public string $resourceUuid = '';

    /** @var array<string, string> */
    public array $resourceName = [];

    public string $resourceType = '';

    public string $resourceBranch = '';

    public string $resourceDepartment = '';

    public int $capacity = 1;

    public string $requirementService = '';

    /** @var array<int, array<string, mixed>> */
    public array $requirements = [];

    public string $blockEmployee = '';

    public string $blockBranch = '';

    public string $blockStartsAt = '';

    public string $blockEndsAt = '';

    public string $blockType = 'break';

    public string $blockNote = '';

    public ?string $error = null;

    public ?string $saved = null;

    /** @var list<array<string, mixed>> */
    public array $affected = [];

    public function mount(): void
    {
        $this->authorizeView();
    }

    public function saveType(SaveResourceType $save): void
    {
        $this->run(function () use ($save): void {
            $save($this->typeName, $this->user());

            $this->typeName = [];
            $this->saved = __('Resource type saved.');
        });
    }

    public function saveResource(SaveResource $save): void
    {
        $this->run(function () use ($save): void {
            $existing = $this->resourceUuid === ''
                ? null
                : OperationalResource::query()->where('uuid', $this->resourceUuid)->first();

            $save(ResourceInput::fromArray([
                'resource_type' => $this->resourceType,
                'branch' => $this->resourceBranch,
                'department' => $this->resourceDepartment === '' ? null : $this->resourceDepartment,
                'name' => $this->resourceName,
                'capacity' => $this->capacity,
                'is_active' => true,
            ]), $this->user(), $existing);

            $this->reset(['resourceUuid', 'resourceName', 'capacity']);
            $this->capacity = 1;
            $this->saved = __('Resource saved.');
        });
    }

    public function editResource(string $uuid): void
    {
        $resource = OperationalResource::query()->with(['type', 'branch', 'department'])
            ->where('uuid', $uuid)->first();

        if (! $resource instanceof OperationalResource) {
            return;
        }

        $this->resourceUuid = $resource->uuid;
        $this->resourceName = $resource->name->all();
        $this->resourceType = (string) $resource->type?->uuid;
        $this->resourceBranch = (string) $resource->branch?->uuid;
        $this->resourceDepartment = (string) $resource->department?->uuid;
        $this->capacity = $resource->capacity;
    }

    public function archiveResource(string $uuid, SaveResource $save): void
    {
        $this->run(function () use ($uuid, $save): void {
            $resource = OperationalResource::query()->where('uuid', $uuid)->first();

            if ($resource instanceof OperationalResource) {
                $save->archive($resource, $this->user());
                // Existing appointments keep this resource. Retiring it stops
                // the NEXT booking and touches nothing already made (§12).
                $this->saved = __('Resource archived. Existing bookings are unchanged.');
            }
        });
    }

    public function loadRequirements(): void
    {
        $service = Service::query()->where('uuid', $this->requirementService)->first();

        if (! $service instanceof Service) {
            $this->requirements = [];

            return;
        }

        $this->requirements = ServiceResourceRequirement::query()
            ->with('type')
            ->where('service_id', $service->getKey())
            ->get()
            ->map(static fn (ServiceResourceRequirement $r): array => [
                'type' => (string) $r->type?->uuid,
                'quantity' => $r->quantity,
            ])
            ->all();
    }

    public function addRequirement(): void
    {
        $this->requirements[] = ['type' => '', 'quantity' => 1];
    }

    public function removeRequirement(int $index): void
    {
        unset($this->requirements[$index]);

        $this->requirements = array_values($this->requirements);
    }

    public function saveRequirements(SetServiceResourceRequirements $set): void
    {
        $this->run(function () use ($set): void {
            $service = Service::query()->where('uuid', $this->requirementService)->first();

            if (! $service instanceof Service) {
                throw ValidationException::withMessages(['service' => __('Choose a service.')]);
            }

            $rows = array_values(array_filter(
                $this->requirements,
                static fn (array $r): bool => ($r['type'] ?? '') !== '',
            ));

            $set($service, $rows, $this->user());

            $this->saved = __('Requirements saved. Existing bookings keep the resources they hold.');
        });
    }

    public function saveBlock(SaveAvailabilityBlock $save, BlockFinder $finder): void
    {
        $this->run(function () use ($save, $finder): void {
            $block = $save([
                'employee' => $this->blockEmployee,
                'branch' => $this->blockBranch,
                'starts_at' => $this->blockStartsAt,
                'ends_at' => $this->blockEndsAt,
                'type' => $this->blockType,
                'internal_note' => $this->blockNote,
            ], $this->user());

            $this->reset(['blockStartsAt', 'blockEndsAt', 'blockNote']);

            // Surfaced, never acted on. Whoever just blocked Thursday afternoon
            // should see the customers standing in it (§14).
            $this->affected = $this->affectedBy($block, $finder);

            $this->saved = $this->affected === []
                ? __('Block saved.')
                : __('Block saved. Existing bookings inside it are listed below and were NOT changed.');
        });
    }

    public function deleteBlock(string $uuid, SaveAvailabilityBlock $save): void
    {
        $this->run(function () use ($uuid, $save): void {
            $block = EmployeeAvailabilityBlock::query()->where('uuid', $uuid)->first();

            if ($block instanceof EmployeeAvailabilityBlock) {
                $save->delete($block, $this->user());
                $this->saved = __('Block removed.');
            }
        });
    }

    public function render(): mixed
    {
        $user = $this->user();

        $branchQuery = Branch::query()->active();
        $user->branchScope()->applyTo($branchQuery, 'id');
        $branches = $branchQuery->get();

        $resourceQuery = OperationalResource::query()->with(['type', 'branch', 'department']);
        $user->branchScope()->applyTo($resourceQuery, 'branch_id');

        $blockQuery = EmployeeAvailabilityBlock::query()
            ->with('employee')
            ->where('ends_at', '>=', CarbonImmutable::now()->utc())
            ->orderBy('starts_at');
        $user->branchScope()->applyTo($blockQuery, 'branch_id');

        return view('livewire.center.resources', [
            'types' => ResourceType::query()->orderBy('sort_order')->orderBy('id')->get(),
            'resources' => $resourceQuery->orderBy('branch_id')->orderBy('sort_order')->get(),
            'branches' => $branches,
            'departments' => Department::query()->active()->get(),
            'services' => Service::query()->orderBy('sort_order')->orderBy('id')->get(),
            'employees' => $this->employees($branches),
            'blocks' => $blockQuery->limit(200)->get(),
            'blockTypes' => AvailabilityBlockType::cases(),
            'locales' => app(TenantLocales::class)->enabled(),
            'noteAdvisory' => NoteAdvisory::text(),
            'canManage' => $user->hasPermission(Permission::ResourceManage),
            'canBlock' => $user->hasPermission(Permission::AvailabilityBlockManage),
        ]);
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return Collection<int, Employee>
     */
    private function employees(Collection $branches): Collection
    {
        return Employee::query()
            ->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branches->pluck('id')->all()))
            ->orderBy('id')
            ->get();
    }

    /**
     * Future bookings standing inside this branch's blocks.
     *
     * Listed, never acted on. Whoever just blocked Thursday afternoon should
     * see the customers already booked into it and decide what to do — moving
     * somebody's appointment is the center's call (§14).
     *
     * @return list<array<string, mixed>>
     */
    private function affectedBy(EmployeeAvailabilityBlock $block, BlockFinder $finder): array
    {
        $ids = $finder->appointmentsInsideBlocks((int) $block->branch_id, CarbonImmutable::now());

        if ($ids === []) {
            return [];
        }

        return Appointment::query()
            ->with('customer')
            ->whereIn('id', $ids)
            ->blocking()
            ->orderBy('starts_at')
            ->limit(25)
            ->get()
            ->map(static fn (Appointment $a): array => [
                'uuid' => $a->uuid,
                'at' => $a->localStart()->format('Y-m-d H:i'),
                'customer' => $a->customer?->name,
            ])
            ->all();
    }

    private function run(callable $operation): void
    {
        $this->error = null;
        $this->saved = null;

        try {
            $operation();
        } catch (ValidationException $e) {
            $this->error = $e->validator->errors()->first();
        } catch (AuthorizationException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeView(): void
    {
        if (! $this->user()->hasPermission(Permission::ResourceView)) {
            throw new AuthorizationException('You may not view resources.');
        }
    }
}
