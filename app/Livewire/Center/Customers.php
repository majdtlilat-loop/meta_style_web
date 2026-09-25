<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Customers\Concerns\EditsCustomer;
use App\Modules\Customers\Application\Actions\ArchiveCustomer;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The staff CRM list: find a customer, add one, open their profile.
 *
 * MASKING IS NOT DONE HERE. Every customer this screen renders has already been
 * through {@see CustomerPresenter}, the same one the API uses. A Blade template
 * that hid a phone number would still have sent it to the browser, and would
 * still leave the API and any future mobile client to reimplement the rule
 * (docs/06-AUTH-ROLES-PERMISSIONS.md §6).
 *
 * THE SEARCH IS NEVER IN THE URL. It can be a phone number or an email, and a
 * query string ends up in browser history, proxy logs and a shared link
 * (docs/08-AUDIT-SECURITY.md: no PII in a URL). The filters, which are not
 * personal, are.
 *
 * Paginated always. A center with fifteen thousand customers must not be able
 * to load them all by opening a screen. The profile — history, benefits,
 * notes — is its own page ({@see Customers\Profile}).
 */
#[Layout('components.layouts.app')]
final class Customers extends Component
{
    use EditsCustomer;
    use WithPagination;

    public string $search = '';

    #[Url]
    public bool $archived = false;

    #[Url]
    public string $registered = '';

    #[Url]
    public string $tag = '';

    #[Url]
    public string $visited = '';

    public string $notice = '';

    public string $noticeTone = 'success';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'archived', 'registered', 'tag', 'visited'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'archived', 'registered', 'tag', 'visited']);
        $this->resetPage();
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    /** The tags drawer changed a tag: redraw the filter and the form's choices. */
    #[On('customer-tags-changed')]
    public function tagsChanged(): void
    {
        if ($this->tag !== '' && ! CustomerTag::query()->active()->where('uuid', $this->tag)->exists()) {
            $this->tag = '';
        }
    }

    public function archive(string $uuid, ArchiveCustomer $archive): void
    {
        $this->act(function () use ($uuid, $archive): void {
            $archive($this->findOrFail($uuid), $this->actor());
            $this->flash(__('manager_customers.notices.archived'));
        });
    }

    public function restore(string $uuid, ArchiveCustomer $archive): void
    {
        $this->act(function () use ($uuid, $archive): void {
            $archive->restore($this->findOrFail($uuid), $this->actor());
            $this->flash(__('manager_customers.notices.restored'));
        });
    }

    public function render(CustomerQuery $query, CustomerPresenter $presenter, TenantLocales $locales, LanguageRegistry $languages): View
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
            'visited' => $this->visited === '' ? null : $this->visited === 'yes',
        ], $user, 25);

        /** @var list<Customer> $items */
        $items = $page->items();
        $lastVisits = $query->lastVisits(array_map(static fn (Customer $c): int => (int) $c->getKey(), $items), $user);
        $locale = app()->getLocale();

        $customers = array_map(function (Customer $customer) use ($presenter, $user, $lastVisits, $locale): array {
            $last = $lastVisits[(int) $customer->getKey()] ?? null;

            $row = $presenter->summary($customer, $user);

            // Readable for a person; a masked value is already display-only.
            if (! $row['contact_masked']) {
                $row['phone'] = PhoneNumber::parse($row['phone'])?->international() ?? $row['phone'];
            }

            return $row + [
                'initials' => self::initials($customer->name),
                'profile_url' => route('center.customers.show', ['uuid' => $customer->uuid]),
                'last_visit' => $last?->locale($locale)->diffForHumans(),
                'last_visit_title' => $last?->locale($locale)->isoFormat('D MMM YYYY'),
            ];
        }, $items);

        $tags = CustomerTag::query()->active()->get();

        return view('livewire.center.customers', [
            'page' => $page,
            'customers' => $customers,
            'hasFilters' => $this->search !== '' || $this->registered !== '' || $this->tag !== '' || $this->visited !== '' || $this->archived,
            'tagOptions' => $tags->map(fn (CustomerTag $t): array => ['uuid' => $t->uuid, 'name' => $t->name->get()])->values()->all(),
            'languageOptions' => self::languageOptions($locales, $languages, $this->preferredLocale),
            'canSeeContact' => $user->hasPermission(Permission::CustomerContactView),
            'canCreate' => $user->hasPermission(Permission::CustomerCreate),
            'canUpdate' => $user->hasPermission(Permission::CustomerUpdate),
            'canArchive' => $user->hasPermission(Permission::CustomerArchive),
            'canManageTags' => $user->hasPermission(Permission::CustomerTagManage),
            'today' => now()->toDateString(),
        ])->title(__('ui.manager_nav.items.customers'));
    }

    /**
     * The content languages offered in the form: the center's enabled ones,
     * plus a customer's stored choice the center has since disabled — so an
     * unrelated edit never silently drops it.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function languageOptions(TenantLocales $locales, LanguageRegistry $languages, string $current): array
    {
        $codes = $locales->enabled();

        if ($current !== '' && ! in_array($current, $codes, true) && $languages->supports($current)) {
            $codes[] = $current;
        }

        return array_map(static fn (string $code): array => [
            'code' => $code,
            'label' => $languages->shortLabel($code).' · '.$languages->nativeName($code),
        ], $codes);
    }

    public static function initials(string $name): string
    {
        $name = trim($name);

        return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
    }

    protected function customerSaved(Customer $customer, bool $created): void
    {
        if ($created) {
            // Straight to the new record: the desk usually books or checks the
            // person in next.
            $this->redirectRoute('center.customers.show', ['uuid' => $customer->uuid], navigate: true);

            return;
        }

        $this->flash(__('manager_customers.notices.saved'));
    }

    protected function formActor(): User
    {
        return $this->actor();
    }

    private function act(callable $work): void
    {
        try {
            $work();
        } catch (AuthorizationException $refused) {
            $this->flash($refused->getMessage(), 'danger');
        }
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function findOrFail(string $uuid): Customer
    {
        /** @var Customer $customer */
        $customer = Customer::query()->where('uuid', $uuid)->firstOrFail();

        return $customer;
    }

    private function actor(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
