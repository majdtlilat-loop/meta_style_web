<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\Benefits\Concerns\ChoosesServices;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Modules\Memberships\Application\Actions\ManageMembershipPlan;
use App\Modules\Memberships\Application\MembershipsAccess;
use App\Modules\Memberships\Application\MembershipsPresenter;
use App\Modules\Memberships\Application\MembershipsQuery;
use App\Modules\Memberships\Domain\Exceptions\MembershipsFailed;
use App\Modules\Memberships\Domain\Models\MembershipPlan;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use App\Modules\Sales\Domain\Pricing\SalePricing;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Membership plans: what a center sells as a membership, and what it gives.
 *
 * A plan is sold at the till like any other line; this page defines, edits,
 * archives and restores it, and shows who holds one. Editing a plan changes
 * what is sold next — every membership already sold is a snapshot
 * (docs/21-LOYALTY-MEMBERSHIPS-PACKAGES.md §§10, 12, 27).
 *
 * After a downgrade the page stays readable while there is history, and the
 * Actions refuse changes whatever it shows (§22).
 */
#[Layout('components.layouts.app')]
final class MembershipPlans extends Component
{
    use ChoosesServices;
    use RequiresFeature;

    /** @var array<string, string> */
    public array $name = [];

    public string $price = '';

    public string $durationDays = '30';

    public string $sortOrder = '0';

    /** @var list<array{service: string, type: string, value: string, uses: string}> */
    public array $benefits = [['service' => '', 'type' => 'percent', 'value' => '', 'uses' => '']];

    /** The plan being edited, by uuid; null while adding. */
    public ?string $editing = null;

    #[Url(except: 'active')]
    public string $view = 'active';

    public string $error = '';

    public string $saved = '';

    public bool $showForm = false;

    public function openForm(): void
    {
        $this->reset(['error', 'saved', 'name', 'price', 'durationDays', 'sortOrder', 'benefits', 'editing']);
        $this->resetValidation();
        $this->showForm = true;
    }

    public function edit(string $uuid, MembershipsQuery $query, MembershipsPresenter $presenter): void
    {
        $this->reset(['error', 'saved']);
        $this->resetValidation();

        try {
            $plan = $presenter->plan($query->plan($uuid, $this->user()));
        } catch (AuthorizationException $refused) {
            $this->error = $refused->getMessage();

            return;
        } catch (NotFoundHttpException) {
            $this->error = __('manager_benefits.errors.not_found');

            return;
        }

        /** @var array<string, string> $names */
        $names = $plan['names'];
        /** @var list<array<string, mixed>> $benefits */
        $benefits = $plan['benefits'];

        $this->editing = $uuid;
        $this->name = $names;
        $this->price = $this->major((int) $plan['price']['amount']);
        $this->durationDays = (string) $plan['duration_days'];
        $this->sortOrder = (string) $plan['sort_order'];
        $this->benefits = array_map(fn (array $benefit): array => [
            'service' => (string) ($benefit['service'] ?? ''),
            'type' => (string) $benefit['discount_type'],
            'value' => $benefit['percent'] !== null
                ? (string) $benefit['percent']
                : $this->major((int) ($benefit['amount']['amount'] ?? 0)),
            'uses' => $benefit['uses_per_term'] === null ? '' : (string) $benefit['uses_per_term'],
        ], $benefits) ?: [['service' => '', 'type' => 'percent', 'value' => '', 'uses' => '']];
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
        $this->editing = null;
        $this->resetValidation();
    }

    public function setView(string $view): void
    {
        $this->view = $view === 'archived' ? 'archived' : 'active';
    }

    public function addBenefitRow(): void
    {
        if (count($this->benefits) < ManageMembershipPlan::MAX_BENEFITS) {
            $this->benefits[] = ['service' => '', 'type' => 'percent', 'value' => '', 'uses' => ''];
        }
    }

    public function removeBenefitRow(int $index): void
    {
        unset($this->benefits[$index]);
        $this->benefits = array_values($this->benefits);
    }

    public function save(ManageMembershipPlan $manage, MembershipsQuery $query): void
    {
        $this->guard(function () use ($manage, $query): void {
            $existing = $this->editing === null ? null : $query->plan($this->editing, $this->user());

            $manage->save($this->user(), $this->name, $this->minor($this->price), (int) $this->durationDays, $this->parsedBenefits(), max(0, (int) $this->sortOrder), $existing);

            $this->reset(['name', 'price', 'durationDays', 'sortOrder', 'benefits', 'editing']);
            $this->saved = $existing === null ? __('manager_benefits.plans.saved') : __('manager_benefits.plans.updated');
        });

        if ($this->error === '') {
            $this->showForm = false;
        }
    }

    public function archive(string $uuid, ManageMembershipPlan $manage, MembershipsQuery $query): void
    {
        $this->guard(function () use ($uuid, $manage, $query): void {
            $manage->archive($this->user(), $query->plan($uuid, $this->user()));
            $this->saved = __('manager_benefits.plans.archived');
        });
    }

    public function restore(string $uuid, ManageMembershipPlan $manage, MembershipsQuery $query): void
    {
        $this->guard(function () use ($uuid, $manage, $query): void {
            $manage->restore($this->user(), $query->plan($uuid, $this->user()));
            $this->saved = __('manager_benefits.plans.restored');
        });
    }

    public function render(MembershipsQuery $query, MembershipsPresenter $presenter, MembershipsAccess $access, TenantLocales $locales): View
    {
        $user = $this->user();

        if (! $user->hasPermission(Permission::MembershipView)) {
            abort(403);
        }

        $offer = $this->lockedFeature('memberships');

        if ($offer !== null && ! $query->hasHistory()) {
            return view('livewire.center.feature-locked', ['offer' => $offer])->title(__('ui.manager_nav.items.memberships'));
        }

        $all = [];
        $counts = [];

        try {
            $all = array_map(fn (MembershipPlan $plan): array => $presenter->plan($plan), $query->plans($user, true));
            $counts = $query->liveCountsByPlan($user);
        } catch (AuthorizationException $failure) {
            $this->error = $failure->getMessage();
        }

        $archived = array_values(array_filter($all, static fn (array $plan): bool => $plan['archived'] === true));
        $active = array_values(array_filter($all, static fn (array $plan): bool => $plan['archived'] !== true));
        $canManage = $offer === null && $access->enabled() && $user->hasPermission(Permission::MembershipManage);

        return view('livewire.center.membership-plans', [
            'offer' => $offer,
            'plans' => array_map(fn (array $plan): array => $plan + ['members' => $counts[$plan['uuid']] ?? 0], $this->view === 'archived' ? $archived : $active),
            'activeCount' => count($active),
            'archivedCount' => count($archived),
            'services' => $canManage && $this->showForm ? $this->serviceChoices() : [],
            'currency' => Currency::default()->value,
            'maxBenefits' => ManageMembershipPlan::MAX_BENEFITS,
            'canManage' => $canManage,
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
        ])->title(__('ui.manager_nav.items.memberships'));
    }

    /**
     * What the person typed, parsed — never computed: a percentage becomes
     * basis points, an amount becomes minor units.
     *
     * @return list<array{service?: string|null, discount_type: string, basis_points?: int|null, amount_minor?: int|null, uses_per_term?: int|null}>
     *
     * @throws MembershipsFailed
     */
    private function parsedBenefits(): array
    {
        $parsed = [];

        foreach ($this->benefits as $row) {
            try {
                $percent = $row['type'] === 'percent';

                $parsed[] = [
                    'service' => $row['service'] === '' ? null : $row['service'],
                    'discount_type' => $row['type'],
                    'basis_points' => $percent ? SalePricing::basisPoints($row['value']) : null,
                    'amount_minor' => $percent ? null : $this->minor($row['value']),
                    'uses_per_term' => trim($row['uses']) === '' ? null : (int) $row['uses'],
                ];
            } catch (SaleFailed $failure) {
                throw MembershipsFailed::policy($failure->getMessage());
            }
        }

        return $parsed;
    }

    private function major(int $minor): string
    {
        return Money::fromMinor($minor, Currency::default())->toMajorString();
    }

    /**
     * @throws MembershipsFailed
     */
    private function minor(string $typed): int
    {
        try {
            return Money::fromMajorString(trim($typed) === '' ? '0' : $typed, Currency::default())->minor;
        } catch (InvalidArgumentException) {
            throw MembershipsFailed::policy(__('manager_benefits.errors.amount_format'));
        }
    }

    private function guard(callable $work): void
    {
        $this->reset(['error', 'saved']);

        try {
            $work();
        } catch (MembershipsFailed|AuthorizationException|EntitlementRequired $failure) {
            $this->error = $failure->getMessage();
        } catch (NotFoundHttpException) {
            $this->error = __('manager_benefits.errors.not_found');
        }
    }

    private function user(): User
    {
        $user = auth('web')->user();

        return $user instanceof User ? $user : abort(403);
    }
}
