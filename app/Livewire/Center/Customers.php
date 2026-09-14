<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Notes\NoteVisibility;
use App\Modules\Customers\Application\Actions\ArchiveCustomer;
use App\Modules\Customers\Application\Actions\ManageCustomerNotes;
use App\Modules\Customers\Application\Actions\SaveCustomer;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Data\CustomerInput;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The staff CRM: list, profile, edit, notes.
 *
 * MASKING IS NOT DONE HERE. Every customer this screen renders has already been
 * through {@see CustomerPresenter}, the same one the API uses. A Blade template
 * that hid a phone number would still have sent it to the browser, and would
 * still leave the API and any future mobile client to reimplement the rule
 * (docs/06-AUTH-ROLES-PERMISSIONS.md §6).
 *
 * Paginated always. A center with fifteen thousand customers must not be able
 * to load them all by opening a screen.
 */
#[Layout('components.layouts.app')]
final class Customers extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public bool $archived = false;

    #[Url]
    public string $registered = '';

    #[Url]
    public string $tag = '';

    /** The profile currently open, by uuid. */
    public ?string $viewing = null;

    /** The record being edited, by uuid. Null while adding. */
    public ?string $editing = null;

    public bool $showForm = false;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $preferredLocale = '';

    public string $dateOfBirth = '';

    public bool $allowOperational = true;

    public bool $marketingOptIn = false;

    /** @var list<string> */
    public array $tagUuids = [];

    public string $noteBody = '';

    public string $noteVisibility = 'internal';

    public string $notice = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedArchived(): void
    {
        $this->resetPage();
    }

    public function open(string $uuid): void
    {
        $this->viewing = $uuid;
        $this->showForm = false;
    }

    public function closeProfile(): void
    {
        $this->viewing = null;
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showForm = true;
        $this->viewing = null;
    }

    public function edit(string $uuid): void
    {
        $customer = $this->findOrFail($uuid);

        $this->editing = $uuid;
        $this->name = $customer->name;

        // Only prefilled for someone allowed to see it. Otherwise the edit form
        // would hand back the very value the list masked.
        $maySeeContact = $this->actor()->hasPermission(Permission::CustomerContactView);

        $this->phone = $maySeeContact ? (string) $customer->phone_display : '';
        $this->email = $maySeeContact ? (string) $customer->email : '';

        $this->preferredLocale = (string) $customer->preferred_locale;
        $this->dateOfBirth = $customer->date_of_birth?->toDateString() ?? '';
        $this->allowOperational = $customer->allow_operational_messages;
        $this->marketingOptIn = $customer->marketing_opt_in;
        $this->tagUuids = $customer->tags->pluck('uuid')->all();

        $this->showForm = true;
        $this->viewing = null;
    }

    public function save(SaveCustomer $save): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'dateOfBirth' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $existing = $this->editing === null ? null : $this->findOrFail($this->editing);

        $mayEditContact = $this->actor()->hasPermission(Permission::CustomerContactView);

        try {
            $customer = $save(CustomerInput::fromArray([
                'name' => $this->name,
                // A viewer who cannot see contact details cannot change them
                // either: submitting a blank form would otherwise erase a phone
                // number they were never shown.
                'phone' => $mayEditContact ? $this->phone : ($existing?->phone_display),
                'email' => $mayEditContact ? $this->email : ($existing?->email),
                'preferred_locale' => $this->preferredLocale,
                'date_of_birth' => $this->dateOfBirth === '' ? null : $this->dateOfBirth,
                'allow_operational_messages' => $this->allowOperational,
                'marketing_opt_in' => $this->marketingOptIn,
                'tags' => $this->tagUuids,
            ]), $this->actor(), $existing);
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();

            return;
        } catch (ValidationException $e) {
            $this->addError('phone', $e->getMessage());

            return;
        }

        $this->notice = __('Saved.');
        $this->resetForm();
        $this->showForm = false;
        $this->viewing = $customer->uuid;
    }

    public function archive(string $uuid, ArchiveCustomer $archive): void
    {
        try {
            $archive($this->findOrFail($uuid), $this->actor());

            $this->notice = __('Customer archived.');
            $this->viewing = null;
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();
        }
    }

    public function restore(string $uuid, ArchiveCustomer $archive): void
    {
        try {
            $archive->restore($this->findOrFail($uuid), $this->actor());

            $this->notice = __('Customer restored.');
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();
        }
    }

    public function addNote(ManageCustomerNotes $notes): void
    {
        if ($this->viewing === null) {
            return;
        }

        try {
            $notes->add(
                $this->findOrFail($this->viewing),
                $this->noteBody,
                $this->actor(),
                NoteVisibility::from($this->noteVisibility),
            );
        } catch (AuthorizationException $e) {
            $this->notice = $e->getMessage();

            return;
        } catch (ValidationException $e) {
            $this->addError('noteBody', $e->getMessage());

            return;
        }

        $this->noteBody = '';
        $this->notice = __('Note added.');
    }

    public function render(CustomerQuery $query, CustomerPresenter $presenter, TenantLocales $locales): mixed
    {
        $user = $this->actor();

        if (! $user->hasPermission(Permission::CustomerView)) {
            abort(403);
        }

        $page = $query->paginate([
            'search' => $this->search,
            'archived' => $this->archived,
            'registered' => $this->registered === '' ? null : $this->registered === 'yes',
            'tag' => $this->tag === '' ? null : $this->tag,
        ], $user, 25);

        $profile = null;

        if ($this->viewing !== null) {
            $customer = Customer::query()->with(['tags', 'account', 'internalNotes'])
                ->where('uuid', $this->viewing)->first();

            $profile = $customer === null ? null : $presenter->detail($customer, $user);
        }

        return view('livewire.center.customers', [
            'page' => $page,
            'customers' => array_map(
                fn (Customer $c): array => $presenter->summary($c, $user),
                $page->items(),
            ),
            'profile' => $profile,
            'tags' => CustomerTag::query()->active()->get(),
            'locales' => $locales->enabled(),
            'canSeeContact' => $user->hasPermission(Permission::CustomerContactView),
            'canCreate' => $user->hasPermission(Permission::CustomerCreate),
            'canUpdate' => $user->hasPermission(Permission::CustomerUpdate),
            'canArchive' => $user->hasPermission(Permission::CustomerArchive),
            'canViewNotes' => $user->hasPermission(Permission::CustomerNoteView),
            'canManageNotes' => $user->hasPermission(Permission::CustomerNoteManage),
        ]);
    }

    private function findOrFail(string $uuid): Customer
    {
        return Customer::query()->with('tags')->where('uuid', $uuid)->firstOrFail();
    }

    private function resetForm(): void
    {
        $this->editing = null;
        $this->name = '';
        $this->phone = '';
        $this->email = '';
        $this->preferredLocale = '';
        $this->dateOfBirth = '';
        $this->allowOperational = true;
        $this->marketingOptIn = false;
        $this->tagUuids = [];
    }

    private function actor(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
