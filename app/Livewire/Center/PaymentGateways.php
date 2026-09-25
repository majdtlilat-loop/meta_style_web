<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Entitlements;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\PosFinance\BranchTime;
use App\Livewire\Center\PosFinance\GuardsMoneyActions;
use App\Livewire\Center\PosFinance\Refusals;
use App\Modules\Branches\Domain\Models\Branch;
use App\Modules\Payments\Application\Actions\ManageGatewayAccount;
use App\Modules\Payments\Application\PaymentsPresenter;
use App\Modules\Payments\Application\PaymentsQuery;
use App\Modules\Payments\Domain\Enums\GatewayEnvironment;
use App\Modules\Payments\Domain\Exceptions\PaymentFailed;
use App\Modules\Payments\Domain\Models\GatewayAccount;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A branch's online payment gateways: configure, update credentials, enable,
 * disable.
 *
 * ## Secrets are write-only here
 *
 * Credential inputs start empty every time and are cleared the moment they are
 * submitted — never loaded from the account, never rendered back. Switching the
 * provider, environment or branch clears anything typed, so a secret never
 * rides along in the page state while a person looks around. There is no
 * "show secret"; updating credentials means typing new ones
 * (docs/19-PAYMENTS.md §61).
 *
 * ## `payments`
 *
 * Configuring and enabling need it. Without it, a center that had gateways
 * still sees them (and may switch one off); one that never had any sees the
 * upgrade state.
 */
#[Layout('components.layouts.app')]
final class PaymentGateways extends Component
{
    use GuardsMoneyActions;
    use RequiresFeature;

    #[Url]
    public string $branch = '';

    public string $provider = 'fib';

    public string $environment = 'sandbox';

    public string $displayName = '';

    /** @var array<string, string> field => typed value, cleared after every submit */
    public array $credentials = [];

    /** The account being updated, or '' for a new one. */
    public string $editing = '';

    public bool $showForm = false;

    public string $error = '';

    public string $saved = '';

    public function mount(): void
    {
        if ($this->branch === '') {
            $query = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
            $this->user()->branchScope()->applyTo($query, 'id');
            $this->branch = (string) ($query->value('uuid') ?? '');
        }
    }

    // A live change never carries typed secrets along with it.
    public function updatingProvider(): void
    {
        $this->credentials = [];
    }

    public function updatingEnvironment(): void
    {
        $this->credentials = [];
    }

    public function updatingBranch(): void
    {
        $this->credentials = [];
        $this->reset(['editing', 'showForm']);
    }

    public function newAccount(): void
    {
        $this->reset(['editing', 'displayName', 'credentials', 'error', 'saved']);
        $this->provider = 'fib';
        $this->environment = 'sandbox';
        $this->showForm = true;
    }

    /**
     * Prefills what is not secret — provider, environment, name — to type new
     * credentials over an existing account.
     */
    public function editAccount(string $uuid, PaymentsQuery $query): void
    {
        $this->attempt(function () use ($uuid, $query): void {
            $account = $query->gatewayAccount($uuid, $this->user(), Permission::PaymentGatewayManage);

            $this->editing = $account->uuid;
            $this->provider = $account->provider;
            $this->environment = $account->environment->value;
            $this->displayName = $account->display_name;
            $this->credentials = [];
            $this->showForm = true;
        });
    }

    public function closeForm(): void
    {
        $this->reset(['editing', 'displayName', 'credentials', 'showForm']);
        $this->resetValidation();
    }

    public function configure(ManageGatewayAccount $manage): void
    {
        $configured = $this->attempt(function () use ($manage): void {
            $environment = GatewayEnvironment::tryFrom($this->environment) ?? throw PaymentFailed::policy('Choose an environment.');

            try {
                $manage->configure($this->branch, $this->provider, $this->user(), $this->credentials, $environment, $this->displayName);
            } finally {
                // Whatever happened, the typed secrets do not survive this request.
                $this->credentials = [];
            }

            $this->saved = (string) __('Gateway saved. It stays off until you enable it.');
        });

        // A refusal still clears them: the attempt may have thrown before the
        // Action ran.
        $this->credentials = [];

        if ($configured) {
            $this->reset(['editing', 'displayName', 'showForm']);
        }
    }

    public function enable(string $uuid, ManageGatewayAccount $manage, PaymentsQuery $query): void
    {
        $this->attempt(function () use ($uuid, $manage, $query): void {
            $manage->setEnabled($query->gatewayAccount($uuid, $this->user(), Permission::PaymentGatewayManage), $this->user(), true);
            $this->saved = (string) __('Gateway enabled.');
        });
    }

    public function disable(string $uuid, ManageGatewayAccount $manage, PaymentsQuery $query): void
    {
        $this->attempt(function () use ($uuid, $manage, $query): void {
            $manage->setEnabled($query->gatewayAccount($uuid, $this->user(), Permission::PaymentGatewayManage), $this->user(), false);
            $this->saved = (string) __('Gateway disabled. Payments already started through it can still complete.');
        });
    }

    public function render(PaymentsQuery $query, PaymentsPresenter $presenter, Entitlements $entitlements): View
    {
        $owned = $entitlements->enabled('payments');

        if (! $owned && ! GatewayAccount::query()->exists() && ($locked = $this->lockedView('payments'))) {
            return $locked;
        }

        $user = $this->user();
        $allowed = $user->hasPermission(Permission::PaymentGatewayManage);

        $branchQuery = Branch::query()->active()->orderByDesc('is_main')->orderBy('id');
        $user->branchScope()->applyTo($branchQuery, 'id');
        /** @var list<Branch> $branches */
        $branches = $branchQuery->get()->all();

        $timezone = 'UTC';

        foreach ($branches as $candidate) {
            if ($candidate->uuid === $this->branch) {
                $timezone = BranchTime::zoneOf($candidate);
            }
        }

        $accounts = [];
        $listError = '';

        try {
            if ($allowed && $this->branch !== '') {
                $accounts = array_map(fn (GatewayAccount $account): array => $this->account($presenter->gatewayAccount($account), $timezone), $query->gatewayAccounts($this->branch, $user));
            }
        } catch (AuthorizationException $refused) {
            $listError = Refusals::text($refused->getMessage());
        } catch (NotFoundHttpException) {
            $listError = (string) __('You may not manage payment gateways for that branch.');
        }

        $providers = array_map(fn (array $provider): array => $provider + [
            'fields' => array_map(fn (string $field): array => ['name' => $field, 'label' => $this->fieldLabel($field)], $provider['credential_fields']),
        ], $presenter->providers());

        $selected = null;

        foreach ($providers as $provider) {
            if ($provider['code'] === $this->provider) {
                $selected = $provider;
            }
        }

        return view('livewire.center.payment-gateways', [
            'branches' => array_map(static fn (Branch $option): array => ['uuid' => $option->uuid, 'name' => $option->name->get()], $branches),
            'accounts' => $accounts,
            'providers' => $providers,
            'selectedProvider' => $selected,
            'environments' => array_map(static fn (GatewayEnvironment $environment): array => [
                'value' => $environment->value,
                'label' => (string) __('manager_pos.gateways.environment.'.$environment->value),
            ], GatewayEnvironment::cases()),
            'listError' => $listError,
            'denied' => ! $allowed,
            'owned' => $owned,
            'offer' => $owned ? null : $this->lockedFeature('payments'),
            'canConfigure' => $allowed && $owned,
        ]);
    }

    /**
     * @param  array<string, mixed>  $account  PaymentsPresenter::gatewayAccount()
     * @return array<string, mixed>
     */
    private function account(array $account, string $timezone): array
    {
        return $account + [
            'environment_label' => (string) __('manager_pos.gateways.environment.'.$account['environment']),
            'configured_at_label' => BranchTime::label($account['configured_at'] ?? null, $timezone, BranchTime::DATETIME_FULL),
            'state' => $account['enabled'] ? 'enabled' : 'disabled',
            'state_label' => (string) __($account['enabled'] ? 'manager_pos.gateways.enabled' : 'manager_pos.gateways.disabled'),
        ];
    }

    private function fieldLabel(string $field): string
    {
        $key = 'manager_pos.gateways.fields.'.$field;
        $label = __($key);

        return $label === $key ? Str::headline($field) : (string) $label;
    }
}
