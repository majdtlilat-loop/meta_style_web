<?php

declare(strict_types=1);

namespace App\Livewire\Center\Staff;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Modules\Catalog\Application\Actions\SetEmployeeServices;
use App\Modules\Catalog\Domain\Models\Service;
use App\Modules\Employees\Application\EmployeeQuery;
use App\Modules\Employees\Application\StaffGuard;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Which services one member of staff performs.
 *
 * The Booking Engine assigns a person only to services they are eligible for,
 * so this list IS who can be booked for what. Written through
 * {@see SetEmployeeServices}; the list below is read-only for anyone who may
 * not edit this person.
 */
final class Services extends Component
{
    #[Locked]
    public string $uuid = '';

    /** @var array<int, string> service uuids */
    public array $selected = [];

    public string $filter = '';

    public string $notice = '';

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
        $this->selected = $this->current();
    }

    public function selectAll(bool $on): void
    {
        $this->selected = $on ? $this->catalog($this->current())->pluck('uuid')->values()->all() : [];
    }

    public function save(SetEmployeeServices $set): void
    {
        $employee = $this->employee();

        if (! $employee instanceof Employee) {
            return;
        }

        try {
            $set($employee, array_values(array_map('strval', $this->selected)), $this->actor());
        } catch (AuthorizationException|ValidationException $e) {
            $this->addError('selected', $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage());

            return;
        }

        $this->resetValidation();
        $this->selected = $this->current();
        $this->notice = trans_choice('manager_staff.services.saved', count($this->selected), ['count' => count($this->selected)]);
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function render(): mixed
    {
        $viewer = $this->actor();
        $employee = $this->employee();
        $needle = mb_strtolower(trim($this->filter));
        $catalog = $this->catalog($this->current());

        $services = $catalog
            ->filter(fn (Service $service): bool => $needle === '' || str_contains(mb_strtolower($service->name->get()), $needle))
            ->map(fn (Service $service): array => [
                'uuid' => $service->uuid,
                'name' => $service->name->get(),
                'minutes' => $service->duration_minutes,
                'archived' => $service->isArchived(),
                'inactive' => ! $service->is_active,
            ])
            ->values()
            ->all();

        return view('livewire.center.staff.services', [
            'found' => $employee instanceof Employee,
            'canEdit' => $employee instanceof Employee
                && $viewer->hasPermission(Permission::StaffUpdate)
                && StaffGuard::reaches($viewer, $employee),
            'services' => $services,
            'total' => $catalog->count(),
        ]);
    }

    /**
     * Live services, plus retired ones the person is still eligible for.
     *
     * @param  list<string>  $current
     * @return Collection<int, Service>
     */
    private function catalog(array $current): Collection
    {
        return Service::query()
            ->where(fn ($query) => $query->whereNull('archived_at')->orWhereIn('uuid', $current === [] ? [''] : $current))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'uuid', 'name', 'duration_minutes', 'is_active', 'archived_at']);
    }

    /**
     * @return list<string>
     */
    private function current(): array
    {
        $employee = $this->employee();

        if (! $employee instanceof Employee) {
            return [];
        }

        /** @var list<string> $uuids */
        $uuids = DB::connection('tenant')->table('employee_service')
            ->join('services', 'services.id', '=', 'employee_service.service_id')
            ->where('employee_service.employee_id', $employee->getKey())
            ->pluck('services.uuid')
            ->map(static fn (mixed $uuid): string => (string) $uuid)
            ->all();

        return $uuids;
    }

    private function employee(): ?Employee
    {
        return app(EmployeeQuery::class)->find($this->uuid, $this->actor());
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
