<?php

declare(strict_types=1);

namespace App\Livewire\Center\Resources;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Notes\NoteAdvisory;
use App\Kernel\Time\BranchClock;
use App\Livewire\Center\Staff\StaffOptions;
use App\Modules\Booking\Application\AppointmentsInsideBlocks;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Employees\Application\Actions\SaveAvailabilityBlock;
use App\Modules\Employees\Domain\Enums\AvailabilityBlockType;
use App\Modules\Employees\Domain\Enums\EmployeeStatus;
use App\Modules\Employees\Domain\Models\Employee;
use App\Modules\Employees\Domain\Models\EmployeeAvailabilityBlock;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Time a member of staff cannot be booked: breaks, training, personal time.
 *
 * One component, two places: the Resources page lists every upcoming block in
 * the viewer's branches, and a staff profile shows the same list for one
 * person (`employee`). Times are entered and shown in the BRANCH's wall clock
 * and converted by `SaveAvailabilityBlock` through `BranchClock` — never the
 * server's timezone.
 *
 * A block stops NEW bookings only. Appointments already inside it are listed
 * (to someone who may read bookings) and never changed.
 */
final class AvailabilityBlocks extends Component
{
    /** Set when embedded in a staff profile: that person's blocks only. */
    #[Locked]
    public string $employee = '';

    public string $filterEmployee = '';

    public string $filterBranch = '';

    public bool $showForm = false;

    #[Locked]
    public ?string $editing = null;

    public string $formEmployee = '';

    public string $formBranch = '';

    public string $startsAt = '';

    public string $endsAt = '';

    public string $type = 'break';

    public string $note = '';

    public string $notice = '';

    public string $noticeTone = 'success';

    /** @var list<array{uuid: string, at: string, customer: string|null}> */
    public array $affected = [];

    public function mount(string $employee = ''): void
    {
        $this->employee = $employee;
    }

    public function create(): void
    {
        $this->resetForm();
        $this->formEmployee = $this->employee;
        $branches = $this->branchOptions();

        if (count($branches) === 1) {
            $this->formBranch = $branches[0]['uuid'];
        }

        $this->showForm = true;
    }

    public function updatedFormEmployee(): void
    {
        $options = array_column($this->branchOptions(), 'uuid');

        if (! in_array($this->formBranch, $options, true)) {
            $this->formBranch = count($options) === 1 ? $options[0] : '';
        }
    }

    public function edit(string $uuid): void
    {
        $block = $this->block($uuid);

        if (! $block instanceof EmployeeAvailabilityBlock) {
            return;
        }

        $this->resetForm();
        $tz = (string) Branch::query()->whereKey($block->branch_id)->value('timezone');
        $this->editing = $block->uuid;
        $this->formEmployee = (string) $block->employee?->uuid;
        $this->formBranch = (string) Branch::query()->whereKey($block->branch_id)->value('uuid');
        $this->startsAt = BranchClock::toLocal($block->starts_at, $tz)->format('Y-m-d\TH:i');
        $this->endsAt = BranchClock::toLocal($block->ends_at, $tz)->format('Y-m-d\TH:i');
        $this->type = $block->type->value;
        $this->note = (string) $block->internal_note;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->showForm = false;
    }

    public function save(SaveAvailabilityBlock $save, AppointmentsInsideBlocks $inside): void
    {
        $this->validate([
            'formEmployee' => ['required', 'string'],
            'formBranch' => ['required', 'string'],
            'startsAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'endsAt' => ['required', 'date_format:Y-m-d\TH:i'],
            'type' => ['required', 'in:'.implode(',', AvailabilityBlockType::values())],
            'note' => ['nullable', 'string', 'max:2000'],
        ], [], [
            'formEmployee' => __('manager_staff.blocks.employee'),
            'formBranch' => __('ui.fields.branch'),
            'startsAt' => __('ui.fields.from'),
            'endsAt' => __('ui.fields.to'),
            'type' => __('ui.fields.reason'),
            'note' => __('manager_staff.blocks.note'),
        ]);

        $existing = $this->editing !== null ? $this->block($this->editing) : null;

        try {
            $block = $save([
                'employee' => $this->formEmployee,
                'branch' => $this->formBranch,
                'starts_at' => $this->startsAt,
                'ends_at' => $this->endsAt,
                'type' => $this->type,
                'internal_note' => $this->note,
            ], $this->actor(), $existing);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $target = ['employee' => 'formEmployee', 'branch' => 'formBranch', 'starts_at' => 'startsAt', 'ends_at' => 'endsAt'][$field] ?? 'form';
                $this->addError($target, (string) ($messages[0] ?? ''));
            }

            return;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->affected = $this->affectedBy($block, $inside);
        $this->closeForm();
        $this->flash($this->affected === [] ? __('manager_staff.blocks.saved') : __('manager_staff.blocks.saved_with_bookings'));
    }

    public function delete(string $uuid, SaveAvailabilityBlock $save): void
    {
        $block = $this->block($uuid);

        if (! $block instanceof EmployeeAvailabilityBlock) {
            return;
        }

        try {
            $save->delete($block, $this->actor());
        } catch (AuthorizationException $e) {
            $this->flash($e->getMessage(), 'danger');

            return;
        }

        $this->affected = [];
        $this->flash(__('manager_staff.blocks.removed'));
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
        $this->affected = [];
    }

    public function render(): mixed
    {
        $viewer = $this->actor();
        $canView = $viewer->hasPermission(Permission::AvailabilityBlockManage)
            || $viewer->hasPermission(Permission::ResourceView)
            || ($this->employee !== '' && $viewer->hasPermission(Permission::StaffView));

        return view('livewire.center.resources.availability-blocks', [
            'canView' => $canView,
            'canManage' => $viewer->hasPermission(Permission::AvailabilityBlockManage),
            'blocks' => $canView ? $this->rows($viewer) : [],
            'employees' => $canView && $this->employee === '' ? $this->employeeOptions($viewer) : [],
            'filterBranches' => $canView && $this->employee === '' ? StaffOptions::branches($viewer) : [],
            'formBranches' => $this->showForm ? $this->branchOptions() : [],
            'types' => array_map(static fn (AvailabilityBlockType $type): array => ['value' => $type->value, 'label' => $type->label()], AvailabilityBlockType::cases()),
            'noteAdvisory' => NoteAdvisory::text(),
        ]);
    }

    /**
     * Upcoming blocks in the viewer's branches, in each branch's own clock.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(User $viewer): array
    {
        $query = EmployeeAvailabilityBlock::query()
            ->with('employee')
            ->where('ends_at', '>=', CarbonImmutable::now()->utc())
            ->orderBy('starts_at');

        $viewer->branchScope()->applyTo($query, 'branch_id');

        $employee = $this->employee !== '' ? $this->employee : $this->filterEmployee;

        if ($employee !== '') {
            $query->whereHas('employee', fn (Builder $q) => $q->where('uuid', $employee));
        }

        if ($this->filterBranch !== '') {
            $query->whereIn('branch_id', Branch::query()->where('uuid', $this->filterBranch)->select('id'));
        }

        $blocks = $query->limit(150)->get();
        $branches = Branch::query()->whereIn('id', $blocks->pluck('branch_id')->unique()->all())->get(['id', 'name', 'timezone'])->keyBy('id');
        $canManage = $viewer->hasPermission(Permission::AvailabilityBlockManage);

        return $blocks->map(static function (EmployeeAvailabilityBlock $block) use ($branches, $canManage): array {
            $branch = $branches->get($block->branch_id);
            $tz = $branch instanceof Branch ? $branch->timezone : 'UTC';
            $start = BranchClock::toLocal($block->starts_at, $tz);
            $end = BranchClock::toLocal($block->ends_at, $tz);

            return [
                'uuid' => $block->uuid,
                'employee' => $block->employee?->name->get() ?? '—',
                'branch' => $branch instanceof Branch ? $branch->name->get() : '—',
                'date' => $start->translatedFormat('D j M'),
                'from' => $start->format('H:i'),
                'to' => $start->isSameDay($end) ? $end->format('H:i') : $end->translatedFormat('D j M, H:i'),
                'type' => $block->type->value,
                'type_label' => $block->type->label(),
                'note' => $block->internal_note,
                'can_manage' => $canManage,
            ];
        })->values()->all();
    }

    /**
     * @return list<array{uuid: string, name: string}>
     */
    private function employeeOptions(User $viewer): array
    {
        $query = Employee::query()->where('status', EmployeeStatus::Active->value)->orderBy('id');
        $scope = $viewer->branchScope();

        if (! $scope->isUnrestricted()) {
            $ids = $scope->branchIds ?? [];
            $query->whereHas('branches', fn (Builder $q) => $q->whereIn('branches.id', $ids === [] ? [0] : $ids));
        }

        return $query->get()->map(static fn (Employee $employee): array => [
            'uuid' => $employee->uuid,
            'name' => $employee->name->get(),
        ])->values()->all();
    }

    /**
     * Live branches in the viewer's scope — narrowed to where the chosen
     * person works, because a block elsewhere would block nothing.
     *
     * @return list<array{uuid: string, name: string}>
     */
    private function branchOptions(): array
    {
        $query = Branch::query()->active()->orderBy('sort_order')->orderBy('id');
        $this->actor()->branchScope()->applyTo($query, 'id');

        $person = $this->formEmployee !== '' ? $this->formEmployee : $this->employee;

        if ($person !== '') {
            $query->whereIn('id', static fn ($sub) => $sub->select('employee_branches.branch_id')
                ->from('employee_branches')
                ->join('employees', 'employees.id', '=', 'employee_branches.employee_id')
                ->where('employees.uuid', $person));
        }

        return $query->get()->map(static fn (Branch $branch): array => [
            'uuid' => $branch->uuid,
            'name' => $branch->name->get(),
        ])->values()->all();
    }

    /**
     * Future bookings standing inside this branch's blocks — listed only to
     * someone who may read bookings, the same gate the API applies.
     *
     * @return list<array{uuid: string, at: string, customer: string|null}>
     */
    private function affectedBy(EmployeeAvailabilityBlock $block, AppointmentsInsideBlocks $inside): array
    {
        if (! $this->actor()->hasPermission(Permission::AppointmentView)) {
            return [];
        }

        return $inside->forBranch((int) $block->branch_id, app()->getLocale());
    }

    /**
     * A block in the viewer's branches — and in this profile's person, when
     * embedded. Anything else is simply not found.
     */
    private function block(string $uuid): ?EmployeeAvailabilityBlock
    {
        $query = EmployeeAvailabilityBlock::query()->with('employee')->where('uuid', $uuid);
        $this->actor()->branchScope()->applyTo($query, 'branch_id');

        if ($this->employee !== '') {
            $query->whereHas('employee', fn (Builder $q) => $q->where('uuid', $this->employee));
        }

        $block = $query->first();

        return $block instanceof EmployeeAvailabilityBlock ? $block : null;
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'formEmployee', 'formBranch', 'startsAt', 'endsAt', 'type', 'note');
        $this->resetValidation();
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
