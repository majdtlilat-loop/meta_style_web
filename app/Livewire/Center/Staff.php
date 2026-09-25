<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Models\Role;
use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneCountries;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Contact\PhoneRule;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Staff\StaffOptions;
use App\Modules\Employees\Application\Actions\CreateEmployee;
use App\Modules\Employees\Application\EmployeePresenter;
use App\Modules\Employees\Application\EmployeeQuery;
use App\Modules\Employees\Domain\Data\NewEmployee;
use App\Modules\Employees\Domain\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The team: who works here, where, and whether they can sign in.
 *
 * The component gathers input and shows results; every decision about who may
 * do what lives in the Actions it calls, so the same rules apply to the API and
 * to any later caller (docs/13-ROADMAP.md Phase 3 Part I). The list is
 * branch-scoped in the query ({@see EmployeeQuery}); one person's details and
 * every change to them live in the profile drawer ({@see Staff\Profile}).
 */
#[Layout('components.layouts.app')]
final class Staff extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $branch = '';

    #[Url(except: '')]
    public string $role = '';

    #[Url(except: '')]
    public string $login = '';

    /** The open profile, by employee uuid. */
    #[Url(except: '')]
    public string $open = '';

    public bool $showForm = false;

    /** @var array<string, string> locale => name */
    public array $name = [];

    public bool $withLogin = false;

    public string $email = '';

    /** Required for a login (every center user account has a phone). */
    public string $phone = '';

    public string $phoneCountry = PhoneCountries::DEFAULT;

    /** @var array<int, int|string> checkbox values arrive as strings */
    public array $branchIds = [];

    /** @var array<int, int|string> */
    public array $roleIds = [];

    /**
     * Shown once, for the manager to hand over. Only its hash is stored; it is
     * never flashed, logged or sent anywhere by Meta Style.
     */
    public ?string $activationToken = null;

    public string $activationFor = '';

    public string $notice = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status', 'branch', 'role', 'login'], true)) {
            $this->resetPage();
        }

        // Switching the login off forgets what was typed, so a hidden field can
        // never create an account by accident.
        if ($property === 'withLogin' && ! $this->withLogin) {
            $this->reset('email', 'phone', 'phoneCountry', 'roleIds');
            $this->resetValidation(['email', 'phone', 'roleIds']);
        }
    }

    public function resetFilters(): void
    {
        $this->reset('search', 'status', 'branch', 'role', 'login');
        $this->resetPage();
    }

    public function openForm(): void
    {
        $this->reset('name', 'withLogin', 'email', 'phone', 'phoneCountry', 'branchIds', 'roleIds');
        $this->resetValidation();
        $this->open = '';

        // A manager of one branch starts with it ticked: they can only add
        // people there anyway.
        $options = StaffOptions::branches($this->actor());

        if (count($options) === 1) {
            $this->branchIds = [$options[0]['id']];
        }

        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->resetValidation();
    }

    public function dismissToken(): void
    {
        $this->activationToken = null;
        $this->activationFor = '';
    }

    public function show(string $uuid): void
    {
        $this->showForm = false;
        $this->open = $uuid;
    }

    #[On('staff-profile-closed')]
    public function closeProfile(): void
    {
        $this->open = '';
    }

    #[On('staff-updated')]
    public function refreshList(string $message = ''): void
    {
        $this->notice = $message;
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function create(CreateEmployee $createEmployee): void
    {
        $wantsLogin = $this->withLogin || trim($this->phone) !== '' || trim($this->email) !== '';
        $locales = app(TenantLocales::class);

        $this->validate([
            'name' => ['required', 'array'],
            'name.'.$locales->default() => ['required', 'string', 'min:2', 'max:190'],
            'name.*' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phoneCountry' => ['required', 'string', 'size:2'],
            'phone' => [$wantsLogin ? 'required' : 'nullable', 'string', 'max:32', ...($this->phone !== '' ? [new PhoneRule($this->phoneCountry)] : [])],
            'branchIds' => ['array'],
            'branchIds.*' => ['integer'],
            'roleIds' => ['array'],
            'roleIds.*' => ['integer'],
        ], [], StaffOptions::attributes());

        try {
            $result = $createEmployee(
                new NewEmployee(
                    name: $this->name,
                    branchIds: array_values(array_map('intval', $this->branchIds)),
                    roleIds: $wantsLogin ? array_values(array_map('intval', $this->roleIds)) : [],
                    email: $wantsLogin && trim($this->email) !== '' ? trim($this->email) : null,
                    phone: $wantsLogin ? PhoneNumber::fromParts($this->phoneCountry, $this->phone)?->e164 : null,
                ),
                $this->actor(),
            );
        } catch (ValidationException $e) {
            StaffOptions::remapErrors($this, $e);

            return;
        } catch (AuthorizationException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        $this->activationToken = $result['activation_token'];
        $this->activationFor = $result['employee']->name->get();
        $this->notice = $result['activation_token'] === null
            ? __('manager_staff.staff.created', ['name' => $this->activationFor])
            : '';
        $this->reset('name', 'withLogin', 'email', 'phone', 'phoneCountry', 'branchIds', 'roleIds');
        $this->showForm = false;
    }

    public function render(EmployeeQuery $query, TenantLocales $locales): mixed
    {
        $user = $this->actor();
        $canView = $user->hasPermission(Permission::StaffView);

        $presenter = EmployeePresenter::for($user);

        $employees = $canView
            ? $query->paginate([
                'search' => $this->search,
                'status' => $this->status,
                'branch' => $this->branch,
                'role' => $this->role,
                'login' => $this->login,
            ], $user)->through(fn (Employee $employee): array => $presenter->summary($employee))
            : new LengthAwarePaginator([], 0, 25);

        return view('livewire.center.staff', [
            'canView' => $canView,
            'canCreate' => $user->hasPermission(Permission::StaffCreate),
            'canAssignRoles' => $user->hasPermission(Permission::StaffAccessManage),
            'employees' => $employees,
            'stats' => $canView ? StaffOptions::stats($query, $user) : null,
            'filterBranches' => $canView ? StaffOptions::branches($user, includeArchived: true) : [],
            'filterRoles' => $canView ? Role::query()->orderBy('id')->get()->map(static fn (Role $role): array => ['uuid' => $role->uuid, 'name' => $role->name->get()])->all() : [],
            'formBranches' => $this->showForm ? StaffOptions::branches($user) : [],
            'formRoles' => $this->showForm ? StaffOptions::grantableRoles($user) : [],
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
            'activationUrl' => $this->activationToken !== null ? route('activate', ['token' => $this->activationToken]) : null,
            'activeFilters' => count(array_filter([$this->status, $this->branch, $this->role, $this->login])),
        ])->title(__('ui.manager_nav.items.staff'));
    }

    private function actor(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
