<?php

declare(strict_types=1);

namespace App\Livewire\Center;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Kernel\Localization\TenantLocales;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Livewire\Center\Customers\Concerns\FormatsLocalDates;
use App\Modules\Loyalty\Application\Actions\ConfigureLoyalty;
use App\Modules\Loyalty\Application\Actions\ManageLoyaltyTier;
use App\Modules\Loyalty\Application\LoyaltyAccess;
use App\Modules\Loyalty\Application\LoyaltyPresenter;
use App\Modules\Loyalty\Application\LoyaltyQuery;
use App\Modules\Loyalty\Domain\Exceptions\LoyaltyFailed;
use App\Modules\Loyalty\Domain\Models\LoyaltyProgram;
use App\Modules\Loyalty\Domain\Models\LoyaltyRuleVersion;
use App\Modules\Loyalty\Domain\Models\LoyaltyTier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The center's loyalty program: what is switched on, the rules, their history,
 * the tiers, and who holds points.
 *
 * Amounts are typed in major units and parsed by `Money::fromMajorString`; the
 * rules themselves are `ConfigureLoyalty`'s, and every save becomes a rule
 * VERSION that earning reads by event time (ADR-064). A change applies to what
 * happens next — points already earned keep their own expiry.
 *
 * After a downgrade the page stays READABLE when there is history (program,
 * tiers, points) and says so; changes are refused by the Actions whatever this
 * screen shows (docs/21 §22). With nothing to read, it is the upgrade page.
 */
#[Layout('components.layouts.app')]
final class Loyalty extends Component
{
    use FormatsLocalDates;
    use RequiresFeature;

    public string $spendPoints = '0';

    public string $spendUnit = '';

    public string $minSpend = '';

    public string $visitPoints = '0';

    public string $pointValue = '';

    public string $minRedeem = '0';

    /** Empty: points never expire. */
    public string $expiryDays = '';

    /** @var array<string, string> */
    public array $tierName = [];

    public string $tierThreshold = '';

    /** @var array<string, string> */
    public array $tierNote = [];

    /** The tier being edited, by uuid; null while adding. */
    public ?string $editingTier = null;

    public bool $showArchivedTiers = false;

    public string $error = '';

    public string $saved = '';

    public bool $showTierForm = false;

    public function mount(LoyaltyQuery $query): void
    {
        try {
            $program = $query->program($this->user());
        } catch (AuthorizationException) {
            abort(403);
        }

        $this->fillRules($program);
    }

    public function openTierForm(): void
    {
        $this->reset(['error', 'saved', 'tierName', 'tierThreshold', 'tierNote', 'editingTier']);
        $this->resetValidation();
        $this->showTierForm = true;
    }

    public function editTier(string $uuid, LoyaltyQuery $query, LoyaltyPresenter $presenter): void
    {
        $this->reset(['error', 'saved']);
        $this->resetValidation();

        try {
            $tier = $presenter->tier($query->tier($uuid, $this->user()));
        } catch (AuthorizationException $refused) {
            $this->error = $refused->getMessage();

            return;
        } catch (NotFoundHttpException) {
            $this->error = __('manager_benefits.errors.not_found');

            return;
        }

        /** @var array<string, string> $names */
        $names = $tier['names'];
        $this->editingTier = $uuid;
        $this->tierName = $names;
        $this->tierNote = $this->noteTexts($uuid, $query);
        $this->tierThreshold = (string) $tier['threshold_points'];
        $this->showTierForm = true;
    }

    public function closeTierForm(): void
    {
        $this->showTierForm = false;
        $this->editingTier = null;
        $this->resetValidation();
    }

    public function save(ConfigureLoyalty $configure): void
    {
        $this->guard(function () use ($configure): void {
            $configure($this->user(), [
                'spend_points' => (int) $this->spendPoints,
                'spend_unit_minor' => $this->minor($this->spendUnit),
                'min_spend_minor' => $this->minor($this->minSpend),
                'visit_points' => (int) $this->visitPoints,
                'point_value_minor' => $this->minor($this->pointValue),
                'min_redeem_points' => (int) $this->minRedeem,
                'expiry_days' => trim($this->expiryDays) === '' ? null : (int) $this->expiryDays,
            ]);

            $this->saved = __('manager_benefits.loyalty.rules_saved');
        });
    }

    /** Kept for the create path: the form posts here when adding. */
    public function addTier(ManageLoyaltyTier $manage, LoyaltyQuery $query): void
    {
        $this->saveTier($manage, $query);
    }

    public function saveTier(ManageLoyaltyTier $manage, LoyaltyQuery $query): void
    {
        $this->guard(function () use ($manage, $query): void {
            $existing = $this->editingTier === null ? null : $query->tier($this->editingTier, $this->user());

            $manage->save($this->user(), $this->tierName, (int) $this->tierThreshold, $this->tierNote, $existing === null ? 0 : $existing->sort_order, $existing);

            $this->saved = $existing === null ? __('manager_benefits.loyalty.tier_added') : __('manager_benefits.loyalty.tier_saved');
            $this->reset(['tierName', 'tierThreshold', 'tierNote', 'editingTier']);
        });

        if ($this->error === '') {
            $this->showTierForm = false;
        }
    }

    public function archiveTier(string $uuid, ManageLoyaltyTier $manage, LoyaltyQuery $query): void
    {
        $this->guard(function () use ($uuid, $manage, $query): void {
            $manage->archive($this->user(), $query->tier($uuid, $this->user()));
            $this->saved = __('manager_benefits.loyalty.tier_archived');
        });
    }

    public function restoreTier(string $uuid, ManageLoyaltyTier $manage, LoyaltyQuery $query): void
    {
        $this->guard(function () use ($uuid, $manage, $query): void {
            $manage->restore($this->user(), $query->tier($uuid, $this->user()));
            $this->saved = __('manager_benefits.loyalty.tier_restored');
        });
    }

    public function render(LoyaltyQuery $query, LoyaltyPresenter $presenter, LoyaltyAccess $access, TenantLocales $locales): View
    {
        $user = $this->user();
        $offer = $this->lockedFeature('loyalty');

        if ($offer !== null && ! $query->hasHistory()) {
            return view('livewire.center.feature-locked', ['offer' => $offer])->title(__('ui.manager_nav.items.loyalty'));
        }

        $tiers = [];
        $program = null;
        $versions = [];
        $totals = ['members' => 0, 'outstanding_points' => 0, 'lifetime_points' => 0];

        try {
            $tiers = array_map(fn (LoyaltyTier $tier): array => $presenter->tier($tier), $query->tiers($user, $this->showArchivedTiers));
            $program = $presenter->program($query->program($user));
            $versions = array_map(fn (LoyaltyRuleVersion $version): array => $presenter->ruleVersion($version) + [
                'when' => $this->localDateTime($version->effective_from),
            ], $query->ruleVersions($user));
            $totals = $query->totals($user);
        } catch (AuthorizationException $refused) {
            $this->error = $refused->getMessage();
        }

        return view('livewire.center.loyalty', [
            'offer' => $offer,
            'program' => $program,
            'programUpdated' => $program === null || $program['updated_at'] === null ? null : $this->localDate($program['updated_at']),
            'versions' => $versions,
            'tiers' => $tiers,
            'totals' => $totals,
            'currency' => Currency::default()->value,
            'canManage' => $offer === null && $access->enabled() && $user->hasPermission(Permission::LoyaltyManage),
            'locales' => $locales->enabled(),
            'primaryLocale' => $locales->default(),
        ])->title(__('ui.manager_nav.items.loyalty'));
    }

    private function fillRules(?LoyaltyProgram $program): void
    {
        if (! $program instanceof LoyaltyProgram) {
            return;
        }

        $this->spendPoints = (string) $program->spend_points;
        $this->spendUnit = $this->major($program->spend_unit_minor);
        $this->minSpend = $this->major($program->min_spend_minor);
        $this->visitPoints = (string) $program->visit_points;
        $this->pointValue = $this->major($program->point_value_minor);
        $this->minRedeem = (string) $program->min_redeem_points;
        $this->expiryDays = $program->expiry_days === null ? '' : (string) $program->expiry_days;
    }

    /**
     * The tier's note in every language it has, for the edit form.
     *
     * @return array<string, string>
     */
    private function noteTexts(string $uuid, LoyaltyQuery $query): array
    {
        $note = $query->tier($uuid, $this->user())->benefit_note;

        return $note === null ? [] : $note->all();
    }

    private function major(int $minor): string
    {
        return $minor === 0 ? '' : Money::fromMinor($minor, Currency::default())->toMajorString();
    }

    /**
     * @throws LoyaltyFailed
     */
    private function minor(string $typed): int
    {
        if (trim($typed) === '') {
            return 0;
        }

        try {
            return Money::fromMajorString($typed, Currency::default())->minor;
        } catch (InvalidArgumentException) {
            throw LoyaltyFailed::policy(__('manager_benefits.errors.amount_format'));
        }
    }

    private function guard(callable $work): void
    {
        $this->reset(['error', 'saved']);

        try {
            $work();
        } catch (LoyaltyFailed|AuthorizationException|EntitlementRequired $failure) {
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
