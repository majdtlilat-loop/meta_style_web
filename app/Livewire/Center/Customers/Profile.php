<?php

declare(strict_types=1);

namespace App\Livewire\Center\Customers;

use App\Kernel\Authorization\Permission;
use App\Kernel\Contact\PhoneNumber;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\LanguageRegistry;
use App\Kernel\Localization\TenantLocales;
use App\Livewire\Center\Customers as CustomerList;
use App\Livewire\Center\Customers\Concerns\EditsCustomer;
use App\Livewire\Center\Customers\Concerns\FormatsLocalDates;
use App\Modules\Customers\Application\Actions\ArchiveCustomer;
use App\Modules\Customers\Application\Actions\SetCustomerAccountStatus;
use App\Modules\Customers\Application\CustomerPresenter;
use App\Modules\Customers\Application\CustomerQuery;
use App\Modules\Customers\Domain\Models\Customer;
use App\Modules\Customers\Domain\Models\CustomerTag;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One customer: who they are, and — each through its own module's panel —
 * what they booked, what happened, what they bought, their points, memberships
 * and packages, what they said, and what staff noted.
 *
 * Every tab is gated by ITS OWN permission here, and each panel authorises
 * itself again on the server; a hidden tab is orientation, not protection.
 * Customers never imports Booking, Journey, Sales, the benefit modules or
 * Reviews: the page embeds their panels instead (docs/04, docs/21 §3).
 *
 * Contact details arrive already masked or not by CustomerPresenter. Archiving
 * keeps every piece of history; the login is a separate switch behind
 * `customer.account.manage` (ADR-041).
 */
#[Layout('components.layouts.app')]
final class Profile extends Component
{
    use EditsCustomer;
    use FormatsLocalDates;

    public const TABS = ['overview', 'bookings', 'visits', 'purchases', 'loyalty', 'plans', 'reviews', 'notes'];

    #[Locked]
    public string $uuid = '';

    #[Url(as: 'tab', except: 'overview')]
    public string $tab = 'overview';

    public string $notice = '';

    public string $noticeTone = 'success';

    public function mount(string $uuid): void
    {
        $this->uuid = $uuid;
    }

    public function showTab(string $tab): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'overview';
    }

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    public function archive(ArchiveCustomer $archive, CustomerQuery $query): void
    {
        $this->act(function () use ($archive, $query): void {
            $archive($query->find($this->uuid, $this->actor()), $this->actor());
            $this->flash(__('manager_customers.notices.archived'));
        });
    }

    public function restore(ArchiveCustomer $archive, CustomerQuery $query): void
    {
        $this->act(function () use ($archive, $query): void {
            $archive->restore($query->find($this->uuid, $this->actor()), $this->actor());
            $this->flash(__('manager_customers.notices.restored_login_off'));
        });
    }

    public function setLogin(bool $active, SetCustomerAccountStatus $status, CustomerQuery $query): void
    {
        $this->act(function () use ($active, $status, $query): void {
            $status($query->find($this->uuid, $this->actor()), $active, $this->actor());
            $this->flash($active ? __('manager_customers.notices.login_on') : __('manager_customers.notices.login_off'));
        });
    }

    public function render(CustomerQuery $query, CustomerPresenter $presenter, TenantLocales $locales, LanguageRegistry $languages): View
    {
        $user = $this->actor();

        try {
            $customer = $query->find($this->uuid, $user);
        } catch (AuthorizationException) {
            abort(403);
        }

        $profile = $presenter->detail($customer, $user);

        if (! $profile['contact_masked']) {
            $profile['phone'] = PhoneNumber::parse($profile['phone'])?->international() ?? $profile['phone'];
        }
        $tabs = $this->tabsFor($user);

        if (! in_array($this->tab, $tabs, true)) {
            $this->tab = 'overview';
        }

        $lastVisit = $query->lastVisits([(int) $customer->getKey()], $user)[(int) $customer->getKey()] ?? null;
        $account = $profile['account'];

        return view('livewire.center.customers.profile', [
            'profile' => $profile,
            'initials' => CustomerList::initials($customer->name),
            'tabs' => $tabs,
            'facts' => [
                'since' => $this->localDate($customer->created_at),
                'last_visit' => $lastVisit === null ? null : $this->relative($lastVisit),
                'last_visit_date' => $lastVisit === null ? null : $this->localDate($lastVisit),
                'language' => $customer->preferred_locale === null ? null : $languages->nativeName($profile['preferred_locale']),
                'date_of_birth' => $customer->date_of_birth === null ? null : $this->localDate($customer->date_of_birth, false),
                'source' => __('manager_customers.source.'.$profile['source']),
                'marketing_since' => $customer->marketing_opt_in_at === null ? null : $this->localDate($customer->marketing_opt_in_at),
                'last_login' => $account === null || $account['last_login_at'] === null ? null : $this->relative($account['last_login_at']),
                'account_since' => $account === null ? null : $this->localDate($account['created_at']),
            ],
            'tagOptions' => CustomerTag::query()->active()->get()
                ->map(fn (CustomerTag $t): array => ['uuid' => $t->uuid, 'name' => $t->name->get()])->values()->all(),
            'languageOptions' => CustomerList::languageOptions($locales, $languages, $this->preferredLocale),
            'canSeeContact' => $user->hasPermission(Permission::CustomerContactView),
            'canCreate' => false,
            'canUpdate' => $user->hasPermission(Permission::CustomerUpdate),
            'canArchive' => $user->hasPermission(Permission::CustomerArchive),
            'canManageLogin' => $user->hasPermission(Permission::CustomerAccountManage),
            'listUrl' => route('center.customers'),
            'today' => now()->toDateString(),
        ])->title($customer->name);
    }

    /**
     * The tabs this viewer may open. Each panel checks again on the server.
     *
     * @return list<string>
     */
    private function tabsFor(User $user): array
    {
        $allowed = [
            'overview' => true,
            'bookings' => $user->hasPermission(Permission::AppointmentView),
            'visits' => $user->hasPermission(Permission::JourneyView),
            'purchases' => $user->hasPermission(Permission::SaleView),
            'loyalty' => $user->hasPermission(Permission::LoyaltyView),
            'plans' => $user->hasPermission(Permission::MembershipView) || $user->hasPermission(Permission::PackageView),
            'reviews' => $user->hasPermission(Permission::ReviewView),
            'notes' => $user->hasPermission(Permission::CustomerNoteView),
        ];

        return array_values(array_filter(self::TABS, static fn (string $tab): bool => $allowed[$tab]));
    }

    protected function customerSaved(Customer $customer, bool $created): void
    {
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
        } catch (ValidationException $invalid) {
            $this->flash((string) collect($invalid->errors())->flatten()->first(), 'danger');
        }
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    private function actor(): User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : abort(403);
    }
}
