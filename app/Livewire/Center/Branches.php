<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Branches\BranchPresenter;
use App\Modules\Branches\Application\Actions\ArchiveBranch;
use App\Modules\Branches\Application\Actions\RestoreBranch;
use App\Modules\Branches\Application\Actions\SaveBranch;
use App\Modules\Branches\Application\Actions\SaveBranchSchedule;
use App\Modules\Branches\Domain\Data\BranchInput;
use App\Modules\Branches\Domain\Models\Branch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Branch management: details, contact, the weekly schedule and date
 * exceptions (holidays, special hours).
 *
 * Every write goes through an Action, so the permission check, the branch-scope
 * check, the transaction and the audit entry are the same ones the API gets.
 *
 * An edit round-trips EVERYTHING the Actions replace: coordinates and sort
 * order (SaveBranch writes them unconditionally) and every date exception,
 * including past ones kept aside unedited (SaveBranchSchedule replaces the
 * whole set). Leaving any of them out is how a web edit used to wipe a
 * branch's holidays.
 */
#[Layout('components.layouts.app')]
final class Branches extends Component
{
    #[Url(except: 'active')]
    public string $view = 'active';

    #[Locked]
    public ?string $editing = null;

    /** @var array<string, string> */
    public array $name = [];

    /** @var array<string, string> */
    public array $address = [];

    public string $timezone = 'Asia/Baghdad';

    public string $phone = '';

    public string $phoneCountry = PhoneCountries::DEFAULT;

    public string $whatsapp = '';

    public string $whatsappCountry = PhoneCountries::DEFAULT;

    public string $email = '';

    public string $mapUrl = '';

    public string $latitude = '';

    public string $longitude = '';

    /** A string so an emptied number field is a validation error, not a TypeError. */
    public string $sortOrder = '0';

    public bool $isActive = true;

    public bool $isPublic = true;

    /** @var list<array{day_of_week: int, opens_at: string, closes_at: string}> */
    public array $hours = [];

    /** @var list<array{date: string, is_closed: bool, opens_at: string, closes_at: string, note: string}> */
    public array $exceptions = [];

    /**
     * Exceptions before today, carried through a save unchanged.
     *
     * @var list<array{date: string, is_closed: bool, opens_at: string|null, closes_at: string|null, note: string|null}>
     */
    #[Locked]
    public array $pastExceptions = [];

    public string $notice = '';

    public string $noticeTone = 'success';

    public bool $showForm = false;

    public function create(): void
    {
        $this->resetForm();
        $this->resetValidation();
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->resetForm();
        $this->resetValidation();
        $this->showForm = false;
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function edit(string $uuid): void
    {
        $branch = $this->scopedBranch($uuid);

        if (! $branch instanceof Branch) {
            return;
        }

        $this->resetForm();
        $this->resetValidation();

        $this->editing = $branch->uuid;
        $this->name = $branch->name->all();
        $this->address = $branch->address?->all() ?? [];
        $this->timezone = $branch->timezone;
        [$this->phoneCountry, $this->phone] = BranchPresenter::phoneParts($branch->phone);
        [$this->whatsappCountry, $this->whatsapp] = BranchPresenter::phoneParts($branch->whatsapp);
        $this->email = (string) $branch->email;
        $this->mapUrl = (string) $branch->map_url;
        $this->latitude = (string) $branch->latitude;
        $this->longitude = (string) $branch->longitude;
        $this->sortOrder = (string) $branch->sort_order;
        $this->isActive = $branch->is_active;
        $this->isPublic = $branch->is_public;

        $this->hours = $branch->workingHours->map(static fn ($h): array => [
            'day_of_week' => (int) $h->day_of_week,
            'opens_at' => mb_substr((string) $h->opens_at, 0, 5),
            'closes_at' => mb_substr((string) $h->closes_at, 0, 5),
        ])->values()->all();

        [$this->exceptions, $this->pastExceptions] = BranchPresenter::exceptionRows($branch);

        $this->showForm = true;
    }

    public function addInterval(int $day = 0): void
    {
        // Adding a second interval to a day is how a split shift is entered —
        // 09:00–13:00 and 16:00–22:00 are two rows, not one field.
        $this->hours[] = ['day_of_week' => $day, 'opens_at' => '09:00', 'closes_at' => '17:00'];
    }

    public function removeInterval(int $index): void
    {
        unset($this->hours[$index]);

        $this->hours = array_values($this->hours);
    }

    public function addException(): void
    {
        $this->exceptions[] = ['date' => '', 'is_closed' => true, 'opens_at' => '', 'closes_at' => '', 'note' => ''];
    }

    public function removeException(int $index): void
    {
        unset($this->exceptions[$index]);

        $this->exceptions = array_values($this->exceptions);
    }

    public function save(SaveBranch $save, SaveBranchSchedule $saveSchedule): void
    {
        $this->validate([
            'name' => ['required', 'array'],
            'name.'.app(TenantLocales::class)->default() => ['required', 'string', 'max:190'],
            'timezone' => ['required', 'timezone'],
            'phone' => ['nullable', 'string', 'max:32', ...($this->phone !== '' ? [new PhoneRule($this->phoneCountry)] : [])],
            'whatsapp' => ['nullable', 'string', 'max:32', ...($this->whatsapp !== '' ? [new PhoneRule($this->whatsappCountry)] : [])],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'mapUrl' => ['nullable', 'url', 'max:500'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'sortOrder' => ['required', 'integer', 'min:0', 'max:9999'],
            'hours.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i'],
            'exceptions.*.date' => ['required', 'date_format:Y-m-d'],
            'exceptions.*.is_closed' => ['boolean'],
            'exceptions.*.opens_at' => ['nullable', 'required_if:exceptions.*.is_closed,false', 'date_format:H:i'],
            'exceptions.*.closes_at' => ['nullable', 'required_if:exceptions.*.is_closed,false', 'date_format:H:i'],
            'exceptions.*.note' => ['nullable', 'string', 'max:190'],
        ], [], BranchPresenter::attributes());

        $existing = $this->editing === null ? null : $this->scopedBranch($this->editing);

        if ($this->editing !== null && ! $existing instanceof Branch) {
            $this->addError('form', __('manager_staff.errors.branch_denied'));

            return;
        }

        $exceptions = array_map(static fn (array $row): array => [
            'date' => $row['date'],
            'is_closed' => (bool) $row['is_closed'],
            'opens_at' => $row['is_closed'] ? null : $row['opens_at'],
            'closes_at' => $row['is_closed'] ? null : $row['closes_at'],
            'note' => trim($row['note']) !== '' ? trim($row['note']) : null,
        ], $this->exceptions);

        try {
            // ONE transaction around both Actions. A rejected schedule must not
            // leave a branch behind with no hours and an audit entry saying it
            // was created.
            DB::connection('tenant')->transaction(function () use ($save, $saveSchedule, $existing, $exceptions): void {
                $branch = $save(BranchInput::fromArray([
                    'name' => $this->name,
                    'address' => $this->address,
                    'timezone' => $this->timezone,
                    'phone' => $this->phone !== '' ? PhoneNumber::fromParts($this->phoneCountry, $this->phone)?->e164 : null,
                    'whatsapp' => $this->whatsapp !== '' ? PhoneNumber::fromParts($this->whatsappCountry, $this->whatsapp)?->e164 : null,
                    'email' => $this->email,
                    'map_url' => $this->mapUrl,
                    'latitude' => $this->latitude,
                    'longitude' => $this->longitude,
                    'is_active' => $this->isActive,
                    'is_public' => $this->isPublic,
                    'sort_order' => (int) $this->sortOrder,
                ]), $this->actor(), $existing);

                $saveSchedule($branch, $this->hours, [...$this->pastExceptions, ...$exceptions], $this->actor());
            });
        } catch (AuthorizationException $e) {
            // Inside the open drawer, where the person pressed Save.
            $this->addError('form', $e->getMessage());

            return;
        } catch (ValidationException $e) {
            // Overlapping intervals, a duplicated date: reported where the
            // person entering them can see them.
            foreach ($e->errors() as $field => $messages) {
                $this->addError(in_array($field, ['hours', 'exceptions'], true) ? $field : 'form', (string) ($messages[0] ?? ''));
            }

            return;
        }

        $this->flash($existing === null ? __('manager_staff.branches.created') : __('manager_staff.branches.saved'));
        $this->resetForm();
        $this->showForm = false;
    }

    public function archive(string $uuid, ArchiveBranch $archive): void
    {
        $this->lifecycle($uuid, fn (Branch $branch) => $archive($branch, $this->actor()), __('manager_staff.branches.archived'));
    }

    public function restore(string $uuid, RestoreBranch $restore): void
    {
        $this->lifecycle($uuid, fn (Branch $branch) => $restore($branch, $this->actor()), __('manager_staff.branches.restored'));
    }

    public function render(TenantLocales $locales): mixed
    {
        $user = $this->actor();
        $canView = $user->hasPermission(Permission::BranchView);
        $canManage = $user->hasPermission(Permission::BranchManage);
        $scoped = Branch::query()->with(['workingHours', 'hourExceptions'])->orderBy('sort_order')->orderBy('id');
        $user->branchScope()->applyTo($scoped, 'id');
        $all = $canView ? $scoped->get() : collect();
        $archived = $all->filter(static fn (Branch $branch): bool => $branch->isArchived());

        return view('livewire.center.branches', [
            'canView' => $canView,
            'canManage' => $canManage,
            // A new branch lies outside any limited scope (SaveBranch refuses it).
            'canCreate' => $canManage && $user->branchScope()->isUnrestricted(),
            'branches' => $all
                ->filter(fn (Branch $branch): bool => $this->view === 'archived' ? $branch->isArchived() : ! $branch->isArchived())
                ->map(static fn (Branch $branch): array => BranchPresenter::card($branch, $canManage))
                ->values()->all(),
            'archivedCount' => $archived->count(),
            'activeCount' => $all->count() - $archived->count(),
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
            'editorDays' => $this->showForm ? BranchPresenter::editorDays($this->hours) : [],
        ])->title(__('ui.manager_nav.items.branches'));
    }

    private function lifecycle(string $uuid, callable $action, string $success): void
    {
        $branch = $this->scopedBranch($uuid);

        if (! $branch instanceof Branch) {
            return;
        }

        try {
            $action($branch);
            $this->flash($success);
        } catch (AuthorizationException|ValidationException $e) {
            $this->flash($e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage(), 'danger');
        }
    }

    /** A branch in the viewer's scope, or nothing — never another branch's row. */
    private function scopedBranch(string $uuid): ?Branch
    {
        $query = Branch::query()->with(['workingHours', 'hourExceptions'])->where('uuid', $uuid);
        $this->actor()->branchScope()->applyTo($query, 'id');
        $branch = $query->first();

        return $branch instanceof Branch ? $branch : null;
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function resetForm(): void
    {
        $this->reset('editing', 'name', 'address', 'timezone', 'phone', 'phoneCountry', 'whatsapp', 'whatsappCountry', 'email',
            'mapUrl', 'latitude', 'longitude', 'sortOrder', 'isActive', 'isPublic', 'hours', 'exceptions', 'pastExceptions');
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
